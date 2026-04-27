<?php

namespace App\Filament\Resources\VacationRequests\Schemas;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VacationRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Заявка на отпуск')
                    ->description('Основная информация по заявке.')
                    ->schema([
                        Select::make('user_id')
                            ->label('Сотрудник')
                            ->options(
                                User::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all()
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visibleOn('create'),

                        Placeholder::make('user_name')
                            ->label('Сотрудник')
                            ->content(fn ($record) => $record?->user?->name ?? '—')
                            ->visibleOn('edit'),

                        Select::make('status')
                            ->label('Статус заявки')
                            ->options([
                                'pending' => 'На рассмотрении',
                                'approved' => 'Одобрено',
                                'rejected' => 'Отклонено',
                                'partially_approved' => 'Частично одобрено',
                            ])
                            ->default('pending')
                            ->required()
                            ->native(false),

                        Placeholder::make('created_at')
                            ->label('Создано')
                            ->content(fn ($record) => $record?->created_at?->format('d.m.Y H:i') ?? '—')
                            ->visibleOn('edit'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Причина и комментарий')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Причина сотрудника')
                            ->rows(4)
                            ->required()
                            ->maxLength(500)
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->columnSpanFull(),

                        Textarea::make('admin_comment')
                            ->label('Комментарий администратора')
                            ->rows(4)
                            ->placeholder('Например: согласовано частично')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Даты')
                    ->description('При создании можно добавить одну или несколько дат отпуска.')
                    ->schema([
                        Repeater::make('days')
                            ->label('Даты')
                            ->relationship('days')
                            ->schema([
                                DatePicker::make('date')
                                    ->label('Дата')
                                    ->native(false)
                                    ->required(),

                                Hidden::make('user_id')
                                    ->dehydrated(true)
                                    ->default(null),

                                Select::make('status')
                                    ->label('Статус даты')
                                    ->options([
                                        'pending' => 'На рассмотрении',
                                        'approved' => 'Одобрено',
                                        'rejected' => 'Отклонено',
                                    ])
                                    ->default('pending')
                                    ->required()
                                    ->native(false),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->addActionLabel('Добавить дату')
                            ->reorderable(false)
                            ->collapsible()
                            ->cloneable()
                            ->columnSpanFull()
                            ->visibleOn('create')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data, callable $get): array {
                                $data['user_id'] = $get('user_id');
                                $data['status'] = $data['status'] ?? $get('status') ?? 'pending';

                                return $data;
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data, callable $get): array {
                                $data['user_id'] = $get('user_id');
                                $data['status'] = $data['status'] ?? $get('status') ?? 'pending';

                                return $data;
                            }),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}