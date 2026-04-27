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
                ->badge(fn (): int => InventoryRequestResource::getModel()::query()
                    ->where('status', 'pending')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'pending')),

            'partially_issued' => Tab::make('Частично')
                ->icon('heroicon-m-adjustments-horizontal')
                ->badge(fn (): int => InventoryRequestResource::getModel()::query()
                    ->where('status', 'partially_issued')
                    ->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'partially_issued')),

            'issued' => Tab::make('Выдано')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'issued')),

            'cancelled' => Tab::make('Не выдано')
                ->icon('heroicon-m-x-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'cancelled')),

            'all' => Tab::make('Все')
                ->icon('heroicon-m-circle-stack'),
        ];
    }
}