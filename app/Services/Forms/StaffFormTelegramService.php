<?php

namespace App\Services\Forms;

use App\Models\FeedbackSuggestion;
use App\Models\SalaryQuestion;
use App\Models\ScheduleQuestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StaffFormTelegramService
{
    public function sendSalaryQuestion(SalaryQuestion $record): void
    {
        $record->loadMissing('user');

        $this->send(
            title: '💰 Новый вопрос по зарплате',
            typeLabel: 'Вопрос по зарплате',
            userName: $record->user?->name ?? 'Неизвестный сотрудник',
            type: $record->type,
            comment: $record->comment,
            attachments: $record->attachments ?? [],
            adminUrl: url('/admin/salary-questions/' . $record->id . '/edit'),
            threadId: config('services.staff_forms.salary_thread_id'),
        );
    }

    public function sendScheduleQuestion(ScheduleQuestion $record): void
    {
        $record->loadMissing('user');

        $this->send(
            title: '📅 Новый вопрос по графику',
            typeLabel: 'Вопрос по графику',
            userName: $record->user?->name ?? 'Неизвестный сотрудник',
            type: $record->type,
            comment: $record->comment,
            attachments: $record->attachments ?? [],
            adminUrl: url('/admin/schedule-questions/' . $record->id . '/edit'),
            threadId: config('services.staff_forms.schedule_thread_id'),
        );
    }

    public function sendFeedbackSuggestion(FeedbackSuggestion $record): void
    {
        $record->loadMissing('user');

        $this->send(
            title: '💡 Новое обращение',
            typeLabel: 'Обратная связь',
            userName: $record->user?->name ?? 'Неизвестный сотрудник',
            type: $record->type,
            comment: $record->comment,
            attachments: $record->attachments ?? [],
            adminUrl: url('/admin/feedback-suggestions/' . $record->id . '/edit'),
            threadId: config('services.staff_forms.feedback_thread_id'),
        );
    }

    protected function send(
        string $title,
        string $typeLabel,
        string $userName,
        string $type,
        string $comment,
        array $attachments,
        string $adminUrl,
        mixed $threadId = null,
    ): void {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.staff_forms.chat_id');

        if (blank($token) || blank($chatId)) {
            Log::warning('Staff form telegram skipped: missing credentials');

            return;
        }

        $message = [];

        $message[] = "<b>{$title}</b>";
        $message[] = '';

        $message[] = '👤 <b>Сотрудник:</b> ' . e($userName);
        $message[] = '🏷️ <b>Категория:</b> ' . e($typeLabel);
        $message[] = '📌 <b>Тема:</b> ' . e($type);

        $message[] = '';
        $message[] = '💬 <b>Комментарий:</b>';
        $message[] = '<blockquote>' . e(trim($comment)) . '</blockquote>';

        if (!empty($attachments)) {
            $message[] = '';
            $message[] = '📎 <b>Прикреплено файлов:</b> ' . count($attachments);
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => implode("\n", $message),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,

            'reply_markup' => [
                'inline_keyboard' => [

                    [
                        [
                            'text' => '⚡ Открыть заявку',
                            'url' => $adminUrl,
                        ]
                    ],

                    [
                        [
                            'text' => '👤 Сотрудники',
                            'url' => url('/admin/users'),
                        ],
                        [
                            'text' => '📊 Панель',
                            'url' => url('/admin'),
                        ]
                    ],

                ],
            ],
        ];

        if (filled($threadId)) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload
        );

        if ($response->failed()) {
            Log::error('Staff form telegram send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'thread_id' => $threadId,
            ]);

            return;
        }

        $this->sendAttachments(
            token: $token,
            chatId: $chatId,
            threadId: $threadId,
            attachments: $attachments,
        );
    }

    protected function sendAttachments(
        string $token,
        string $chatId,
        mixed $threadId,
        array $attachments,
    ): void {
        if (empty($attachments)) {
            return;
        }

        $photos = collect($attachments)
            ->filter(function (array $file) {
                return str_starts_with((string) ($file['mime'] ?? ''), 'image/')
                    && filled($file['path'] ?? null);
            })
            ->values();

        if ($photos->isEmpty()) {
            return;
        }

        foreach ($photos->chunk(10) as $chunk) {
            $media = $chunk
                ->map(function (array $file, int $index) {
                    $url = asset('storage/' . ltrim($file['path'], '/'));

                    return [
                        'type' => 'photo',
                        'media' => $url,
                        'caption' => $index === 0 ? '📎 Фото к заявке' : null,
                        'parse_mode' => 'HTML',
                    ];
                })
                ->values()
                ->all();

            $payload = [
                'chat_id' => $chatId,
                'media' => $media,
            ];

            if (filled($threadId)) {
                $payload['message_thread_id'] = (int) $threadId;
            }

            $response = Http::timeout(20)->post(
                "https://api.telegram.org/bot{$token}/sendMediaGroup",
                $payload
            );

            if ($response->failed()) {
                Log::error('Staff form telegram media group failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'thread_id' => $threadId,
                    'media' => $media,
                ]);
            }
        }
    }
}