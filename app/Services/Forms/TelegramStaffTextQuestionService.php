<?php

namespace App\Services\Forms;

use App\Models\StaffTextQuestion;
use App\Models\StaffTextQuestionDecision;
use App\Models\TelegramOutboundOperation;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\Telegram\TelegramRequestNotSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class TelegramStaffTextQuestionService
{
    protected const KIND = 'salary';
    protected const KEY = 'salary_question';
    protected const RESOURCE = 'salary-questions';
    protected const TITLE = '💰 Вопрос по зарплате';
    protected const RESULT_TITLE = '💰 Ответ на вопрос по зарплате';

    public function __construct(
        private readonly TelegramBotService $telegram,
        private readonly TelegramOutboundMonitor $monitor,
        protected readonly StaffTextQuestionDecisionService $decisions,
    ) {}

    public function employeeName(?User $user): string
    {
        if (! $user) {
            return 'Неизвестный сотрудник';
        }

        return trim((string) $user->name)
            ?: (trim((string) $user->telegram_first_name.' '.(string) $user->telegram_last_name)
                ?: (filled($user->telegram_username) ? '@'.ltrim((string) $user->telegram_username, '@') : 'Сотрудник #'.$user->id));
    }

    private function short(string $text, int $limit): string
    {
        return e(Str::limit(trim($text), $limit));
    }

    public function keyboard(StaffTextQuestion $question): array
    {
        $rows = [];
        $bot = trim((string) config('services.telegram.bot_username'), ' @');
        if ($this->decisions->enabled() && preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', $bot)
            && in_array($question->status, ['pending', 'in_progress'], true)) {
            $link = fn (string $action): string => 'https://t.me/'.$bot.'?startapp='.static::KIND.'_'.$question->id.'_'.$action.'&mode=compact';
            if (static::KIND === 'feedback') {
                $rows[] = [['text' => '✍️ Ответить', 'url' => $link('reply')]];
            } else {
            $rows[] = [
                ['text' => '✅ Одобрить', 'url' => $link('approve')],
                ['text' => '❌ Отказать', 'url' => $link('reject')],
            ];
            $rows[] = [['text' => '🔵 В работу', 'url' => $link('work')]];
            }
        }
        $rows[] = [['text' => 'Открыть заявку', 'url' => url('/admin/'.static::RESOURCE.'/'.$question->id.'/edit')]];

        return ['inline_keyboard' => $rows];
    }

    public function card(StaffTextQuestion $question, ?StaffTextQuestionDecision $decision = null): string
    {
        $question->loadMissing(['user', 'reviewer']);
        $name = $this->short($this->employeeName($question->user), 120);
        if (filled($question->user?->telegram_id) && ctype_digit((string) $question->user->telegram_id)) {
            $name = '<a href="tg://user?id='.e((string) $question->user->telegram_id).'">'.$name.'</a>';
        }
        $status = match ($question->status) {
            'approved' => '✅ Одобрено', 'rejected' => '❌ Отказано', 'in_progress' => '🔵 В работе', 'reviewed' => '✅ Рассмотрено',
            default => '🟡 '.($question::STATUS_LABELS[$question->status] ?? 'Ожидает решения'),
        };
        $lines = [
            '<b>'.static::TITLE.' · #'.$question->id.'</b>', '',
            '👤 <b>Сотрудник:</b> '.$name,
            '📌 <b>Тема:</b> '.$this->short((string) $question->type, 200), '',
            '💬 <b>Комментарий:</b>',
            '<blockquote>'.$this->short((string) $question->comment, 1100).'</blockquote>', '',
            '<b>'.$status.'</b>',
        ];
        if (! empty($question->attachments)) {
            $lines[] = '📎 Прикреплено файлов: '.count($question->attachments);
        }
        if ($decision) {
            $lines[] = '';
            $lines[] = '<b>'.match ($decision->status) {
                'reviewed' => 'Ответ сотруднику', 'approved' => 'Что будет сделано', 'rejected' => 'Причина отказа', default => 'Что проверяем',
            }.':</b>';
            $lines[] = $this->short($decision->comment, 1800);
            $lines[] = '';
            $lines[] = ($decision->status === 'in_progress' ? 'Ответственный: ' : 'Рассмотрел: ')
                .$this->short($this->employeeName($question->reviewer), 120);
            $lines[] = $decision->created_at->timezone('Europe/Rome')->format('d.m.Y · H:i');
            $lines[] = match ($decision->delivery_status) {
                'sent' => '✓ Ответ отправлен сотруднику',
                'failed' => '⚠️ Ответ сохранён, но не доставлен',
                'uncertain' => '⚠️ Результат доставки не подтверждён',
                default => '⏳ Ответ ожидает отправки',
            };
        }

        return implode("\n", $lines);
    }

    public function sendCreated(StaffTextQuestion $question): bool
    {
        $question->refresh();
        if (filled($question->telegram_source_message_id)) {
            return false;
        }
        $chat = (string) config('services.staff_forms.chat_id');
        $thread = (string) config('services.staff_forms.'.static::KIND.'_thread_id');
        $bot = trim((string) config('services.telegram.bot_username'), ' @');
        if ($chat === '' || $thread === '' || ! ctype_digit($thread)
            || ! filled(config('services.telegram.bot_token')) || ! preg_match('/^[a-z][a-z0-9_]{4,31}$/iD', $bot)) {
            throw new \RuntimeException('Salary Telegram destination is not configured.');
        }
        // Reuse the legacy operation key: retrying a pre-rollout notice must not post a second card.
        $key = static::KEY.':'.$question->id;
        $context = [
            'operation_key' => $key, 'notification_type' => static::KEY,
            'source_type' => class_basename($question), 'source_id' => $question->id,
            'bot' => 'work', 'chat_id' => $chat, 'message_thread_id' => $thread,
        ];
        if (! $this->monitor->reserveExpected($context)) {
            $receipt = TelegramOutboundOperation::query()->where('operation_key', $key)
                ->where('notification_type', static::KEY)->where('status', 'sent')
                ->where('chat_id', $chat)->where('message_thread_id', $thread)->first();
            if ($receipt?->telegram_message_id) {
                $question->forceFill([
                    'telegram_source_chat_id' => $chat, 'telegram_source_thread_id' => (int) $thread,
                    'telegram_source_message_id' => $receipt->telegram_message_id,
                ])->saveQuietly();
            }
            // An uncertain send is never repeated automatically.
            return false;
        }

        try {
            $id = $this->monitor->withContext($context, fn () => $this->telegram->sendMessage(
                $chat, $this->card($question), $thread, replyMarkup: $this->keyboard($question),
            ));
        } catch (TelegramRequestNotSent $exception) {
            TelegramOutboundOperation::query()->where('operation_key', $key)
                ->whereIn('status', ['pending', 'uncertain'])->update(['status' => 'expected']);
            throw $exception;
        }
        if (! $id) {
            throw new \RuntimeException('Salary Telegram source delivery was not confirmed.');
        }

        $question->forceFill([
            'telegram_source_chat_id' => $chat, 'telegram_source_thread_id' => (int) $thread,
            'telegram_source_message_id' => $id,
        ])->saveQuietly();

        return true;
    }

    public function deliverDecision(StaffTextQuestionDecision $decision): void
    {
        $question = $decision->question()->with('user')->first();
        if (! $question) {
            return;
        }

        $key = static::KEY.':decision:'.$question->id.':'.$decision->version;
        if ($decision->delivery_status === 'uncertain') {
            $receipt = TelegramOutboundOperation::query()->where('operation_key', $key)->where('status', 'sent')->first();
            if ($receipt?->telegram_message_id) {
                $decision->update(['delivery_status' => 'sent', 'telegram_message_id' => $receipt->telegram_message_id]);
            }
            return;
        }
        if ($decision->delivery_status !== 'pending') {
            return;
        }
        $chat = (string) $question->user?->telegram_id;
        if ($chat === '' || ! filled(config('services.telegram.bot_token'))) {
            $decision->update(['delivery_status' => 'failed']);
            return;
        }
        // Reserve before the external side effect. A crash or ambiguous HTTP response cannot cause a duplicate send.
        if ($decision->newQuery()->whereKey($decision->id)->where('delivery_status', 'pending')
            ->update(['delivery_status' => 'uncertain']) !== 1) {
            return;
        }
        $context = [
            'operation_key' => $key, 'notification_type' => static::KEY.'_result', 'bot' => 'work',
            'source_type' => class_basename($question), 'source_id' => $question->id, 'chat_id' => $chat,
        ];
        $this->monitor->expect($context);
        $text = implode("\n", [
            '<b>'.static::RESULT_TITLE.' · #'.$question->id.'</b>', '',
            '<b>Тема:</b> '.$this->short((string) $question->type, 200),
            '<b>Ваше обращение:</b> '.$this->short((string) $question->comment, 800), '',
            '<b>'.$question::STATUS_LABELS[$decision->status].'</b>',
            $this->short($decision->comment, 1800),
        ]);

        try {
            $id = $this->monitor->withContext($context, fn () => $this->telegram->sendMessage(
                $chat, $text, replyMarkup: ['inline_keyboard' => [[['text' => 'Мои заявки', 'url' => route('page-profile.applications')]]]],
            ));
            if ($id) {
                $decision->update(['delivery_status' => 'sent', 'telegram_message_id' => $id]);
                return;
            }
            $receipt = TelegramOutboundOperation::query()->where('operation_key', $key)->first();
            if ($receipt?->status === 'failed' && $receipt->error_code >= 400 && $receipt->error_code < 500
                && $receipt->error_code !== 408) {
                $decision->update(['delivery_status' => 'failed']);
            }
        } catch (TelegramRequestNotSent $exception) {
            $decision->update(['delivery_status' => 'pending']);
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Salary decision delivery uncertain.', ['decision_id' => $decision->id, 'exception' => class_basename($exception)]);
        }
    }

    public function syncSource(StaffTextQuestion $question): bool
    {
        $question->refresh();
        $decision = $question->decisions()->where('version', $question->decision_version)->first();
        if (! $decision || ! $question->telegram_source_chat_id || ! $question->telegram_source_message_id) {
            return false;
        }
        // Never edit a different chat/topic after a configuration change.
        if ((string) $question->telegram_source_chat_id !== (string) config('services.staff_forms.chat_id')
            || (string) $question->telegram_source_thread_id !== (string) config('services.staff_forms.'.static::KIND.'_thread_id')) {
            $decision->update(['card_sync_status' => 'failed', 'card_sync_attempts' => 5]);
            return false;
        }
        $context = [
            'operation_key' => static::KEY.':edit:'.$question->id.':'.$decision->version.':'.$decision->delivery_status,
            'notification_type' => static::KEY, 'method' => 'editMessageText', 'bot' => 'work',
            'source_type' => class_basename($question), 'source_id' => $question->id,
            'chat_id' => (string) $question->telegram_source_chat_id,
            'message_thread_id' => (string) $question->telegram_source_thread_id,
        ];
        $this->monitor->expect($context);
        try {
            $edited = $this->monitor->withContext($context, fn () => $this->telegram->editMessage(
                (string) $question->telegram_source_chat_id, (int) $question->telegram_source_message_id,
                [], $this->card($question, $decision), $this->keyboard($question), forceLegacy: true,
            ));
        } catch (\Throwable $exception) {
            $edited = false;
            Log::warning('Salary source card sync failed.', ['question_id' => $question->id, 'exception' => class_basename($exception)]);
        }
        $decision->newQuery()->whereKey($decision->id)->where('card_sync_status', '!=', 'superseded')
            ->update(['card_sync_status' => $edited ? 'synced' : 'failed', 'card_sync_attempts' => min(5, $decision->card_sync_attempts + 1)]);

        return $edited;
    }
}
