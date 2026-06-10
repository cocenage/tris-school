<?php

namespace App\Filament\Resources\RewardPrograms\RelationManagers;

use App\Models\ControlResponse;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PointEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'pointEvents';

    protected static ?string $title = 'Начисления баллов';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Сотрудник')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('points')
                    ->label('Баллы')
                    ->numeric()
                    ->required(),

                TextInput::make('reason')
                    ->label('Причина')
                    ->required()
                    ->maxLength(255),

                DatePicker::make('event_date')
                    ->label('Дата')
                    ->default(now())
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('points')
                    ->label('Баллы')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('source_label')
                    ->label('Источник')
                    ->state(function ($record): string {
                        if ($record->source_type === ControlResponse::class && $record->source_id) {
                            return 'Контроль #' . $record->source_id;
                        }

                        return 'Вручную';
                    }),

                TextColumn::make('event_date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Добавил')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Начислить баллы')
                    ->mutateDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('event_date', 'desc');
    }
}