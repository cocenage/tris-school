<?php

namespace App\Filament\Resources\Controls\Schemas;

use App\Models\Control;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ControlForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make([
                'default' => 1,
                'lg' => 3,
            ])->schema([
                Section::make()
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->schema([
                        Tabs::make('control_tabs')
                            ->persistTabInQueryString()
                            ->tabs([
                                Tab::make('Основное')
                                    ->schema([
                                        self::baseInfoSection(),
                                    ]),

                                Tab::make('Структура')
                                    ->schema([
                                        self::structureStatsSection(),
                                        self::roomsSection(),
                                    ]),
                            ]),
                    ]),

                Section::make('Быстрые настройки')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 1,
                    ])
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),

                        Placeholder::make('hint')
                            ->label('Логика оценки')
                            ->content('Положительный ответ обычно = 2 балла. Если у критического вопроса выбран отрицательный ответ, итог не сможет попасть в зелёную зону.'),
                    ]),
            ]),
        ]);
    }

    private static function baseInfoSection(): Section
    {
        return Section::make('Основная информация')
            ->schema([
                Grid::make([
                    'default' => 1,
                    'xl' => 2,
                ])->schema([
                    TextInput::make('name')
                        ->label('Название чек-листа')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, $state, callable $set): void {
                            if ($operation === 'create' && filled($state)) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(Control::class, 'slug', ignoreRecord: true)
                        ->readOnly(),
                ]),

                Textarea::make('description')
                    ->label('Описание')
                    ->rows(3)
                    ->columnSpanFull(),

                FileUpload::make('image')
                    ->label('Обложка контроля')
                    ->image()
                    ->imageEditor()
                    ->directory('controls')
                    ->visibility('public')
                    ->maxSize(10240)
                    ->columnSpanFull(),
            ]);
    }

    private static function structureStatsSection(): Section
    {
        return Section::make('Статистика')
            ->schema([
                Grid::make([
                    'default' => 2,
                    'md' => 3,
                    'xl' => 6,
                ])->schema([
                    Placeholder::make('rooms_count')
                        ->label('Комнат')
                        ->content(fn (callable $get): string => (string) self::countRooms($get('main'))),

                    Placeholder::make('optional_rooms_count')
                        ->label('Необяз. комнат')
                        ->content(fn (callable $get): string => (string) self::countOptionalRooms($get('main'))),

                    Placeholder::make('questions_count')
                        ->label('Вопросов')
                        ->content(fn (callable $get): string => (string) self::countQuestions($get('main'))),

                    Placeholder::make('required_questions_count')
                        ->label('Обязательных')
                        ->content(fn (callable $get): string => (string) self::countRequiredQuestions($get('main'))),

                    Placeholder::make('critical_questions_count')
                        ->label('Критических')
                        ->content(fn (callable $get): string => (string) self::countCriticalQuestions($get('main'))),

                    Placeholder::make('max_points')
                        ->label('Макс. баллов')
                        ->content(fn (callable $get): string => (string) self::sumMaxPoints($get('main'))),
                ]),
            ])
            ->collapsible();
    }

    private static function roomsSection(): Section
    {
        return Section::make('Комнаты и вопросы')
            ->schema([
                Repeater::make('main')
                    ->label('Комнаты')
                    ->addActionLabel('Добавить комнату')
                    ->defaultItems(1)
                    ->reorderable()
                    ->collapsible()
                    ->collapsed()
                    ->cloneable()
                    ->itemLabel(function (?array $state): string {
                        $state ??= [];

                        $title = trim((string) ($state['title'] ?? ''));
                        $title = $title !== '' ? $title : 'Комната без названия';
                        $count = is_array($state['items'] ?? null) ? count($state['items']) : 0;
                        $optional = (bool) ($state['is_optional'] ?? false);

                        return '🏠 ' . $title . ' • ' . $count . ' вопр.' . ($optional ? ' • необяз.' : '');
                    })
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                        ])->schema([
                            Textarea::make('title')
                                ->label('Название комнаты')
                                ->rows(2)
                                ->required()
                                ->maxLength(255),

                            Textarea::make('description')
                                ->label('Описание')
                                ->rows(2),

                            FileUpload::make('room_image')
                                ->label('Фото комнаты')
                                ->image()
                                ->imageEditor()
                                ->directory('controls/rooms')
                                ->visibility('public')
                                ->maxSize(10240)
                                ->columnSpanFull(),

                            Toggle::make('is_optional')
                                ->label('Необязательная комната')
                                ->default(false)
                                ->helperText('Если включить, все вопросы комнаты можно пропустить')
                                ->columnSpanFull(),
                        ]),

                        Repeater::make('items')
                            ->label('Вопросы')
                            ->addActionLabel('Добавить вопрос')
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->collapsed()
                            ->cloneable()
                            ->itemLabel(function (?array $state): string {
                                $state ??= [];

                                $question = trim((string) ($state['question'] ?? ''));
                                $question = $question !== '' ? Str::limit($question, 60) : 'Вопрос без текста';

                                $type = match ($state['answer_type'] ?? 'options') {
                                    'text' => 'текст',
                                    'both' => 'оба',
                                    default => 'варианты',
                                };

                                $optional = (bool) ($state['is_optional'] ?? false);
                                $critical = (bool) ($state['is_critical'] ?? false);

                                return '❓ ' . $question
                                    . ' • ' . $type
                                    . ($optional ? ' • необяз.' : '')
                                    . ($critical ? ' • критич.' : '');
                            })
                            ->schema(self::questionSchema())
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->extraItemActions([
                        Action::make('typical_questions')
                            ->label('Типовые вопросы')
                            ->icon(Heroicon::Sparkles)
                            ->disabled(),
                    ]),
            ]);
    }

    private static function questionSchema(): array
    {
        return [
            Grid::make([
                'default' => 1,
                'md' => 3,
            ])->schema([
                Textarea::make('question')
                    ->label('Вопрос')
                    ->rows(2)
                    ->required()
                    ->columnSpan([
                        'default' => 1,
                        'md' => 2,
                    ]),

                Select::make('answer_type')
                    ->label('Тип ответа')
                    ->options([
                        'options' => 'Варианты ответов',
                        'text' => 'Текстовый ответ',
                        'both' => 'Варианты + текст',
                    ])
                    ->default('options')
                    ->required()
                    ->live(),

                FileUpload::make('question_image')
                    ->label('Картинка вопроса')
                    ->image()
                    ->imageEditor()
                    ->directory('controls/questions')
                    ->visibility('public')
                    ->maxSize(10240)
                    ->columnSpanFull(),
            ]),

            Toggle::make('is_optional')
                ->label('Необязательный вопрос')
                ->default(false)
                ->helperText('Если включить, вопрос можно пропустить')
                ->columnSpanFull(),

            Toggle::make('is_critical')
                ->label('Критический вопрос')
                ->default(false)
                ->helperText('Если выбран отрицательный ответ, итог не сможет попасть в зелёную зону')
                ->columnSpanFull(),

            Repeater::make('answer_options_scored')
                ->label('Варианты ответов + баллы')
                ->visible(fn (callable $get): bool => in_array($get('answer_type'), ['options', 'both'], true))
                ->addActionLabel('Добавить вариант')
                ->defaultItems(2)
                ->reorderable()
                ->collapsible()
                ->collapsed()
                ->cloneable()
                ->itemLabel(function (?array $state): string {
                    $state ??= [];

                    $label = trim((string) ($state['label'] ?? ''));
                    $label = $label !== '' ? $label : 'Вариант';
                    $points = (int) ($state['points'] ?? 0);
                    $positive = (bool) ($state['is_positive'] ?? false);

                    return '• ' . $label . ' • ' . $points . ' б.' . ($positive ? ' • полож.' : ' • отриц.');
                })
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 4,
                    ])->schema([
                        TextInput::make('label')
                            ->label('Вариант')
                            ->required()
                            ->columnSpan([
                                'default' => 1,
                                'md' => 2,
                            ]),

                        TextInput::make('points')
                            ->label('Баллы')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        Toggle::make('is_positive')
                            ->label('Положительный')
                            ->default(false),
                    ]),
                ])
                ->columnSpanFull(),

            TextInput::make('custom_answer_label')
                ->label('Подпись для текстового поля')
                ->default('Другое (укажите)')
                ->visible(fn (callable $get): bool => $get('answer_type') === 'both')
                ->columnSpanFull(),
        ];
    }

    public static function normalizeMain(?array $main): array
    {
        return is_array($main) ? array_values($main) : [];
    }

    public static function normalizeMainForSave(?array $main): array
    {
        $rooms = self::normalizeMain($main);

        foreach ($rooms as $roomIndex => &$room) {
            $room['title'] = trim((string) ($room['title'] ?? ''));
            $room['description'] = trim((string) ($room['description'] ?? ''));
            $room['is_optional'] = (bool) ($room['is_optional'] ?? false);

            $items = is_array($room['items'] ?? null) ? array_values($room['items']) : [];

            foreach ($items as $questionIndex => &$question) {
                $question['question'] = trim((string) ($question['question'] ?? ''));
                $question['answer_type'] = (string) ($question['answer_type'] ?? 'options');
                $question['is_optional'] = (bool) ($question['is_optional'] ?? false);
                $question['is_critical'] = (bool) ($question['is_critical'] ?? false);
                $question['custom_answer_label'] = trim((string) ($question['custom_answer_label'] ?? 'Другое (укажите)'));

                $options = is_array($question['answer_options_scored'] ?? null)
                    ? array_values($question['answer_options_scored'])
                    : [];

                foreach ($options as $optIndex => &$opt) {
                    $label = trim((string) ($opt['label'] ?? ''));
                    $points = (int) ($opt['points'] ?? 0);
                    $existingValue = trim((string) ($opt['value'] ?? ''));
                    $isPositive = (bool) ($opt['is_positive'] ?? false);

                    $opt = [
                        'value' => $existingValue !== ''
                            ? $existingValue
                            : 'q' . $roomIndex . '_' . $questionIndex . '_opt_' . $optIndex . '_' . Str::random(6),
                        'label' => $label,
                        'points' => $points,
                        'is_positive' => $isPositive,
                    ];
                }

                unset($opt);

                if ($question['answer_type'] === 'text') {
                    $question['answer_options_scored'] = [];
                } else {
                    $question['answer_options_scored'] = $options;
                }

                if ($question['answer_type'] !== 'both') {
                    unset($question['custom_answer_label']);
                }
            }

            unset($question);

            $room['items'] = $items;
        }

        unset($room);

        return array_values($rooms);
    }

    private static function countRooms(?array $main): int
    {
        return count(self::normalizeMain($main));
    }

    private static function countOptionalRooms(?array $main): int
    {
        $count = 0;

        foreach (self::normalizeMain($main) as $room) {
            if ((bool) ($room['is_optional'] ?? false)) {
                $count++;
            }
        }

        return $count;
    }

    private static function countQuestions(?array $main): int
    {
        $count = 0;

        foreach (self::normalizeMain($main) as $room) {
            $items = $room['items'] ?? [];

            if (is_array($items)) {
                $count += count($items);
            }
        }

        return $count;
    }

    private static function countRequiredQuestions(?array $main): int
    {
        $count = 0;

        foreach (self::normalizeMain($main) as $room) {
            $roomOptional = (bool) ($room['is_optional'] ?? false);
            $items = $room['items'] ?? [];

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $question) {
                if ($roomOptional) {
                    continue;
                }

                if (! (bool) ($question['is_optional'] ?? false)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function countCriticalQuestions(?array $main): int
    {
        $count = 0;

        foreach (self::normalizeMain($main) as $room) {
            $items = $room['items'] ?? [];

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $question) {
                if ((bool) ($question['is_critical'] ?? false)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function sumMaxPoints(?array $main): int
    {
        $sum = 0;

        foreach (self::normalizeMain($main) as $room) {
            $items = $room['items'] ?? [];

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $question) {
                $options = $question['answer_options_scored'] ?? [];

                if (! is_array($options) || $options === []) {
                    continue;
                }

                $max = 0;

                foreach ($options as $option) {
                    $points = (int) ($option['points'] ?? 0);
                    $max = max($max, $points);
                }

                $sum += $max;
            }
        }

        return $sum;
    }
}