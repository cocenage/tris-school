<?php

namespace App\Filament\Resources\Apartments\RelationManagers;

use App\Models\ApartmentUserAccess;
use App\Models\User;
use App\Services\Apartments\ApartmentAccessService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccessRelationManager extends RelationManager
{
    protected static string $relationship = 'accessGrants';

    protected static ?string $title = 'Доступ сотрудников';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('Сотрудник')
                ->relationship('user', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable(['name', 'email', 'telegram_username'])
                ->preload()
                ->required(),

            DateTimePicker::make('expires_at')
                ->label('Доступ до (необязательно)')
                ->timezone(config('app.timezone', 'Europe/Rome'))
                ->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Сотрудник')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ApartmentUserAccess $record): string => $record->user?->telegram_username ? '@' . $record->user->telegram_username : ($record->user?->email ?? '—')),
                TextColumn::make('user.role')->label('Роль')->badge(),
                TextColumn::make('expires_at')
                    ->label('Срок')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Бессрочно')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->state(fn (ApartmentUserAccess $record): string => $record->isActive() ? 'Активен' : 'Истёк')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Активен' ? 'success' : 'gray'),
                TextColumn::make('grantedBy.name')->label('Кем выдан')->placeholder('—'),
                TextColumn::make('created_at')->label('Дата выдачи')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->headerActions([
                $this->grantAction(),
                $this->grantManyAction(),
            ])
            ->actions([
                EditAction::make()->label('Изменить')->visible(fn (): bool => $this->canManage()),
                DeleteAction::make()->label('Отозвать')->requiresConfirmation()->visible(fn (): bool => $this->canManage()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Отозвать доступ')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => $this->canManage()),
                ]),
            ]);
    }

    private function grantAction(): Action
    {
        return Action::make('grant')
            ->label('Выдать доступ')
            ->form($this->grantFields(false))
            ->visible(fn (): bool => $this->canManage())
            ->action(function (array $data): void {
                app(ApartmentAccessService::class)->grant(auth()->user(), $this->getOwnerRecord(), (int) $data['user_id'], $data['expires_at'] ?? null);
            });
    }

    private function grantManyAction(): Action
    {
        return Action::make('grantMany')
            ->label('Выдать нескольким')
            ->form($this->grantFields(true))
            ->visible(fn (): bool => $this->canManage())
            ->action(function (array $data): void {
                $service = app(ApartmentAccessService::class);
                foreach ($data['user_ids'] ?? [] as $userId) {
                    $service->grant(auth()->user(), $this->getOwnerRecord(), (int) $userId, $data['expires_at'] ?? null);
                }
            });
    }

    private function grantFields(bool $multiple): array
    {
        $userSelect = Select::make($multiple ? 'user_ids' : 'user_id')
            ->label('Сотрудники')
            ->options(fn (): array => User::query()
                ->where('is_active', true)
                ->whereIn('role', ['cleaner', 'supervisor'])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'telegram_username'])
                ->mapWithKeys(fn (User $user): array => [
                    $user->id => trim($user->name . ($user->telegram_username ? ' (@' . $user->telegram_username . ')' : '') . ($user->email ? ' — ' . $user->email : '')),
                ])
                ->all())
            ->searchable()
            ->required();

        if ($multiple) {
            $userSelect->multiple();
        }

        return [
            $userSelect,
            DateTimePicker::make('expires_at')
                ->label('Доступ до (необязательно)')
                ->timezone(config('app.timezone', 'Europe/Rome'))
                ->native(false),
        ];
    }

    private function canManage(): bool
    {
        return app(ApartmentAccessService::class)->canManage(auth()->user());
    }
}
