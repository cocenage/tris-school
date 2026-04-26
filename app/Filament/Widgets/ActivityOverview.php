<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Spatie\Activitylog\Models\Activity;

class ActivityOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $todayActivities = Activity::query()
            ->whereDate('created_at', today())
            ->count();

        $weekActivities = Activity::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $activeUsersToday = Activity::query()
            ->whereDate('created_at', today())
            ->whereNotNull('causer_id')
            ->distinct('causer_id')
            ->count('causer_id');

        $totalUsers = User::query()->count();

        $requestsToday = Activity::query()
            ->whereDate('created_at', today())
            ->whereIn('event', [
                'salary_question_created',
                'day_off_request_created',
                'vacation_request_created',
                'inventory_request_created',
            ])
            ->count();

        $mostPopularEvent = Activity::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('event')
            ->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')
            ->orderByDesc('total')
            ->first();

        $activeUserIds = Activity::query()
            ->where('created_at', '>=', now()->subDays(14))
            ->where('causer_type', User::class)
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id');

        $inactiveUsers = User::query()
            ->whereNotIn('id', $activeUserIds)
            ->count();

        return [
            Stat::make('Активность сегодня', $todayActivities)
                ->description('Действий за сегодня')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('success')
                ->chart($this->dailyActivityChart()),

            Stat::make('За 7 дней', $weekActivities)
                ->description('Всего действий за неделю')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('info'),

            Stat::make('Активных сегодня', $activeUsersToday)
                ->description("из {$totalUsers} пользователей")
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),

            Stat::make('Новые заявки сегодня', $requestsToday)
                ->description('Отправленные формы и заявки')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('primary'),

            Stat::make(
                'Топ действие недели',
                $mostPopularEvent?->event ?? 'Нет данных'
            )
                ->description(
                    $mostPopularEvent
                        ? "{$mostPopularEvent->total} раз"
                        : 'Пока нет активности'
                )
                ->descriptionIcon('heroicon-m-fire')
                ->color('danger'),

            Stat::make('Неактивные 14 дней', $inactiveUsers)
                ->description('Пользователи без действий')
                ->descriptionIcon('heroicon-m-user-minus')
                ->color('gray'),
        ];
    }

    protected function dailyActivityChart(): array
    {
        return collect(range(6, 0))
            ->map(function (int $daysAgo) {
                return Activity::query()
                    ->whereDate('created_at', now()->subDays($daysAgo))
                    ->count();
            })
            ->toArray();
    }
}