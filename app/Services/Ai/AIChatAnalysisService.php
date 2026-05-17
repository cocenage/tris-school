<?php

namespace App\Services\AI;

use App\Models\AiChatReport;
use App\Models\TelegramWorkMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIChatAnalysisService
{
    public function analyzeDate(string $date): AiChatReport
    {
        $chatId = (string) config('services.telegram.work_allowed_chat_id');

        $messages = TelegramWorkMessage::query()
            ->where('chat_id', $chatId)
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        $prompt = $this->buildPrompt($date, $messages);

        if ($messages->isEmpty()) {
            return AiChatReport::updateOrCreate(
                [
                    'report_date' => $date,
                    'chat_id' => $chatId,
                ],
                [
                    'messages_count' => 0,
                    'prompt' => $prompt,
                    'result' => 'За выбранный день сообщений нет.',
                    'meta' => [
                        'status' => 'empty',
                    ],
                ]
            );
        }

        $result = $this->sendToOpenAI($prompt);

        return AiChatReport::updateOrCreate(
            [
                'report_date' => $date,
                'chat_id' => $chatId,
            ],
            [
                'messages_count' => $messages->count(),
                'prompt' => $prompt,
                'result' => $result,
                'meta' => [
                    'model' => config('services.openai.model'),
                    'chat_id' => $chatId,
                    'generated_at' => now()->toDateTimeString(),
                ],
            ]
        );
    }

    protected function buildPrompt(string $date, $messages): string
    {
        $lines = $messages->map(function (TelegramWorkMessage $message) {
            $time = $message->created_at?->format('H:i');
            $author = $message->username
                ?: trim(($message->first_name ?? '') . ' ' . ($message->last_name ?? ''));

            $thread = $message->thread_id ? "topic {$message->thread_id}" : 'без топика';

            return "[{$time}] [{$thread}] {$author}: {$message->text}";
        })->implode("\n");

        return <<<PROMPT
Ты анализируешь рабочий Telegram-форум клининговой компании Tris Service.

Дата анализа: {$date}

Твоя задача — дать управленческий отчет по работе супервайзеров, дежурного и клинеров.

Проанализируй сообщения и выдай отчет строго на русском языке.

Нужно найти:

1. Общая картина дня
2. Вопросы, которые остались без ответа
3. Где была задержка реакции
4. Какие проблемы повторялись
5. Кто активно решал вопросы
6. Кто писал проблему, но не получил нормального ответа
7. Риски для работы
8. Что администратору нужно проверить
9. Краткая оценка работы дежурного/супервайзеров
10. Итоговая оценка дня от 1 до 10

Важно:
- Не выдумывай факты.
- Если данных мало, прямо так и напиши.
- Не обвиняй людей жестко, формулируй как "стоит проверить", "возможно", "нужно уточнить".
- Отделяй факты от предположений.
- Используй короткие пункты.

Сообщения:

{$lines}
PROMPT;
    }

    protected function sendToOpenAI(string $prompt): string
    {
        $apiKey = config('services.openai.api_key');
        $model = config('services.openai.model', 'gpt-4o-mini');

        if (! $apiKey) {
            return 'OPENAI_API_KEY не указан в .env';
        }

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Ты строгий, но аккуратный аналитик работы клининговой компании.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => 0.2,
                ]);

            if (! $response->successful()) {
                Log::error('OpenAI chat analysis failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return 'Ошибка OpenAI: ' . $response->body();
            }

            return $response->json('choices.0.message.content')
                ?? 'OpenAI не вернул текст анализа.';
        } catch (\Throwable $e) {
            Log::error('OpenAI chat analysis exception', [
                'error' => $e->getMessage(),
            ]);

            return 'Ошибка AI-анализа: ' . $e->getMessage();
        }
    }
}