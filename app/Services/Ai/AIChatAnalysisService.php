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

            ->whereDate(
                'created_at',
                $this->date
            )

            ->orderBy('created_at')

            ->get([
                'username',
                'first_name',
                'last_name',
                'text',
                'created_at',
            ])

            ->map(function ($message) {

                return [

                    'time' => $message
                        ->created_at
                        ?->format('H:i'),

                    'author' =>

                        $message->username

                        ?:

                        trim(
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

            $this->prompt =
                'За выбранный день сообщений нет';

            return;
        }

        $chat = collect($this->messages)

            ->map(function ($m) {

                return

                    "[{$m['time']}]\n"

                    ."{$m['author']}:\n"

                    ."{$m['text']}";
            })

            ->implode("\n\n");

        $this->prompt =

"Ты анализируешь внутренний рабочий форум Tris Service.

Проанализируй переписку супервайзеров, дежурных и сотрудников.

Найди:

1. Общую картину дня

2. Какие вопросы остались без ответа

3. Где сотрудники долго ждали ответ

4. Повторяющиеся проблемы

5. Кто активно помогал

6. Где возможны проблемы

7. Что проверить админу

8. Оценку работы супервайзеров

9. Оценку дня от 1 до 10

Важно:

— не выдумывай
— если информации мало, так и скажи
— разделяй факты и предположения
— не обвиняй сотрудников

Сообщения:

{$chat}";
    }

    protected function getHeaderActions(): array
    {
        return [

            Action::make('reload')

                ->label('Обновить')

                ->icon(Heroicon::ArrowPath)

                ->color('gray')

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

    public function getSubheading(): ?string
    {
        return 'Сообщений: '.count(
            $this->messages
        );
    }
}