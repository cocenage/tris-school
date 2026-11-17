<?php

namespace App\Services\Telegram;

use App\Models\ShiftDuty;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramShiftOpening
{
    public function __construct(private readonly TelegramRichMessageBuilder $richMessages) {}

    public function payload(TelegramScheduledMessageDelivery $delivery, ?string $district = null, int $page = 0): array
    {
        $context = $delivery->sequence_context ?? [];
        $draft = $context['opening_draft'] ?? [];
        if ($context['opening_quick'] ?? false) {
            return $this->quickPayload($delivery, $draft);
        }
        $confirmed = isset($context['opening_confirmed_by']);
        $names = User::query()->whereKey(array_filter($draft))->pluck('name', 'id');
        $title = 'ОКС · Открытие смены';
        $date = $this->day($delivery)->format('d.m.Y').' · Europe/Rome';
        $instruction = $confirmed
            ? 'Назначения сохранены.'
            : 'Выберите район и дежурного или отметьте «Нет уборок». Затем сохраните назначения.';
        $summary = [];
        $keyboard = [];
        $prefix = 'oks:'.$delivery->id.':';

        foreach (ScheduledControlTypes::DISTRICTS as $key => $label) {
            $choice = $draft[$key] ?? null;
            $status = match (true) {
                $choice === null => 'Не выбрано',
                $choice === 0 => 'Нет уборок',
                default => $names->get($choice) ?? 'Назначенный сотрудник недоступен',
            };
            $summary[] = $label.' — '.$status;
            if (! $confirmed && $district === null) {
                $keyboard[] = [['text' => ($choice === null ? '▫️ ' : '✓ ').$label, 'callback_data' => $prefix.'pick:'.$key.':0']];
            }
        }

        if (! $confirmed && $district !== null) {
            $instruction .= ' Район — '.ScheduledControlTypes::DISTRICTS[$district].'.';
            $users = $this->eligibleUsers()->orderBy('name')->orderBy('id')->offset($page * 8)->limit(9)->get(['id', 'name']);
            foreach ($users->take(8) as $user) {
                $keyboard[] = [['text' => Str::limit($user->name ?: 'Сотрудник #'.$user->id, 55), 'callback_data' => $prefix.'set:'.$district.':'.$user->id]];
            }
            $navigation = [];
            if ($page > 0) {
                $navigation[] = ['text' => '‹', 'callback_data' => $prefix.'pick:'.$district.':'.($page - 1)];
            }
            if ($users->count() > 8) {
                $navigation[] = ['text' => '›', 'callback_data' => $prefix.'pick:'.$district.':'.($page + 1)];
            }
            if ($navigation !== []) {
                $keyboard[] = $navigation;
            }
            $keyboard[] = [['text' => 'Нет уборок', 'callback_data' => $prefix.'set:'.$district.':0']];
            $keyboard[] = [['text' => 'К списку районов', 'callback_data' => $prefix.'nav:0']];
        } elseif (! $confirmed) {
            $keyboard[] = [['text' => 'Сохранить назначения', 'callback_data' => $prefix.'save']];
        }

        return [
            'rich_message' => $this->richMessages->markdownDocument(implode("\n", [
                '# '.$title, $this->richMessages->escapeRichText($date), '',
                $this->richMessages->escapeRichText($instruction), '',
                ...array_map(fn (string $row): string => '- '.$this->richMessages->escapeRichText($row), $summary),
            ])),
            'fallback_html' => implode("\n", ['<b>'.$title.'</b>', e($date), '', e($instruction), '', ...array_map(fn (string $row): string => '• '.e($row), $summary)]),
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ];
    }

    public function answer(array $callback, TelegramBotService $telegram): string
    {
        $data = $callback['data'] ?? null;
        if (is_string($data) && preg_match('/^oks:(\d+):quick:([a-z]+):(yes|no)$/D', $data, $quick)) {
            return $this->quickAnswer($callback, $quick, $telegram);
        }
        $message = $callback['message'] ?? [];
        if (! is_string($data) || ! preg_match('/^oks:(\d+):(nav:0|save|pick:([a-z]+):(\d{1,3})|set:([a-z]+):(\d+))$/D', $data, $parts)
            || ! is_numeric(data_get($callback, 'from.id')) || data_get($callback, 'from.is_bot', false)
            || ! is_numeric(data_get($message, 'chat.id')) || ! is_numeric(data_get($message, 'message_id'))
            || ! is_numeric(data_get($message, 'message_thread_id'))) {
            return 'Кнопка недоступна.';
        }

        $admin = $this->eligibleUsers()->where('role', 'admin')->where('telegram_id', (string) data_get($callback, 'from.id'))->first();
        if ($admin === null) {
            return 'Назначения сохраняет администратор.';
        }

        $delivery = TelegramScheduledMessageDelivery::query()->whereKey((int) $parts[1])
            ->where('control_type', ScheduledControlTypes::SHIFT_OPENING)->where('bot_type', 'work')->where('status', 'sent')
            ->where('chat_id', (string) data_get($message, 'chat.id'))->where('message_thread_id', (string) $message['message_thread_id'])
            ->where('telegram_message_id', (int) $message['message_id'])->first();

        if ($delivery === null || ! $this->day($delivery)->isToday()) {
            return 'Открытие этой смены недоступно.';
        }

        $district = $parts[5] ?? $parts[3] ?? '';
        if ($district !== '' && ! array_key_exists($district, ScheduledControlTypes::DISTRICTS)) {
            return 'Неизвестный район.';
        }

        return Cache::lock('oks-opening:'.$delivery->id, 30)->block(3, function () use ($delivery, $parts, $district, $admin, $telegram): string {
            $delivery->refresh();
            $context = $delivery->sequence_context ?? [];
            $action = $parts[2];
            $draft = $context['opening_draft'] ?? [];
            if (! isset($context['opening_confirmed_by'])) {
                if ($action === 'save') {
                    if (array_diff_key(ScheduledControlTypes::DISTRICTS, $draft) !== []) {
                        return 'Выберите дежурного или «Нет уборок» для каждого района.';
                    }
                    $actives = array_filter($draft);
                    if ($this->eligibleUsers()->whereKey(array_unique($actives))->count() !== count(array_unique($actives))) {
                        return 'Один из назначенных сотрудников недоступен. Проверьте выбор.';
                    }
                    DB::transaction(function () use ($delivery, $admin, $context, $draft, $actives): void {
                        $day = $this->day($delivery)->toDateString();
                        ShiftDuty::query()->where('date', $day)->whereIn('district_key', array_keys(array_diff_key($draft, $actives)))->delete();
                        foreach ($actives as $key => $userId) {
                            ShiftDuty::query()->updateOrCreate(['date' => $day, 'district_key' => $key], ['user_id' => $userId]);
                        }
                        $delivery->update(['sequence_context' => [
                            ...$context, 'opening_confirmed_by' => $admin->id, 'opening_active_districts' => array_keys($actives),
                        ]]);
                    });
                } elseif (isset($parts[6])) {
                    $id = (int) $parts[6];
                    if ($id !== 0 && ! $this->eligibleUsers()->whereKey($id)->exists()) {
                        return 'Сотрудник недоступен. Откройте список ещё раз.';
                    }
                    $draft[$district] = $id;
                    $delivery->update(['sequence_context' => [...$context, 'opening_draft' => $draft]]);
                }
            }

            $payload = str_starts_with($action, 'pick:')
                ? $this->payload($delivery, $district, (int) $parts[4])
                : $this->payload($delivery);
            try {
                $updated = $telegram->editMessage((string) $delivery->chat_id, $delivery->telegram_message_id,
                    $payload['rich_message'], $payload['fallback_html'], $payload['reply_markup'], forceLegacy: true);
            } catch (Throwable) {
                $updated = false;
            }

            if ($updated) {
                return 'ОКС обновлён.';
            }

            return $action === 'save' || isset($parts[6])
                ? 'Данные сохранены. Карточка пока не обновилась.'
                : 'Не удалось обновить карточку. Нажмите ещё раз.';
        });
    }

    public function assignmentsFor(TelegramScheduledMessageDelivery $delivery): ?array
    {
        $date = $this->day($delivery);
        $opening = TelegramScheduledMessageDelivery::query()
            ->where('control_type', ScheduledControlTypes::SHIFT_OPENING)
            ->where('status', 'sent')->where('chat_id', $delivery->chat_id)->where('message_thread_id', $delivery->message_thread_id)
            ->where('scheduled_for', '>=', $date->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $date->addDay()->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $delivery->getRawOriginal('scheduled_for'))
            ->orderBy('scheduled_for')->orderBy('id')->first();
        $context = $opening?->sequence_context ?? [];

        return ($context['opening_quick'] ?? false) || isset($context['opening_confirmed_by'])
            ? array_filter($context['opening_draft'] ?? []) : null;
    }

    private function quickPayload(TelegramScheduledMessageDelivery $delivery, array $choices): array
    {
        $names = User::query()->whereKey(array_values(array_filter($choices)))->pluck('name', 'id');
        $lines = ['<b>ОКС · Открытие смены</b>', $this->day($delivery)->format('d.m.Y').' · Europe/Rome', '', 'Есть уборки в вашем районе?'];
        if ($delivery->isOksTest()) {
            array_unshift($lines, '<b>ТЕСТ ОКС · Открытие</b>', '');
        }
        $keyboard = [];
        foreach (ScheduledControlTypes::DISTRICTS as $key => $label) {
            $choice = $choices[$key] ?? null;
            $status = match ($choice) {
                null => 'Ждём отметку',
                0 => 'Нет уборок',
                default => 'Есть · '.($names->get($choice) ?: 'Дежурный'),
            };
            $lines[] = e($label.' — '.$status);
            if ($choice === null) {
                $prefix = 'oks:'.$delivery->id.':quick:'.$key.':';
                $keyboard[] = [
                    ['text' => $label.' · Есть', 'callback_data' => $prefix.'yes'],
                    ['text' => $label.' · Нет', 'callback_data' => $prefix.'no'],
                ];
            }
        }

        return ['rich_message' => [], 'fallback_html' => implode("\n", $lines), 'reply_markup' => ['inline_keyboard' => $keyboard]];
    }

    private function quickAnswer(array $callback, array $parts, TelegramBotService $telegram): string
    {
        [, $id, $district, $action] = $parts;
        if (! isset(ScheduledControlTypes::DISTRICTS[$district]) || data_get($callback, 'from.is_bot', false)) {
            return 'Кнопка недоступна.';
        }
        $actor = $this->eligibleUsers()->whereIn('role', ['admin', 'supervisor'])
            ->where('telegram_id', (string) data_get($callback, 'from.id'))->first();
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->whereKey($id)
            ->where('control_type', ScheduledControlTypes::SHIFT_OPENING)->where('bot_type', 'work')
            ->whereIn('status', ['sent', TelegramScheduledMessageDelivery::OKS_TEST])
            ->where('chat_id', (string) data_get($callback, 'message.chat.id'))
            ->where('message_thread_id', (string) data_get($callback, 'message.message_thread_id'))
            ->where('telegram_message_id', (int) data_get($callback, 'message.message_id'))->first();
        if ($actor === null || $delivery === null || ! $this->day($delivery)->isToday()
            || ! ($delivery->sequence_context['opening_quick'] ?? false)) {
            return 'Открытие этой смены недоступно.';
        }

        return Cache::lock('oks-opening:'.$delivery->id, 30)->block(3, function () use ($delivery, $district, $actor, $action, $telegram): string {
            $saved = DB::transaction(function () use ($delivery, $district, $actor, $action): bool {
                $current = TelegramScheduledMessageDelivery::query()->withOksTests()->lockForUpdate()->findOrFail($delivery->id);
                $context = $current->sequence_context;
                $choices = $context['opening_draft'] ?? [];
                if (array_key_exists($district, $choices)) {
                    return false;
                }
                if (! $current->isOksTest()) {
                    $date = $this->day($current)->toDateString();
                    if ($action === 'yes') {
                        ShiftDuty::query()->updateOrCreate(['date' => $date, 'district_key' => $district], ['user_id' => $actor->id]);
                    } else {
                        ShiftDuty::query()->where('date', $date)->where('district_key', $district)->delete();
                    }
                }
                $choices[$district] = $action === 'yes' ? $actor->id : 0;
                $current->update(['sequence_context' => [...$context, 'opening_draft' => $choices]]);

                return true;
            });
            $payload = $this->payload($delivery->refresh());
            try {
                $edited = $telegram->editMessage((string) $delivery->chat_id, $delivery->telegram_message_id, [],
                    $payload['fallback_html'], $payload['reply_markup'], forceLegacy: true);
            } catch (Throwable) {
                $edited = false;
            }

            return $edited ? ($saved ? 'Отметка сохранена.' : 'Район уже отмечен.') : 'Отметка сохранена. Карточка пока не обновилась.';
        });
    }

    private function eligibleUsers(): Builder
    {
        return User::query()->activeStaff()->whereIn('role', ['admin', 'supervisor', 'cleaner'])->whereNotNull('telegram_id')->where('telegram_id', '!=', '');
    }

    private function day(TelegramScheduledMessageDelivery $delivery): CarbonImmutable
    {
        return CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome')->startOfDay();
    }
}
