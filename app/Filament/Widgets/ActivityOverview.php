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
            ->where('causer_type', User::class)
            ->whereNotNull('causer_id')
            ->distinct('causer_id')
            ->count('causer_id');

        $totalUsers = User::query()->count();

        $requestsToday = Activity::query()
            ->whereDate('created_at', today())
            ->whereIn('event', self::requestEvents())
            ->count();

        $controlsToday = Activity::query()
            ->whereDate('created_at', today())
            ->where('event', 'control_completed')
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
                ->description('Формы, обращения и заявки')
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('primary'),

            Stat::make('Контроли сегодня', $controlsToday)
                ->description('Отправленные проверки качества')
                ->descriptionIcon('heroicon-m-clipboard-document-check')
                ->color('info'),

            Stat::make('Топ действие недели', $mostPopularEvent ? self::eventLabel($mostPopularEvent->event) : 'Нет данных')
                ->description($mostPopularEvent ? "{$mostPopularEvent->total} раз" : 'Пока нет активности')
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
            ->map(fn (int $daysAgo): int => Activity::query()
                ->whereDate('created_at', now()->subDays($daysAgo))
                ->count())
            ->toArray();
    }

    protected static function requestEvents(): array
    {
        return [
            'salary_question_created',
            'feedback_suggestion_created',
            'schedule_question_created',
            'day_off_request_created',
            'vacation_request_created',
            'inventory_request_created',
        ];
    }

    protected static function eventLabel(?string $event): string
    {
        return match ($event) {
            'salary_question_created' => 'Вопрос по зарплате',
            'feedback_suggestion_created' => 'Обратная связь',
            'schedule_question_created' => 'Вопрос по графику',
            'day_off_request_created' => 'Заявка на выходной',
            'vacation_request_created' => 'Заявка на отпуск',
            'inventory_request_created' => 'Заявка на инвентарь',
            'control_completed' => 'Контроль пройден',
            default => $event ?: 'Без события',
        };
    }
}