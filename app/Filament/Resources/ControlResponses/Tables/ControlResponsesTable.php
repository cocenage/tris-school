<?php

namespace App\Filament\Resources\ControlResponses\Tables;

use App\Models\ControlResponse;
use App\Models\RewardProgram;
use App\Models\RewardProgramPointEvent;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ControlResponsesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cleaner.name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('result_zone_label')
                    ->label('Цвет')
                    ->badge()
                    ->color(fn (ControlResponse $record): string => $record->result_zone_color),

                TextColumn::make('errors_count')
                    ->label('Ошибки')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('penalty_points')
                    ->label('Штраф')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('result_zone_reason')
                    ->label('Причина')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('inspection_date')
                    ->label('Дата контроля')
                    ->date('d.m.Y')
                    ->sortable(),

                TextColumn::make('apartment.name')
                    ->label('Квартира')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supervisor.name')
                    ->label('Кто проверил')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('control.name')
                    ->label('Форма')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sent_at')
                    ->label('Отправлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result_zone')
                    ->label('Цвет')
                    ->options([
                        'green' => 'Зелёная зона',
                        'yellow' => 'Жёлтая зона',
                        'red' => 'Красная зона',
                    ]),

                SelectFilter::make('cleaner_id')
                    ->label('Клинер')
                    ->relationship('cleaner', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('supervisor_id')
                    ->label('Супервайзер')
                    ->relationship('supervisor', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('apartment_id')
                    ->label('Квартира')
                    ->relationship('apartment', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->recordUrl(fn (ControlResponse $record) => route(
                'filament.admin.resources.control-responses.view',
                $record
            ))
            ->actions([
                ViewAction::make(),

                Action::make('addRewardPoints')
                    ->label('Баллы')
                    ->icon('heroicon-o-gift')
                    ->color('success')
                    ->schema([
                        Select::make('reward_program_id')
                            ->label('Программа')
                            ->options(fn () => RewardProgram::query()
                                ->where('is_active', true)
                                ->orderByDesc('starts_at')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(),

                        TextInput::make('points')
                            ->label('Баллы')
                            ->numeric()
                            ->required()
                            ->default(fn (ControlResponse $record) => match ($record->result_zone) {
                                'green' => 1,
                                'yellow' => 0,
                                'red' => 0,
                                default => 0,
                            }),

                        TextInput::make('reason')
                            ->label('Причина')
                            ->required()
                            ->default(fn (ControlResponse $record) => match ($record->result_zone) {
                                'green' => 'Зелёный контроль качества',
                                'yellow' => 'Контроль качества с замечаниями',
                                'red' => 'Красная зона на контроле',
                                default => 'Контроль качества',
                            })
                            ->maxLength(255),

                        DatePicker::make('event_date')
                            ->label('Дата')
                            ->default(now())
                            ->required(),
                    ])
                   ->action(function (ControlResponse $record, array $data): void {
    $exists = RewardProgramPointEvent::query()
        ->where('reward_program_id', $data['reward_program_id'])
        ->where('user_id', $record->cleaner_id)
        ->where('source_type', ControlResponse::class)
        ->where('source_id', $record->id)
        ->exists();

    if ($exists) {
        \Filament\Notifications\Notification::make()
            ->title('Баллы уже начислены')
            ->body('По этому контролю уже есть начисление в выбранной программе.')
            ->warning()
            ->send();

        return;
    }

    RewardProgramPointEvent::create([
        'reward_program_id' => $data['reward_program_id'],
        'user_id' => $record->cleaner_id,
        'created_by' => auth()->id(),
        'points' => (int) $data['points'],
        'reason' => $data['reason'],
        'event_date' => $data['event_date'],
        'source_type' => ControlResponse::class,
        'source_id' => $record->id,
    ]);

    \Filament\Notifications\Notification::make()
        ->title('Баллы начислены')
        ->success()
        ->send();
})
                    ->visible(fn (ControlResponse $record): bool => filled($record->cleaner_id)),
            ]);
    }
}