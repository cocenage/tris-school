<?php

namespace App\Filament\Resources\DayOffRequests\Schemas;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DayOffRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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

                Select::make('status')
                    ->label('Статус заявки')
                    ->options([
                        'pending' => 'На рассмотрении',
                        'approved' => 'Одобрено',
                        'rejected' => 'Отклонено',
                    ])
                    ->default('pending')
                    ->required()
                    ->visibleOn('create'),

                Textarea::make('reason')
                    ->label('Причина')
                    ->rows(4)
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->disabledOn('edit'),

                Textarea::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->rows(4)
                    ->columnSpanFull()
                    ->visibleOn('create'),

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
                            ->required(),
                    ])
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
            ]);
    }
}