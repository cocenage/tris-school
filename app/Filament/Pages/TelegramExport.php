<?php

namespace App\Filament\Pages;

use App\Models\TelegramChat;
use App\Models\TelegramMessage;
use App\Models\TelegramTopic;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class TelegramExport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?string $navigationLabel = 'Telegram выгрузка';

    protected static ?string $title = 'Telegram выгрузка';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected string $view = 'filament.pages.telegram-export';

    public ?string $date = null;

    public ?int $telegram_chat_id = null;

    public ?int $telegram_topic_id = null;

    public string $exportText = '';

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\DatePicker::make('date')
                ->label('Дата')
                ->required(),

            Forms\Components\Select::make('telegram_chat_id')
                ->label('Чат')
                ->options(fn () => TelegramChat::query()
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->toArray())
                ->searchable()
                ->live(),

            Forms\Components\Select::make('telegram_topic_id')
                ->label('Топик')
                ->options(function () {
                    return TelegramTopic::query()
                        ->when($this->telegram_chat_id, fn ($query) => $query->where('telegram_chat_id', $this->telegram_chat_id))
                        ->orderBy('telegram_thread_id')
                        ->get()
                        ->mapWithKeys(fn (TelegramTopic $topic) => [
                            $topic->id => trim(($topic->title ?: 'Топик') . ' #' . $topic->telegram_thread_id),
                        ])
                        ->toArray();
                })
                ->searchable(),
        ]);
    }

    public function generate(): void
    {
        $date = Carbon::parse($this->date);

        $messages = TelegramMessage::query()
            ->with(['chat', 'topic', 'telegramUser', 'attachments'])
            ->whereBetween('sent_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ])
            ->when($this->telegram_chat_id, fn ($query) => $query->where('telegram_chat_id', $this->telegram_chat_id))
            ->when($this->telegram_topic_id, fn ($query) => $query->where('telegram_topic_id', $this->telegram_topic_id))
            ->orderBy('sent_at')
            ->get();

        if ($messages->isEmpty()) {
            $this->exportText = '';

            Notification::make()
                ->title('Сообщений не найдено')
                ->warning()
                ->send();

            return;
        }

        $lines = [];

        $lines[] = '# Telegram выгрузка для анализа';
        $lines[] = '';
        $lines[] = 'Дата: ' . $date->format('d.m.Y');
        $lines[] = 'Сообщений: ' . $messages->count();
        $lines[] = '';

        $grouped = $messages->groupBy(fn (TelegramMessage $message) => $message->topic?->telegram_thread_id ?: 'Без топика');

        foreach ($grouped as $topicId => $topicMessages) {
            $first = $topicMessages->first();

            $lines[] = '## Чат: ' . ($first->chat?->title ?: 'Без названия');
            $lines[] = '## Топик: ' . $topicId;
            $lines[] = '';

            foreach ($topicMessages as $message) {
                $time = $message->sent_at?->format('H:i') ?? '--:--';

                $author = $message->telegramUser?->full_name
                    ?: $message->telegramUser?->username
                    ?: 'Неизвестно';

                $text = $message->text ?: $message->caption;

                if (!$text && $message->attachments->isNotEmpty()) {
                    $text = '[Вложение: ' . $message->attachments->pluck('type')->implode(', ') . ']';
                }

                if (!$text) {
                    $text = '[Пустое или системное сообщение]';
                }

                $lines[] = '[' . $time . '] ' . $author . ': ' . trim($text);
            }

            $lines[] = '';
        }

        $this->exportText = implode("\n", $lines);

        Notification::make()
            ->title('Выгрузка готова')
            ->success()
            ->send();
    }
}