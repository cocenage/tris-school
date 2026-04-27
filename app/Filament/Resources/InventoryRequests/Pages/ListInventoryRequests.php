<?php

namespace App\Filament\Resources\InventoryRequests\Pages;

use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInventoryRequests extends ListRecords
{
    protected static string $resource = InventoryRequestResource::class;

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

            'partially_issued' => Tab::make('Частично')
                ->icon('heroicon-m-adjustments-horizontal')
                ->badge(fn (): int => $this->countByStatus('partially_issued'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'partially_issued')),

            'issued' => Tab::make('Выдано')
                ->icon('heroicon-m-check-circle')
                ->badge(fn (): int => $this->countByStatus('issued'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'issued')),

            'cancelled' => Tab::make('Не выдано')
                ->icon('heroicon-m-x-circle')
                ->badge(fn (): int => $this->countByStatus('cancelled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'cancelled')),

            'all' => Tab::make('Все')
                ->icon('heroicon-m-circle-stack')
                ->badge(fn (): int => InventoryRequestResource::getModel()::query()->count()),
        ];
    }

    protected function countByStatus(string $status): int
    {
        return InventoryRequestResource::getModel()::query()
            ->where('status', $status)
            ->count();
    }
}