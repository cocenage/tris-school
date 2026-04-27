<?php

namespace App\Filament\Resources\VacationRequests\Pages;

use App\Filament\Resources\VacationRequests\VacationRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListVacationRequests extends ListRecords
{
    protected static string $resource = VacationRequestResource::class;

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
                ->badge(fn (): int => VacationRequestResource::getModel()::query()->count()),
        ];
    }

    protected function countByStatus(string $status): int
    {
        return VacationRequestResource::getModel()::query()
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