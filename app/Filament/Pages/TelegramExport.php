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
use UnitEnum;

class TelegramExport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Telegram выгрузка';

    protected static ?string $title = 'Telegram выгрузка';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected string $view = 'filament.pages.telegram-export';

    public ?string $date = null;

    public ?int $telegram_chat_id = null;

    public ?int $telegram_topic_id = null;

    public ?string $purpose = null;

    public string $exportMode = 'full';

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
                        ->when(
                            $this->telegram_chat_id,
                            fn ($query) => $query->where('telegram_chat_id', $this->telegram_chat_id)
                        )
                        ->orderBy('telegram_thread_id')
                        ->get()
                        ->mapWithKeys(fn (TelegramTopic $topic) => [
                            $topic->id => trim(($topic->title ?: 'Топик') . ' #' . $topic->telegram_thread_id),
                        ])
                        ->toArray();
                })
                ->searchable(),

            Forms\Components\Select::make('purpose')
                ->label('Назначение топика')
                ->options([
                    'cleaning' => 'Уборки',
                    'complaints' => 'Жалобы',
                    'reports' => 'Фотоотчеты',
                    'tasks' => 'Задачи',
                    'staff' => 'Сотрудники',
                    'admin' => 'Админское',
                    'salary' => 'Зарплата',
                    'vacation' => 'Отпуск',
                    'day_off' => 'Выходные',
                    'other' => 'Другое',
                ])
                ->searchable(),

            Forms\Components\Select::make('exportMode')
                ->label('Режим выгрузки')
                ->options([
                    'full' => 'Полная выгрузка дня',
                    'manager_summary' => 'Краткий отчет руководителю',
                    'complaints' => 'Анализ жалоб',
                    'quality' => 'Качество уборок',
                    'staff_signals' => 'Сотрудники / сигналы внимания',
                    'photos' => 'Фотоотчеты',
                ])
                ->default('full')
                ->required(),
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
            ->when($this->purpose, function ($query) {
                $query->whereHas('topic', fn ($topicQuery) => $topicQuery->where('purpose', $this->purpose));
            })
            ->orderBy('sent_at')
            ->get()
            ->filter(function (TelegramMessage $message) {
                return filled($message->text)
                    || filled($message->caption)
                    || $message->attachments->isNotEmpty();
            })
            ->values();

        if ($messages->isEmpty()) {
            $this->exportText = '';

            Notification::make()
                ->title('Сообщений не найдено')
                ->warning()
                ->send();

            return;
        }

        $this->exportText = $this->buildExportText($messages, $date);

        Notification::make()
            ->title('Выгрузка готова')
            ->success()
            ->send();
    }

    protected function buildExportText($messages, Carbon $date): string
    {
        $lines = [];

        $lines[] = '# Telegram выгрузка для анализа';
        $lines[] = '';
        $lines[] = 'Дата: ' . $date->format('d.m.Y');
        $lines[] = 'Режим: ' . $this->modeLabel($this->exportMode);
        $lines[] = 'Сообщений: ' . $messages->count();
        $lines[] = 'Участников: ' . $messages->pluck('telegram_user_id')->filter()->unique()->count();
        $lines[] = 'Вложений: ' . $messages->sum(fn (TelegramMessage $message) => $message->attachments->count());
        $lines[] = '';

        $lines[] = '## Задача для анализа';
        $lines[] = $this->promptForMode($this->exportMode);
        $lines[] = '';

        $lines[] = '## Статистика по участникам';
        $authorStats = $messages
            ->groupBy(fn (TelegramMessage $message) => $this->authorName($message))
            ->map(fn ($items) => $items->count())
            ->sortDesc();

        foreach ($authorStats as $author => $count) {
            $lines[] = '- ' . $author . ': ' . $count . ' сообщений';
        }

        $lines[] = '';

        $grouped = $messages->groupBy(
            fn (TelegramMessage $message) => $message->topic?->id ?: 'no_topic'
        );

        foreach ($grouped as $topicMessages) {
            /** @var TelegramMessage $first */
            $first = $topicMessages->first();

            $lines[] = '## Чат: ' . ($first->chat?->title ?: 'Без названия');
            $lines[] = '## Топик: ' . $this->topicLabel($first);
            $lines[] = 'Сообщений в топике: ' . $topicMessages->count();
            $lines[] = '';

            foreach ($topicMessages as $message) {
                $lines[] = $this->messageLine($message);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function messageLine(TelegramMessage $message): string
    {
        $time = $message->sent_at?->format('H:i') ?? '--:--';

        $author = $this->authorName($message);

        $text = $message->text ?: $message->caption;

        if (!$text && $message->attachments->isNotEmpty()) {
            $text = '[Вложение]';
        }

        if (!$text) {
            $text = '[Пустое или системное сообщение]';
        }

        $attachmentText = '';

        if ($message->attachments->isNotEmpty()) {
            $attachmentText = ' [вложения: ' . $message->attachments->pluck('type')->implode(', ') . ']';
        }

        return '[' . $time . '] ' . $author . ': ' . trim($text) . $attachmentText;
    }

    protected function authorName(TelegramMessage $message): string
    {
        return $message->telegramUser?->full_name
            ?: $message->telegramUser?->username
            ?: 'Неизвестно';
    }

    protected function topicLabel(TelegramMessage $message): string
    {
        $topic = $message->topic;

        if (!$topic) {
            return 'Без топика';
        }

        $label = ($topic->title ?: 'Топик') . ' (#' . $topic->telegram_thread_id . ')';

        if ($topic->purpose) {
            $label .= ' — ' . $this->purposeLabel($topic->purpose);
        }

        return $label;
    }

    protected function purposeLabel(?string $purpose): string
    {
        return match ($purpose) {
            'cleaning' => 'Уборки',
            'complaints' => 'Жалобы',
            'reports' => 'Фотоотчеты',
            'tasks' => 'Задачи',
            'staff' => 'Сотрудники',
            'admin' => 'Админское',
            'salary' => 'Зарплата',
            'vacation' => 'Отпуск',
            'day_off' => 'Выходные',
            'other' => 'Другое',
            default => $purpose ?: 'Без назначения',
        };
    }

    protected function modeLabel(string $mode): string
    {
        return match ($mode) {
            'manager_summary' => 'Краткий отчет руководителю',
            'complaints' => 'Анализ жалоб',
            'quality' => 'Качество уборок',
            'staff_signals' => 'Сотрудники / сигналы внимания',
            'photos' => 'Фотоотчеты',
            default => 'Полная выгрузка дня',
        };
    }

    protected function promptForMode(string $mode): string
    {
        return match ($mode) {
            'manager_summary' =>
                'Сделай краткий отчет для руководителя: что произошло за день, какие были проблемы, что решено, что требует внимания завтра, кого стоит похвалить.',

            'complaints' =>
                'Проанализируй жалобы и негативные ситуации. Выдели причины, квартиры/объекты, повторяющиеся проблемы, ответственных, срочные действия. Не преувеличивай и не делай выводов без сообщений.',

            'quality' =>
                'Проанализируй качество уборок и рабочих процессов. Найди просрочки, проблемы с фотоотчетами, чек-листами, коммуникацией и стандартами уборки. Дай список улучшений.',

            'staff_signals' =>
                'Проанализируй коммуникацию сотрудников. Найди конфликты, перегруз, усталость, резкое недовольство, просьбы о помощи и людей, которых стоит поддержать. Не ставь диагнозы, только мягкие сигналы внимания.',

            'photos' =>
                'Проанализируй сообщения с фотоотчетами и вложениями. Выдели, где фото есть, где не хватает контекста, где возможны проблемы с качеством контроля.',

            default =>
                'Проанализируй рабочие сообщения. Найди проблемы, жалобы, просрочки, конфликты, полезные решения, сотрудников, которых стоит похвалить, и возможные сигналы внимания. Не ставь диагнозы, только мягкие наблюдения.',
        };
    }
}