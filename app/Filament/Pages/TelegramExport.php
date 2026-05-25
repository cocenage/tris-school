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

    public string $exportMode = 'compact';

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
                ->label('Рабочая зона / улица')
                ->options(fn () => TelegramChat::query()
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->toArray())
                ->searchable()
                ->live()
                ->afterStateUpdated(function () {
                    $this->telegram_topic_id = null;
                }),

            Forms\Components\Select::make('telegram_topic_id')
                ->label('Квартира / ветка')
                ->options(function () {
                    return TelegramTopic::query()
                        ->when(
                            $this->telegram_chat_id,
                            fn ($query) => $query->where('telegram_chat_id', $this->telegram_chat_id)
                        )
                        ->orderBy('telegram_thread_id')
                        ->get()
                        ->mapWithKeys(fn (TelegramTopic $topic) => [
                            $topic->id => trim(($topic->title ?: 'Квартира') . ' #' . $topic->telegram_thread_id),
                        ])
                        ->toArray();
                })
                ->searchable(),

            Forms\Components\Select::make('purpose')
                ->label('Назначение / тип ветки')
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
                ->placeholder('Не фильтровать')
                ->searchable(),

            Forms\Components\Select::make('exportMode')
                ->label('Режим выгрузки')
                ->options([
                    'compact' => 'Сжатая выгрузка без пустых сообщений',
                    'full' => 'Полная выгрузка дня',
                    'manager_summary' => 'Краткий отчет руководителю',
                    'apartment_history' => 'История по квартире',
                    'complaints' => 'Анализ жалоб',
                    'quality' => 'Качество уборок',
                    'staff_signals' => 'Сотрудники / сигналы внимания',
                    'photos' => 'Фотоотчеты',
                ])
                ->default('compact')
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
            ->values();

        $totalBeforeFilter = $messages->count();

        if ($this->exportMode === 'compact') {
            $messages = $messages
                ->filter(fn (TelegramMessage $message) =>
                    filled($message->text)
                    || filled($message->caption)
                )
                ->values();
        }

        if ($this->exportMode === 'photos') {
            $messages = $messages
                ->filter(fn (TelegramMessage $message) =>
                    $message->attachments->isNotEmpty()
                    || filled($message->caption)
                )
                ->values();
        }

        if ($messages->isEmpty()) {
            $this->exportText = '';

            Notification::make()
                ->title('Сообщений не найдено')
                ->body('Всего по фильтрам найдено: ' . $totalBeforeFilter . '. После режима выгрузки осталось 0.')
                ->warning()
                ->send();

            return;
        }

        $this->exportText = $this->buildExportText($messages, $date, $totalBeforeFilter);

        Notification::make()
            ->title('Выгрузка готова')
            ->body('Сообщений в выгрузке: ' . $messages->count() . ' из ' . $totalBeforeFilter)
            ->success()
            ->send();
    }

    protected function buildExportText($messages, Carbon $date, int $totalBeforeFilter): string
    {
        $lines = [];

        $lines[] = '# Telegram выгрузка для анализа';
        $lines[] = '';
        $lines[] = 'Дата: ' . $date->format('d.m.Y');
        $lines[] = 'Режим: ' . $this->modeLabel($this->exportMode);
        $lines[] = 'Сообщений в выгрузке: ' . $messages->count();
        $lines[] = 'Всего сообщений по фильтрам до очистки: ' . $totalBeforeFilter;
        $lines[] = 'Участников: ' . $messages->pluck('telegram_user_id')->filter()->unique()->count();
        $lines[] = 'Вложений: ' . $messages->sum(fn (TelegramMessage $message) => $message->attachments->count());
        $lines[] = '';

        $lines[] = '## Как читать эту выгрузку';
        $lines[] = 'Форум Telegram = рабочая зона или улица.';
        $lines[] = 'Ветка Telegram = конкретная квартира или объект.';
        $lines[] = 'Сообщения внутри ветки = история событий по этой квартире.';
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

            $lines[] = '## Рабочая зона / улица: ' . ($first->chat?->title ?: 'Без названия');
            $lines[] = '## Квартира / ветка: ' . $this->topicLabel($first);
            $lines[] = 'Сообщений в ветке: ' . $topicMessages->count();
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
            return 'Без ветки';
        }

        $label = ($topic->title ?: 'Квартира') . ' (#' . $topic->telegram_thread_id . ')';

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
            'compact' => 'Сжатая выгрузка без пустых сообщений',
            'manager_summary' => 'Краткий отчет руководителю',
            'apartment_history' => 'История по квартире',
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
                'Сделай краткий отчет для руководителя по рабочим сообщениям за день. Учитывай, что форум Telegram — это рабочая зона или улица, а ветка — конкретная квартира. Выдели: что произошло, какие квартиры требуют внимания, какие проблемы решены, что перенести на завтра, кого стоит похвалить, где есть риски.',

            'apartment_history' =>
                'Проанализируй историю по квартире. Учитывай, что выбранная ветка Telegram — это конкретная квартира. Составь понятную хронологию: что произошло, какие были жалобы, уборки, фотоотчеты, вопросы по ключам/локерам/белью, что решено, что осталось открытым, какие действия нужны дальше.',

            'complaints' =>
                'Проанализируй жалобы и негативные ситуации по рабочим сообщениям. Учитывай, что ветки — это квартиры. Выдели квартиры/объекты, причины жалоб, повторяющиеся проблемы, срочные действия и сообщения, на которые нужно обратить внимание. Не преувеличивай и не делай выводов без сообщений.',

            'quality' =>
                'Проанализируй качество уборок и рабочих процессов. Учитывай, что ветки — это квартиры. Найди проблемы с уборкой, бельем, полотенцами, ключами, локерами, фотоотчетами, коммуникацией и стандартами. Дай список улучшений.',

            'staff_signals' =>
                'Проанализируй коммуникацию сотрудников. Найди конфликты, перегруз, усталость, резкое недовольство, просьбы о помощи и людей, которых стоит поддержать. Не ставь диагнозы, не делай кадровых выводов, только мягкие сигналы внимания.',

            'photos' =>
                'Проанализируй сообщения с фотоотчетами и вложениями. Учитывай, что ветки — это квартиры. Выдели, где фото есть, где не хватает контекста, где возможны проблемы с контролем качества.',

            default =>
                'Проанализируй рабочие сообщения. Учитывай, что форум Telegram — это рабочая зона или улица, а ветка — конкретная квартира. Найди проблемы, жалобы, просрочки, конфликты, полезные решения, квартиры, требующие внимания, сотрудников, которых стоит похвалить, и мягкие сигналы внимания. Не ставь диагнозы и не делай выводов без сообщений.',
        };
    }
}