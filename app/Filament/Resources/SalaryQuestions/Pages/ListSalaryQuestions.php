<?php

namespace App\Filament\Resources\SalaryQuestions\Pages;

use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSalaryQuestions extends ListRecords
{
    protected static string $resource = SalaryQuestionResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('На рассмотрении')
                ->icon('heroicon-m-clock')
                ->badge(fn(): int => $this->countByStatus('pending'))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', 'pending')),

            'reviewed' => Tab::make('Рассмотрено')
                ->icon('heroicon-m-eye')
                ->badge(fn(): int => $this->countByStatus('reviewed'))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', 'reviewed')),

            'closed' => Tab::make('Закрыто')
                ->icon('heroicon-m-check-circle')
                ->badge(fn(): int => $this->countByStatus('closed'))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('status', 'closed')),

            'all' => Tab::make('Все')
                ->icon('heroicon-m-circle-stack')
                ->badge(fn(): int => SalaryQuestionResource::getModel()::query()->count()),
        ];
    }

    protected function countByStatus(string $status): int
    {
        return SalaryQuestionResource::getModel()::query()
            ->where('status', $status)
            ->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать вопрос'),
        ];
    }
}