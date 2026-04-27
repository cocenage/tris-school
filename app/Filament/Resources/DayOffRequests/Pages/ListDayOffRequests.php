<?php

namespace App\Filament\Resources\DayOffRequests\Pages;

use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDayOffRequests extends ListRecords
{
    protected static string $resource = DayOffRequestResource::class;

    public function getDefaultActiveTab(): string | int | null
    {
        return 'pending';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('На рассмотрении')
                ->icon('heroicon-m-clock')
                ->badge(fn (): int => $this->countByStatus('pending'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'pending')),

            'partially_approved' => Tab::make('Частично')
                ->icon('heroicon-m-adjustments-horizontal')
                ->badge(fn (): int => $this->countByStatus('partially_approved'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'partially_approved')),

            'approved' => Tab::make('Одобрено')
                ->icon('heroicon-m-check-circle')
                ->badge(fn (): int => $this->countByStatus('approved'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'approved')),

            'rejected' => Tab::make('Отклонено')
                ->icon('heroicon-m-x-circle')
                ->badge(fn (): int => $this->countByStatus('rejected'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'rejected')),

            'all' => Tab::make('Все')
                ->icon('heroicon-m-circle-stack')
                ->badge(fn (): int => DayOffRequestResource::getModel()::query()->count()),
        ];
    }

    protected function countByStatus(string $status): int
    {
        return DayOffRequestResource::getModel()::query()
            ->where('status', $status)
            ->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать заявку'),
        ];
    }
}