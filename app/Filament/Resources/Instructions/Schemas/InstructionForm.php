<?php

namespace App\Filament\Resources\Instructions\Schemas;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class InstructionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Инструкция')
                ->tabs([
                    Tab::make('Основное')
                        ->schema([
                            Section::make('Данные статьи')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))),

                                        TextInput::make('slug')
                                            ->label('Slug')
                                            ->required()
                                            ->unique(ignoreRecord: true),
                                    ]),

                                    Textarea::make('short_description')
                                        ->label('Краткое описание')
                                        ->rows(3)
                                        ->columnSpanFull(),

                                    Grid::make(2)->schema([
                                        Select::make('instruction_category_id')
                                            ->label('Категория')
                                            ->relationship('category', 'title')
                                            ->searchable()
                                            ->preload(),

                                        Select::make('status')
                                            ->label('Статус')
                                            ->options([
                                                'draft' => 'Черновик',
                                                'published' => 'Опубликована',
                                                'archived' => 'Архив',
                                            ])
                                            ->default('draft')
                                            ->required(),
                                    ]),

                                    FileUpload::make('cover_image')
                                        ->label('Обложка')
                                        ->image()
                                            ->disk('public')
                                        ->directory('instructions/covers')
                                        ->columnSpanFull(),

                                    Grid::make(3)->schema([
                                        TextInput::make('emoji')
                                            ->label('Emoji')
                                            ->maxLength(10),

                                        TextInput::make('icon')
                                            ->label('Иконка')
                                            ->placeholder('heroicon-o-document-text'),

                                        ColorPicker::make('color')
                                            ->label('Цвет'),
                                    ]),

                                    Grid::make(3)->schema([
                                        Toggle::make('is_featured')
                                            ->label('Закрепить'),

                                        Toggle::make('is_public')
                                            ->label('Публичная')
                                            ->default(true),

                                        TextInput::make('sort_order')
                                            ->label('Сортировка')
                                            ->numeric()
                                            ->default(0),
                                    ]),

                                    DateTimePicker::make('published_at')
                                        ->label('Дата публикации'),
                                ]),
                        ]),

                    Tab::make('Контент')
                        ->schema([
                            Section::make('Конструктор статьи')
                                ->description('Собери инструкцию из готовых блоков.')
                                ->schema([
                                    Builder::make('blocks')
                                        ->label('Блоки')
                                        ->columnSpanFull()
                                        ->reorderable()
                                        ->collapsible()
                                        ->cloneable()
                                        ->blocks([
                                            Block::make('hero')
                                                ->label('Вступление')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок'),

                                                    Textarea::make('description')
                                                        ->label('Описание')
                                                        ->rows(3),

                                                    TextInput::make('badge')
                                                        ->label('Бейдж')
                                                        ->placeholder('Например: 3 минуты'),
                                                ]),

                                            Block::make('text')
                                                ->label('Текст')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок'),

                                                    RichEditor::make('content')
                                                        ->label('Текст')
                                                        ->columnSpanFull(),
                                                ]),

                                            Block::make('warning')
                                                ->label('Важный блок')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок')
                                                        ->default('Важно'),

                                                    Textarea::make('content')
                                                        ->label('Текст')
                                                        ->rows(4),

                                                    Select::make('type')
                                                        ->label('Тип')
                                                        ->options([
                                                            'info' => 'Информация',
                                                            'warning' => 'Предупреждение',
                                                            'danger' => 'Критично',
                                                            'success' => 'Успешно',
                                                        ])
                                                        ->default('warning'),
                                                ]),

                                            Block::make('steps')
                                                ->label('Шаги')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок')
                                                        ->default('Пошаговая инструкция'),

                                                    Repeater::make('items')
                                                        ->label('Шаги')
                                                        ->schema([
                                                            TextInput::make('title')
                                                                ->label('Название шага')
                                                                ->required(),

                                                            Textarea::make('text')
                                                                ->label('Описание')
                                                                ->rows(3),
                                                        ])
                                                        ->addActionLabel('Добавить шаг')
                                                        ->reorderable()
                                                        ->columnSpanFull(),
                                                ]),

                                            Block::make('checklist')
                                                ->label('Чеклист')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок')
                                                        ->default('Проверьте перед отправкой'),

                                                    Repeater::make('items')
                                                        ->label('Пункты')
                                                        ->schema([
                                                            TextInput::make('text')
                                                                ->label('Пункт')
                                                                ->required(),
                                                        ])
                                                        ->addActionLabel('Добавить пункт')
                                                        ->reorderable()
                                                        ->columnSpanFull(),
                                                ]),

                                            Block::make('faq')
                                                ->label('FAQ')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок')
                                                        ->default('Частые вопросы'),

                                                    Repeater::make('items')
                                                        ->label('Вопросы')
                                                        ->schema([
                                                            TextInput::make('question')
                                                                ->label('Вопрос')
                                                                ->required(),

                                                            Textarea::make('answer')
                                                                ->label('Ответ')
                                                                ->rows(3)
                                                                ->required(),
                                                        ])
                                                        ->addActionLabel('Добавить вопрос')
                                                        ->reorderable()
                                                        ->columnSpanFull(),
                                                ]),

                                            Block::make('tips')
                                                ->label('Советы')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок')
                                                        ->default('Полезные советы'),

                                                    Repeater::make('items')
                                                        ->label('Советы')
                                                        ->schema([
                                                            TextInput::make('text')
                                                                ->label('Совет')
                                                                ->required(),
                                                        ])
                                                        ->addActionLabel('Добавить совет')
                                                        ->reorderable()
                                                        ->columnSpanFull(),
                                                ]),

                                            Block::make('image')
                                                ->label('Изображение')
                                                ->schema([
                                                    FileUpload::make('image')
                                                        ->label('Изображение')
                                                        ->image()
                                                            ->disk('public')
                                                        ->directory('instructions/content'),

                                                    TextInput::make('caption')
                                                        ->label('Подпись'),
                                                ]),

                                            Block::make('video')
                                                ->label('Видео')
                                                ->schema([
                                                    TextInput::make('url')
                                                        ->label('Ссылка на видео')
                                                        ->url(),

                                                    TextInput::make('title')
                                                        ->label('Название видео'),
                                                ]),

                                            Block::make('links')
                                                ->label('Ссылки')
                                                ->schema([
                                                    TextInput::make('title')
                                                        ->label('Заголовок')
                                                        ->default('Смотрите также'),

                                                    Repeater::make('items')
                                                        ->label('Ссылки')
                                                        ->schema([
                                                            TextInput::make('label')
                                                                ->label('Название')
                                                                ->required(),

                                                            TextInput::make('url')
                                                                ->label('Ссылка')
                                                                ->required(),
                                                        ])
                                                        ->addActionLabel('Добавить ссылку')
                                                        ->reorderable()
                                                        ->columnSpanFull(),
                                                ]),
                                        ]),
                                ]),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }
}