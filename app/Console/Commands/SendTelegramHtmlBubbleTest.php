<?php

namespace App\Console\Commands;

use App\Services\Telegram\HtmlBubbles\OksWidgetBuilder;
use App\Services\Telegram\HtmlBubbles\TelegramHtmlBubblesClient;
use App\Services\Telegram\HtmlBubbles\TelegramHtmlBubblesTargetGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;

class SendTelegramHtmlBubbleTest extends Command
{
    protected $signature = 'telegram:htmlbubbles:send-test {--chat-id= : Must match the separately configured test chat} {--message-thread-id= : Must match the separately configured test topic}';

    protected $description = 'Send the isolated OКС HTMLBubble test card to its explicitly configured test destination';

    public function handle(
        TelegramHtmlBubblesClient $client,
        OksWidgetBuilder $builder,
        TelegramHtmlBubblesTargetGuard $targetGuard,
    ): int {
        if (! (bool) config('services.telegram.htmlbubbles.enabled', false)) {
            $this->error('HTMLBubble experiment is disabled.');

            return self::FAILURE;
        }

        $chatId = $this->option('chat-id') !== null ? (string) $this->option('chat-id') : null;
        $requestedThreadId = $this->option('message-thread-id') !== null
            ? (string) $this->option('message-thread-id')
            : null;

        if (! $targetGuard->allowsConfiguredTarget($chatId, $requestedThreadId)) {
            $this->error('A dedicated, non-operational test chat and matching thread must be configured first.');

            return self::FAILURE;
        }

        $token = (string) config('services.telegram.bot_token', '');
        $html = $builder->html();
        $chatData = json_encode($builder->initialChatData(), JSON_THROW_ON_ERROR);

        if (strlen($html) > 32768 || strlen(gzcompress($html, 9) ?: '') > 4096 || strlen($chatData) > 1024) {
            $this->error('The widget payload exceeds Telegram limits.');

            return self::FAILURE;
        }

        $parameters = [
            'chat_id' => $chatId,
            'caption' => $builder->caption(),
            'html' => $html,
            'width' => 0,
            'height' => 0,
            'allow_queries' => true,
            'chat_data' => $chatData,
        ];

        if (($threadId = $targetGuard->configuredThreadId()) !== null) {
            $parameters['message_thread_id'] = $threadId;
        }

        try {
            $result = $client->sendWidget($token, $parameters);
        } catch (\Throwable $error) {
            Log::warning('Telegram HTMLBubble test send failed', [
                'exception' => class_basename($error),
            ]);
            $this->error('Telegram test result is not confirmed. Check the dedicated test chat before retrying. No fallback message was sent.');

            return self::FAILURE;
        }

        $messageId = filter_var($result['message_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($messageId === false) {
            $this->error('Telegram accepted no verifiable message id; the widget is not registered for test actions.');

            return self::FAILURE;
        }

        try {
            $initialState = json_decode($chatData, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The local test widget state could not be encoded.');

            return self::FAILURE;
        }

        try {
            Cache::put(
                $targetGuard->messageCacheKey((string) $chatId, $messageId),
                ['status' => $initialState['status'] ?? 'prepared'],
                now()->addHours(24),
            );
        } catch (\Throwable $error) {
            Log::warning('Telegram HTMLBubble test state could not be registered', [
                'exception' => class_basename($error),
            ]);
            $this->error('Telegram accepted the widget, but test actions are not registered. Do not resend; verify the current message first.');

            return self::FAILURE;
        }

        $this->info('The demo HTMLBubble was sent to the configured test destination.');

        return self::SUCCESS;
    }
}
