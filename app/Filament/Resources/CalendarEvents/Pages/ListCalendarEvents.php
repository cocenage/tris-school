<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCalendarEvents extends ListRecords
{
    protected static string $resource = CalendarEventResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Активные')
                ->icon('heroicon-m-check-circle')
                ->badge(fn(): int => CalendarEventResource::getModel()::query()
                    ->where('is_active', true)
                    ->count())
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('is_active', true)),

            'workflow' => Tab::make('Рабочие')
                ->icon('heroicon-m-briefcase')
                ->badge(fn(): int => $this->countByType(CalendarEvent::TYPE_WORKFLOW))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('type', CalendarEvent::TYPE_WORKFLOW)),

            'finance' => Tab::make('Финансы')
                ->icon('heroicon-m-banknotes')
                ->badge(fn(): int => $this->countByType(CalendarEvent::TYPE_FINANCE))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('type', CalendarEvent::TYPE_FINANCE)),

            'holiday' => Tab::make('Праздники')
                ->icon('heroicon-m-sparkles')
                ->badge(fn(): int => $this->countByType(CalendarEvent::TYPE_HOLIDAY))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('type', CalendarEvent::TYPE_HOLIDAY)),
                    
            'peak' => Tab::make('Пики')
                ->icon('heroicon-m-fire')
                ->badge(fn(): int => $this->countByType(CalendarEvent::TYPE_PEAK))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('type', CalendarEvent::TYPE_PEAK)),

            'vacation' => Tab::make('Отпуска')
                ->icon('heroicon-m-sun')
                ->badge(fn(): int => $this->countByType(CalendarEvent::TYPE_VACATION))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('type', CalendarEvent::TYPE_VACATION)),

            'strike' => Tab::make('Забастовки')
                ->icon('heroicon-m-exclamation-triangle')
                ->badge(fn(): int => $this->countByType(CalendarEvent::TYPE_STRIKE))
                ->modifyQueryUsing(fn(Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where('type', CalendarEvent::TYPE_STRIKE)),

            'inactive' => Tab::make('Скрытые')
                ->icon('heroicon-m-eye-slash')
                ->badge(fn(): int => CalendarEventResource::getModel()::query()
                    ->where('is_active', false)
                    ->count())
                ->modifyQueryUsing(fn(Builder $query): Builder => $query->where('is_active', false)),

            'all' => Tab::make('Все')
                ->icon('heroicon-m-circle-stack')
                ->badge(fn(): int => CalendarEventResource::getModel()::query()->count()),
        ];
    }

    protected function countByType(string $type): int
    {
        return CalendarEventResource::getModel()::query()
            ->where('is_active', true)
            ->where('type', $type)
            ->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать событие'),
        ];
    }
}