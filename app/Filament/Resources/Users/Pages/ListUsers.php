<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getDefaultActiveTab(): string | int | null
    {
        return 'employees';
    }

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),

            Action::make('back')
                   ->icon('heroicon-m-arrow-left')
                ->label('Назад')
                ->color('gray')
                ->outlined()
                ->url(UserResource::getUrl('index')),
        ];
    }

    public function getTabs(): array
    {
        return [
            'employees' => Tab::make('Сотрудники')
                ->icon('heroicon-m-users')
                ->badge(fn (): int => UserResource::getModel()::query()
                    ->where('is_active', true)
                    ->where('status', 'approved')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('status', 'approved')),

            'pending_access' => Tab::make('Ожидают доступ')
                ->icon('heroicon-m-clock')
                ->badge(fn (): int => UserResource::getModel()::query()
                    ->where('status', 'pending')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', 'pending')),

            'rejected_access' => Tab::make('Без доступа')
                ->icon('heroicon-m-no-symbol')
                ->badge(fn (): int => UserResource::getModel()::query()
                    ->where('status', 'rejected')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', 'rejected')),

            'inactive' => Tab::make('Уволенные')
                ->icon('heroicon-m-user-minus')
                ->badge(fn (): int => UserResource::getModel()::query()
                    ->where('is_active', false)
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('is_active', false)),

            'all' => Tab::make('Все')
                ->icon('heroicon-m-circle-stack')
                ->badge(fn (): int => UserResource::getModel()::query()->count()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать пользователя'),
        ];
    }
}