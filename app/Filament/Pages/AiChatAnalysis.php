<?php

namespace App\Filament\Pages;

use App\Models\TelegramWorkMessage;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class AiChatAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static ?string $navigationLabel = 'AI анализ чатов';

    protected static ?string $title = 'AI анализ чатов';

    protected static string|\UnitEnum|null $navigationGroup = 'Аналитика';

    protected string $view = 'filament.pages.ai-chat-analysis';

    public string $date;

    public ?string $result = null;

    public array $messages = [];

    public function mount(): void
    {
        $this->date = now()->toDateString();

        $this->loadMessages();
    }

   public function loadMessages(): void
{
    $this->messages = TelegramWorkMessage::query()
        ->whereDate('sent_at', $this->date)
        ->orderBy('sent_at')
        ->get([
            'chat_title',
            'thread_id',
            'username',
            'first_name',
            'last_name',
            'text',
            'sent_at',
        ])
        ->map(fn (TelegramWorkMessage $message) => [
            'time' => $message->sent_at?->format('H:i'),
            'thread_id' => $message->thread_id,
            'author' => $message->username
                ?: trim(($message->first_name ?? '') . ' ' . ($message->last_name ?? '')),
            'text' => $message->text,
        ])
        ->toArray();

    $this->result = null;
}

    public function analyze(): void
    {
        $messages = collect($this->messages);

        if ($messages->isEmpty()) {
            $this->result = 'За выбранный день сообщений нет.';
            return;
        }

        $byUsers = $messages
            ->groupBy('author')
            ->map(fn (Collection $items) => $items->count())
            ->sortDesc();

        $byThreads = $messages
            ->groupBy('thread_id')
            ->map(fn (Collection $items) => $items->count())
            ->sortDesc();

        $questions = $messages
            ->filter(fn ($m) => str_contains($m['text'], '?'))
            ->values();

        $this->result =
            "Черновой анализ без AI:\n\n" .
            "Всего сообщений: {$messages->count()}\n\n" .
            "Активность по людям:\n" .
            $byUsers->map(fn ($count, $user) => "- {$user}: {$count}")->implode("\n") .
            "\n\nАктивность по топикам:\n" .
            $byThreads->map(fn ($count, $thread) => "- Топик {$thread}: {$count}")->implode("\n") .
            "\n\nВопросы, которые нужно проверить:\n" .
            ($questions->isEmpty()
                ? "- Вопросов не найдено"
                : $questions->map(fn ($m) => "- {$m['time']} {$m['author']}: {$m['text']}")->implode("\n"));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('analyze')
                ->label('Проанализировать день')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action('analyze'),
        ];
    }
}