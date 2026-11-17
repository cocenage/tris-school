<?php

namespace App\Console\Commands;

use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Services\Telegram\OksControlSchedule;
use App\Services\Telegram\ScheduledControlTypes;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOksControlCards;
use App\Services\Telegram\TelegramOksRichCard;
use App\Services\Telegram\TelegramShiftOpening;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramOksTestCommand extends Command
{
    protected $signature = 'telegram:oks-test {stage : opening or cards} {--template=} {--chat=} {--thread=}
        {--retry-unconfirmed= : Retry this HH:MM slot after checking that the message is absent from Telegram}';

    protected $description = 'Send interactive OKS test messages to an explicit Telegram topic';

    public function handle(TelegramBotService $bot, TelegramShiftOpening $opening, TelegramOksControlCards $cards): int
    {
        $stage = $this->argument('stage');
        $chat = (string) $this->option('chat');
        $thread = (string) $this->option('thread');
        $template = (string) $this->option('template');
        $retry = $this->option('retry-unconfirmed');
        if (! in_array($stage, ['opening', 'cards'], true) || ! preg_match('/^-100\d+$/D', $chat)
            || ! preg_match('/^[1-9]\d*$/D', $thread) || ! preg_match('/^[1-9]\d*$/D', $template)) {
            $this->error('Укажи opening или cards, --template, --chat и --thread.');

            return self::INVALID;
        }
        if ($retry !== null && ($stage !== 'cards' || ! isset(OksControlSchedule::TIMES[$retry]))) {
            $this->error('Для --retry-unconfirmed укажи время контрольной карточки в формате HH:MM и этап cards.');

            return self::INVALID;
        }
        $anchor = TelegramScheduledMessage::query()->where('control_type', 'schedule_checked')->find($template);
        if ($anchor === null || (string) $anchor->telegramChat?->telegram_chat_id !== $chat) {
            $this->error('Шаблон проверки графика должен принадлежать указанному чату.');

            return self::INVALID;
        }
        if (! filled(config('services.telegram.bot_token')) || ! preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', trim((string) config('services.telegram.bot_username'), ' @'))) {
            $this->error('Для прогона нужен токен рабочего бота и имя его Mini App.');

            return self::FAILURE;
        }

        $day = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $openingTime = $day->setTime(9, 0, 59);
        $lock = Cache::lock('oks-test:'.$anchor->id.':'.$day->toDateString(), 300);
        $result = $lock->get(function () use ($stage, $chat, $thread, $anchor, $day, $openingTime, $bot, $opening, $cards, $retry): int {
            $openingType = ScheduledControlTypes::SHIFT_OPENING;
            if ($stage === 'opening') {
                return $this->send($anchor, $openingTime, $openingType, $chat, $thread, ['opening_quick' => true], $bot, $opening, $cards)
                    ? self::SUCCESS : self::FAILURE;
            }

            $record = TelegramScheduledMessageDelivery::query()->withOksTests()->where('scheduled_message_id', $anchor->id)
                ->where('scheduled_for', $openingTime->format('Y-m-d H:i:s'))->where('status', TelegramScheduledMessageDelivery::OKS_TEST)
                ->where('chat_id', $chat)->where('message_thread_id', $thread)->whereNotNull('telegram_message_id')->first();
            $assignments = $record === null ? [] : $this->assignments($record);
            if ($assignments === []) {
                $this->error('Сначала отправь тестовое открытие и отметь «Есть» хотя бы у одного района.');

                return self::FAILURE;
            }

            $complete = true;
            foreach (OksControlSchedule::TIMES as $time => $type) {
                $slot = $day->setTimeFromTimeString($time)->setSecond(59);
                if (! $this->send($anchor, $slot, $type, $chat, $thread, ['oks_assignments' => $assignments], $bot, $opening, $cards, retryUnconfirmed: $retry === $time)) {
                    $complete = false;
                }
            }

            return $complete ? self::SUCCESS : self::FAILURE;
        });
        if ($result === false) {
            $this->error('Прогон уже запущен.');

            return self::FAILURE;
        }

        return $result;
    }

    private function assignments(TelegramScheduledMessageDelivery $opening): array
    {
        return Cache::lock('oks-opening:'.$opening->id, 30)->block(3, function () use ($opening): array {
            $context = $opening->refresh()->sequence_context;
            $assignments = $context['oks_test_assignments'] ?? array_filter($context['opening_draft'] ?? []);
            if ($assignments !== [] && ! isset($context['oks_test_assignments'])) {
                $opening->update(['sequence_context' => [...$context, 'oks_test_assignments' => $assignments]]);
            }

            return $assignments;
        });
    }

    private function send(TelegramScheduledMessage $anchor, CarbonImmutable $slot, string $type, string $chat, string $thread, array $context,
        TelegramBotService $bot, TelegramShiftOpening $opening, TelegramOksControlCards $cards, bool $retryUnconfirmed = false): bool
    {
        $delivery = TelegramScheduledMessageDelivery::query()->withOksTests()->firstOrCreate([
            'scheduled_message_id' => $anchor->id,
            'scheduled_for' => $slot->format('Y-m-d H:i:s'),
        ], [
            'control_type' => $type, 'bot_type' => 'work', 'status' => TelegramScheduledMessageDelivery::OKS_TEST,
            'chat_id' => $chat, 'message_thread_id' => $thread, 'sequence_context' => [...$context, TelegramOksRichCard::CONTEXT_KEY => true],
            'failure_reason' => 'oks_test_delivery_unconfirmed',
        ]);
        $time = $slot->format('H:i');
        if (! $delivery->isOksTest() || $delivery->control_type !== $type
            || $delivery->chat_id !== $chat || $delivery->message_thread_id !== $thread) {
            $this->error($time.' — время уже занято другой доставкой.');

            return false;
        }
        if (! $delivery->wasRecentlyCreated && $delivery->telegram_message_id === null && ! $retryUnconfirmed) {
            $this->error($time.' — предыдущая отправка не подтверждена. Карточка пропущена.');

            return false;
        }
        if ($delivery->telegram_message_id !== null) {
            if (TelegramOksRichCard::enabled($delivery)) {
                $synced = $type === ScheduledControlTypes::SHIFT_OPENING ? $opening->syncQuick($delivery, $bot) : $cards->sync($delivery);
                if (! $synced) {
                    $this->error($time.' — Telegram не подтвердил обновление карточки.');

                    return false;
                }
            }
            $this->line($time.' — уже отправлено, Telegram ID '.$delivery->telegram_message_id);

            return true;
        }
        if (! TelegramOksRichCard::enabled($delivery)) {
            $delivery->update(['sequence_context' => [...($delivery->sequence_context ?? []), TelegramOksRichCard::CONTEXT_KEY => true]]);
        }

        $payload = $type === ScheduledControlTypes::SHIFT_OPENING ? $opening->payload($delivery) : $cards->payload($delivery);
        try {
            $id = $bot->sendRichMessage($chat, $payload['rich_message'], $payload['fallback_html'], $thread,
                replyMarkup: $payload['reply_markup'], preferRich: true, buttonsInRichBody: true);
        } catch (Throwable $exception) {
            $this->error($time.' — ошибка Telegram: '.class_basename($exception));

            return false;
        }
        if ($id === null) {
            $this->error($time.' — Telegram не подтвердил доставку.');

            return false;
        }

        $now = CarbonImmutable::now('Europe/Rome');
        $delivery->update([
            'telegram_message_id' => $id, 'sent_at' => $now, 'sent_at_utc' => $now->utc()->format('Y-m-d H:i:s'),
            'failure_reason' => null, 'render_hash' => $payload['render_hash'] ?? null,
        ]);
        $this->line($time.' — Telegram ID '.$id);
        if (! app()->runningUnitTests()) {
            sleep(1);
        }

        return true;
    }
}
