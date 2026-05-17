<?php

namespace App\Filament\Pages;

use App\Models\TelegramWorkMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class AiChatAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?string $navigationLabel = 'AI анализ чатов';

    protected static ?string $title = 'AI анализ чатов';

    protected static string|\UnitEnum|null $navigationGroup = 'Аналитика';

    protected string $view = 'filament.pages.ai-chat-analysis';

    public string $date;

    public ?string $result = null;

    public ?string $prompt = null;

    public array $messages = [];

    public function mount(): void
    {
        $this->date = now()->toDateString();

        $this->loadMessages();
    }

    public function loadMessages(): void
    {
        $chatId = (string) config(
            'services.telegram.work_allowed_chat_id'
        );

        $this->messages = TelegramWorkMessage::query()
            ->where('chat_id', $chatId)
            ->whereDate('created_at', $this->date)
            ->orderBy('created_at')
            ->get([
                'thread_id',
                'username',
                'first_name',
                'last_name',
                'text',
                'created_at',
            ])
            ->map(function ($message) {

                return [

                    'time' => $message->created_at?->format('H:i'),

                    'thread' => $message->thread_id,

                    'author' =>
                        $message->username
                        ?: trim(
                            ($message->first_name ?? '')
                            .' '.
                            ($message->last_name ?? '')
                        ),

                    'text' => $message->text,
                ];

            })
            ->toArray();
    }

    public function generatePrompt(): void
    {
        if (empty($this->messages)) {

            $this->prompt='Сообщений нет';

            return;
        }

        $chat = collect($this->messages)

            ->map(function ($m) {

                return
                    "[{$m['time']}] ".
                    "[topic {$m['thread']}] ".
                    "{$m['author']}:\n".
                    "{$m['text']}";
            })

            ->implode("\n\n");

        $this->prompt =

"Ты анализируешь внутренний форум Tris Service.

Проанализируй работу супервайзеров, дежурных и сотрудников.

Найди:

1. Общую картину дня
2. Вопросы без ответа
3. Где долго отвечали
4. Повторяющиеся проблемы
5. Кто активно помогал
6. Какие риски есть
7. Что проверить админу
8. Оцени работу супервайзеров
9. Итоговая оценка дня от 1 до 10

Не выдумывай.

Если данных мало — скажи это.

Сообщения:

{$chat}";
    }

    protected function getHeaderActions(): array
    {
        return [

            Action::make('reload')

                ->label('Обновить')

                ->icon(Heroicon::ArrowPath)

                ->action(function () {

                    $this->loadMessages();

                    Notification::make()
                        ->title('Обновлено')
                        ->success()
                        ->send();

                }),

            Action::make('generate')

                ->label('Сформировать AI промпт')

                ->icon(Heroicon::Sparkles)

                ->color('primary')

                ->action('generatePrompt'),
        ];
    }
}