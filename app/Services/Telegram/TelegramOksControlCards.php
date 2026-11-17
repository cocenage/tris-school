<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use App\Models\TelegramScheduledMessageResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TelegramOksControlCards
{
    private const RESPONSE_PREFIX = 'oks_card:';

    public function payload(TelegramScheduledMessageDelivery $delivery): array
    {
        $questions = OksControlSchedule::questions($delivery->control_type);
        $districts = array_intersect_key(ScheduledControlTypes::DISTRICTS, $delivery->sequence_context['oks_assignments'] ?? []);
        $responses = $this->responses($delivery)->keyBy(fn ($response): string => self::questionKey($response).'|'.$response->district_key);
        if (TelegramOksRichCard::enabled($delivery)) {
            $rows = [];
            foreach ($districts as $district => $name) {
                $statuses = [];
                $problem = false;
                $answered = 0;
                foreach ($questions as $key => $question) {
                    $reply = $responses->get($key.'|'.$district);
                    if ($reply === null) {
                        continue;
                    }
                    $answered++;
                    $problem = $problem || $reply->classification === 'problem';
                    $statuses[] = $this->shortStatus($delivery->control_type, $key, $question, $reply);
                }
                $complete = $answered === count($questions);
                if (! $complete) {
                    $statuses[] = $answered === 0 ? 'Ждём ответа' : 'Есть ещё вопрос';
                }
                $rows[] = ['name' => $name, 'status' => implode(' · ', $statuses), 'icon' => $problem ? '🔴' : ($complete ? '✅' : '▫️')];
            }

            return app(TelegramOksRichCard::class)->render($delivery, OksControlSchedule::LABELS[$delivery->control_type], $rows, 'Открыть контроль');
        }
        $previous = $this->previousReasons($delivery);
        $botUsername = trim((string) config('services.telegram.bot_username'), ' @');
        $lines = [];
        if ($delivery->isOksTest()) {
            $lines[] = '<b>ТЕСТ ОКС · '.e($delivery->scheduled_for->format('H:i')).'</b>';
            $lines[] = '';
        }
        $keyboard = [];
        if (count($questions) > 1) {
            $lines[] = '<b>⏰ '.e(OksControlSchedule::LABELS[$delivery->control_type]).'</b>';
        }
        foreach ($questions as $key => $question) {
            $lines[] = '<b>'.(count($questions) === 1 ? '⏰ ' : '').e($question['title']).'</b>';
            if ($question['details'] !== '') {
                $lines[] = e($question['details']);
            }
            foreach ($districts as $district => $label) {
                $response = $responses->get($key.'|'.$district);
                $status = match (true) {
                    $response === null => '▫️ Ждём ответа',
                    self::isSkipped($response) => '— Поздних выездов нет',
                    $response->classification === 'problem' => '🔴 '.$question['no'],
                    default => '✅ '.$question['yes'],
                };
                $lines[] = e($label.' · '.$status);
                $comment = $response?->classification === 'problem' ? $response->text : null;
                $earlier = $response === null && ($delivery->control_type !== 'oks_start_eleven' || $key === 'late') ? ($previous[$district] ?? null) : null;
                if ($comment !== null || $earlier !== null) {
                    $lines[] = '<i>'.e(Str::limit($comment ?? $earlier, 145)).'</i>';
                }
                if ($response?->classification === 'confirmed') {
                    continue;
                }
                $prefix = 'oksc:'.$delivery->id.':'.$key.':'.$district.':';
                $shortLabel = count($questions) > 1 ? $label.($key === 'late' ? ' · выезды' : ' · старт') : $label;
                $buttons = [['text' => $shortLabel.' · '.$question['yes'], 'callback_data' => $prefix.'yes']];
                if (preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', $botUsername)) {
                    $buttons[] = [
                        'text' => $shortLabel.' · '.($response === null ? $question['no'] : 'Уточнить'),
                        'url' => 'https://t.me/'.$botUsername.'?startapp=oks_'.$delivery->id.'_'.$key.'_'.$district.'&mode=compact',
                    ];
                }
                $keyboard[] = $buttons;
                if ($question['allowsSkip'] && $response === null) {
                    $keyboard[] = [['text' => $label.' · Поздних выездов нет', 'callback_data' => $prefix.'skip']];
                }
            }
            $lines[] = '';
        }
        $text = trim(implode("\n", $lines));
        $markup = ['inline_keyboard' => $keyboard];

        return ['text' => $text, 'reply_markup' => $markup, 'render_hash' => hash('sha256', serialize([$text, $markup]))];
    }

    private function shortStatus(string $type, string $key, array $question, TelegramScheduledMessageResponse $reply): string
    {
        if (self::isSkipped($reply)) {
            return 'Поздних выездов нет';
        }

        $positive = $reply->classification !== 'problem';

        return match ($key) {
            'start' => $positive ? 'Уборки начались' : 'Не все уборки начались',
            'late' => $type === 'oks_start_eleven'
                ? ($positive ? 'Гости выехали' : 'Гости ещё в квартире')
                : ($positive ? 'Поздние выезды под контролем' : 'Есть риск из-за позднего выезда'),
            default => $question[$positive ? 'yes' : 'no'],
        };
    }

    public function callback(array $callback): string
    {
        $data = (string) ($callback['data'] ?? '');
        if (! preg_match('/^oksc:(\d+):(check|start|late):([a-z]+):(yes|skip)$/D', $data, $parts)
            || ! is_numeric(data_get($callback, 'from.id')) || data_get($callback, 'from.is_bot', false)) {
            return 'Кнопка недоступна.';
        }
        [, $id, $key, $district, $action] = $parts;
        $callbackId = (string) ($callback['id'] ?? '');
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->whereKey($id)
            ->where('chat_id', (string) data_get($callback, 'message.chat.id'))
            ->where('message_thread_id', (string) data_get($callback, 'message.message_thread_id'))
            ->where('telegram_message_id', (int) data_get($callback, 'message.message_id'))->first();
        $actor = User::query()->activeStaff()->where('telegram_id', (string) data_get($callback, 'from.id'))->first();
        if ($delivery === null || $actor === null || $callbackId === '' || ! $this->canAnswer($delivery, $key, $district, $actor)) {
            return 'Ответить может дежурный этого района.';
        }
        $question = OksControlSchedule::questions($delivery->control_type)[$key];
        if ($action === 'skip' && ! $question['allowsSkip']) {
            return 'Такой ответ для этого вопроса недоступен.';
        }

        return $this->save($delivery, $key, $district, $actor, $action, $question['yes'], $callbackId)
            ? 'Ответ сохранён.' : 'Ответ сохранён. Карточка пока не обновилась.';
    }

    public function canAnswer(TelegramScheduledMessageDelivery $delivery, string $key, string $district, User $user): bool
    {
        return isset(OksControlSchedule::questions((string) $delivery->control_type)[$key], $this->districtsFor($delivery, $user)[$district]);
    }

    public function districtsFor(TelegramScheduledMessageDelivery $delivery, User $user): array
    {
        if (! $user->is_active || $user->status !== 'approved' || ! filled($user->telegram_id)
            || $delivery->bot_type !== 'work' || ($delivery->status !== 'sent' && ! $delivery->isOksTest()) || ! filled($delivery->telegram_message_id)
            || ! isset(OksControlSchedule::LABELS[$delivery->control_type])
            || ! CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome')->isToday()) {
            return [];
        }

        $assignments = array_filter($delivery->sequence_context['oks_assignments'] ?? [], fn ($assigned): bool => (int) $assigned === (int) $user->id);

        return array_intersect_key(ScheduledControlTypes::DISTRICTS, $assignments);
    }

    public function formQuestions(TelegramScheduledMessageDelivery $delivery, string $district): array
    {
        $questions = OksControlSchedule::questions($delivery->control_type);
        $answers = $this->responses($delivery)->where('district_key', $district)->keyBy(self::questionKey(...));
        foreach ($questions as $key => &$question) {
            $reply = $answers->get($key);
            $question['options'] = ['yes' => $question['yes'], 'note' => $question['no']];
            if ($question['allowsSkip']) {
                $question['options']['skip'] = 'Поздних выездов нет';
            }
            $question['version'] = $reply?->id ?? 0;
            $question['selected'] = match (true) {
                $reply === null => null,
                self::isSkipped($reply) => 'skip',
                $reply->classification === 'problem' => 'note',
                default => 'yes',
            };
            $question['note'] = $question['selected'] === 'note' ? $reply->text : '';
        }

        return $questions;
    }

    public function priorReason(TelegramScheduledMessageDelivery $delivery, string $district): ?string
    {
        return $this->previousReasons($delivery)[$district] ?? null;
    }

    public function latestAnswer(TelegramScheduledMessageDelivery $delivery, string $key, string $district): ?TelegramScheduledMessageResponse
    {
        return $delivery->responses()->where('district_key', $district)
            ->whereIn('classification_reason', [self::RESPONSE_PREFIX.$key, self::RESPONSE_PREFIX.$key.':skip'])
            ->latest('id')->first();
    }

    public function save(TelegramScheduledMessageDelivery $delivery, string $key, string $district, User $user, string $action, string $text, ?string $callbackId = null, ?int $version = null): bool
    {
        Cache::lock('scheduled_control_delivery:'.$delivery->id, 15)->block(3, function () use ($delivery, $key, $district, $user, $action, $text, $callbackId, $version): void {
            abort_unless($this->canAnswer($delivery->refresh(), $key, $district, $user), 403);
            $this->recordAnswer($delivery, $key, $district, $user, $action, $text, $callbackId, $version);
        });

        return $this->sync($delivery);
    }

    public function saveChecklist(TelegramScheduledMessageDelivery $delivery, string $district, User $user, array $answers): bool
    {
        Cache::lock('scheduled_control_delivery:'.$delivery->id, 15)->block(3, function () use ($delivery, $district, $user, $answers): void {
            abort_unless(isset($this->districtsFor($delivery->refresh(), $user)[$district]), 403);
            DB::transaction(function () use ($delivery, $district, $user, $answers): void {
                foreach (OksControlSchedule::questions($delivery->control_type) as $key => $question) {
                    $answer = $answers[$key];
                    $text = $answer['choice'] === 'note' ? trim($answer['note']) : $question['yes'];
                    $this->recordAnswer($delivery, $key, $district, $user, $answer['choice'], $text, version: (int) $answer['version']);
                }
            });
        });

        return $this->sync($delivery);
    }

    private function recordAnswer(TelegramScheduledMessageDelivery $delivery, string $key, string $district, User $user, string $action, string $text, ?string $callbackId = null, ?int $version = null): void
    {
        if ($callbackId !== null && $delivery->responses()->where('telegram_callback_query_id', $callbackId)->exists()) {
            return;
        }
        $latest = $this->latestAnswer($delivery, $key, $district);
        $reason = self::RESPONSE_PREFIX.$key.($action === 'skip' ? ':skip' : '');
        $classification = $action === 'note' ? 'problem' : 'confirmed';
        $text = $action === 'skip' ? 'Поздних выездов нет' : $text;
        if ($latest?->classification === $classification && $latest->text === $text && $latest->classification_reason === $reason) {
            return;
        }
        abort_if($version !== null && (int) ($latest?->id ?? 0) !== $version, 409, 'Ответ уже изменился. Откройте форму заново.');
        $now = CarbonImmutable::now('Europe/Rome');
        $sent = CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), 'Europe/Rome');
        $delivery->responses()->create([
            'telegram_callback_query_id' => $callbackId,
            'chat_id' => $delivery->chat_id,
            'message_thread_id' => $delivery->message_thread_id,
            'reply_to_message_id' => $delivery->telegram_message_id,
            'user_id' => $user->id,
            'telegram_user_id' => $user->telegram_id,
            'author_name' => $user->name,
            'district_key' => $district,
            'text' => $text,
            'classification' => $classification,
            'classification_reason' => $reason,
            'response_kind' => $action === 'note' ? 'text_answer' : 'button',
            'responded_at' => $now,
            'responded_at_utc' => $now->utc()->format('Y-m-d H:i:s'),
            'response_latency_seconds' => $now->lt($sent) ? null : (int) $sent->diffInSeconds($now),
        ]);
    }

    public function waitingDistricts(TelegramScheduledMessageDelivery $delivery): array
    {
        $questions = OksControlSchedule::questions($delivery->control_type);
        $answered = $this->responses($delivery)->groupBy('district_key');
        $waiting = [];
        foreach (array_keys($delivery->sequence_context['oks_assignments'] ?? []) as $district) {
            if ($answered->get($district, collect())->map(self::questionKey(...))->unique()->count() < count($questions)) {
                $waiting[] = ScheduledControlTypes::DISTRICTS[$district];
            }
        }

        return $waiting;
    }

    public function sync(TelegramScheduledMessageDelivery $delivery): bool
    {
        try {
            return Cache::lock('telegram_work_control_sync:'.$delivery->id, 30)->block(3, function () use ($delivery): bool {
                $delivery->refresh();
                $payload = $this->payload($delivery);
                if ($delivery->render_hash === $payload['render_hash']) {
                    return true;
                }
                $rich = TelegramOksRichCard::enabled($delivery);
                $edited = app(TelegramBotService::class)->editMessage((string) $delivery->chat_id, $delivery->telegram_message_id, $payload['rich_message'] ?? [],
                    $payload['text'], $payload['reply_markup'], forceLegacy: ! $rich, preferRich: $rich);
                if ($edited) {
                    $delivery->update(['render_hash' => $payload['render_hash']]);
                }

                return $edited;
            });
        } catch (Throwable $exception) {
            Log::warning('OKS card could not be synchronized.', ['delivery_id' => $delivery->id, 'exception' => class_basename($exception)]);

            return false;
        }
    }

    public static function questionKey(TelegramScheduledMessageResponse $response): string
    {
        return explode(':', (string) $response->classification_reason)[1] ?? '';
    }

    private static function isSkipped(TelegramScheduledMessageResponse $response): bool
    {
        return str_ends_with((string) $response->classification_reason, ':skip');
    }

    private function responses(TelegramScheduledMessageDelivery $delivery): Collection
    {
        $responses = $delivery->relationLoaded('responses') ? $delivery->responses : $delivery->responses()->get();

        return $responses->filter(fn ($response): bool => str_starts_with((string) $response->classification_reason, self::RESPONSE_PREFIX))
            ->sortBy('id');
    }

    public function districtEvents(TelegramScheduledMessageDelivery $delivery): array
    {
        $states = [];
        $events = [];
        $questions = OksControlSchedule::questions($delivery->control_type);
        foreach ($this->responses($delivery) as $response) {
            $district = $response->district_key;
            $states[$district][self::questionKey($response)] = $response;
            $problem = collect($states[$district])->firstWhere('classification', 'problem');
            $classification = $problem !== null ? 'problem' : (count($states[$district]) === count($questions) ? 'confirmed' : 'unclear');
            $events[] = [
                'district' => $district,
                'identity' => 'telegram:'.$response->telegram_user_id,
                'classification' => $classification,
                'responded_at' => $response->responded_at_utc === null ? $response->responded_at->toIso8601String()
                    : CarbonImmutable::parse($response->responded_at_utc, 'UTC')->setTimezone('Europe/Rome')->toIso8601String(),
                'text' => $problem?->text ?? $response->text,
                'response' => $problem ?? $response,
            ];
        }

        return $events;
    }

    private function previousReasons(TelegramScheduledMessageDelivery $delivery): array
    {
        [$type, $key, $time] = match ($delivery->control_type) {
            'oks_start_eleven' => ['oks_start_ten', 'late', '10:15'],
            'oks_first_finish' => ['oks_first_progress', 'check', '12:00'],
            default => [null, null, null],
        };
        if ($type === null) {
            return [];
        }
        $scheduled = CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), 'Europe/Rome')->setTimeFromTimeString($time);
        $query = TelegramScheduledMessageDelivery::query()->where('control_type', $type);
        if ($delivery->isOksTest()) {
            $query->withOksTests()->where('status', TelegramScheduledMessageDelivery::OKS_TEST)
                ->where('scheduled_message_id', $delivery->scheduled_message_id)->whereNotNull('telegram_message_id');
            $scheduled = $scheduled->setSecond(59);
        } else {
            $query->where('status', 'sent');
        }
        $source = $query
            ->where('chat_id', $delivery->chat_id)->where('message_thread_id', $delivery->message_thread_id)
            ->where('scheduled_for', $scheduled->format('Y-m-d H:i:s'))
            ->first();
        if ($source === null) {
            return [];
        }

        return $this->responses($source)->filter(fn ($response): bool => self::questionKey($response) === $key)
            ->keyBy('district_key')->where('classification', 'problem')->map(fn ($response): string => 'В '.$time.' · '.$response->text)->all();
    }
}
