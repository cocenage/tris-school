<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\EmployeeWorkCalendarReport;
use App\Filament\Pages\TelegramDeliveryReport;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Apartments\ApartmentResource;
use App\Filament\Resources\ControlResponses\ControlResponseResource;
use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use App\Filament\Resources\FeedbackSuggestions\FeedbackSuggestionResource;
use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use App\Filament\Resources\ScheduleQuestions\ScheduleQuestionResource;
use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VacationRequests\VacationRequestResource;
use App\Models\ControlResponse;
use App\Models\User;
use App\Services\Authorization\SupervisorPanelAccess;
use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
use App\Services\Telegram\TelegramDeliveryCenter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

class WorkspaceOverview extends Widget
{
    protected string $view = 'filament.widgets.workspace-overview';
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        $panel = Filament::getCurrentPanel();

        return $user instanceof User && $user->isApproved() && $panel !== null
            && match ($panel->getId()) {
                'admin' => $user->canAccessPanel($panel),
                'education' => app(SupervisorPanelAccess::class)->canUseSupervisorPanel($user),
                default => false,
            };
    }

    protected function getViewData(): array
    {
        abort_unless(static::canView(), 403);
        $supervisor = Filament::getCurrentPanel()->getId() === 'education';
        $today = CarbonImmutable::now('Europe/Rome');
        $attention = $metrics = $links = $activity = [];
        $requestCount = 0;
        $requestSources = 0;

        $requests = [
            [DayOffRequestResource::class, 'Выходные', 'heroicon-o-calendar-days'],
            [VacationRequestResource::class, 'Отпуска', 'heroicon-o-calendar'],
        ];
        if (! $supervisor) {
            $requests = [...$requests,
                [InventoryRequestResource::class, 'Инвентарь', 'heroicon-o-cube'],
                [ScheduleQuestionResource::class, 'Вопросы по графику', 'heroicon-o-clock'],
                [SalaryQuestionResource::class, 'Вопросы по зарплате', 'heroicon-o-banknotes'],
                [FeedbackSuggestionResource::class, 'Обратная связь', 'heroicon-o-chat-bubble-left-right'],
            ];
        }

        foreach ($requests as [$resource, $label, $icon]) {
            if (! $resource::canViewAny()) {
                continue;
            }
            $query = $resource::getEloquentQuery();
            $requestSources++;
            $requestCount += (clone $query)->whereDate('created_at', $today->toDateString())->count();
            $count = (clone $query)->where('status', 'pending')->count();
            if ($count > 0) {
                $attention[] = self::item($label, $count, $icon, self::filteredUrl($resource, 'status', 'pending'), 'Ожидают рассмотрения');
            }
            $links[] = self::link($label, $icon, $resource::getUrl());
        }
        if ($requestSources > 0) {
            $metrics[] = self::item('Заявок сегодня', $requestCount, 'heroicon-o-inbox', null, $supervisor ? 'Выходные и отпуска' : 'По доступным видам заявок');
        }

        $staffResource = $supervisor ? SupervisorEmployeeResource::class : UserResource::class;
        if ($staffResource::canViewAny()) {
            $staffQuery = $staffResource::getEloquentQuery()->reorder()->where('status', 'approved')->where('is_active', true)->whereIn('role', ['cleaner', 'supervisor']);
            $metrics[] = self::item('Активных сотрудников', $staffQuery->count(), 'heroicon-o-users', $staffResource::getUrl(), 'Допущены к работе');
            array_unshift($links, self::link('Команда', 'heroicon-o-users', $staffResource::getUrl()));
        }
        if (! $supervisor && UserResource::canViewAny()) {
            $pending = UserResource::getEloquentQuery()->reorder()->where('status', 'pending')->count();
            if ($pending > 0) {
                array_unshift($attention, self::item('Запросы доступа', $pending, 'heroicon-o-user-plus', self::filteredUrl(UserResource::class, 'status', 'pending'), 'Новые сотрудники'));
            }
        }
        if (ControlResponseResource::canViewAny()) {
            $query = ControlResponseResource::getEloquentQuery()
                ->where('status', ControlResponse::STATUS_SENT)
                ->whereDate('sent_at', $today->toDateString());
            $metrics[] = self::item('Контролей сегодня', (clone $query)->count(), 'heroicon-o-clipboard-document-check', ControlResponseResource::getUrl());
            $red = (clone $query)->where('result_zone', 'red')->count();
            if ($red > 0) {
                $attention[] = self::item('Красная зона', $red, 'heroicon-o-exclamation-triangle', self::filteredUrl(ControlResponseResource::class, 'result_zone', 'red'), 'Контроли, отправленные сегодня', true);
            }
            $links[] = self::link('Контроли', 'heroicon-o-clipboard-document-check', ControlResponseResource::getUrl());
        }
        if (EmployeeWorkCalendarReport::canAccess()) {
            try {
                $report = app(EmployeeWorkCalendarStatisticsService::class)->forPeriod($today, $today);
                $totals = $report['totals'];
                $url = EmployeeWorkCalendarReport::getUrl();
                $metrics[] = self::item('По плану работают', $totals['planned_workdays'], 'heroicon-o-calendar-days', $url, 'По рабочему графику');
                $metrics[] = self::item('Одобренные отсутствия', $totals['approved_absence_days'], 'heroicon-o-calendar', $url);
                if ($totals['unknown_schedule_days'] > 0) {
                    $attention[] = self::item('График не указан', $totals['unknown_schedule_days'], 'heroicon-o-calendar-days', $url, 'Сотрудников на сегодня');
                }
            } catch (\Throwable $exception) {
                report($exception);
                $attention[] = self::item('График недоступен', '—', 'heroicon-o-calendar-days', EmployeeWorkCalendarReport::getUrl(), 'Не удалось получить данные', true);
            }
            $links[] = self::link('Рабочий календарь', 'heroicon-o-calendar-days', EmployeeWorkCalendarReport::getUrl());
        }
        if (ApartmentResource::canViewAny()) {
            $links[] = self::link('Квартиры', 'heroicon-o-building-office-2', ApartmentResource::getUrl());
        }
        if (Route::has('page-home.instructions')) {
            $links[] = self::link('Инструкции', 'heroicon-o-book-open', route('page-home.instructions'));
        }
        if (! $supervisor && Route::has('knowledge.roadmap')) {
            $links[] = self::link('TRIS Knowledge', 'heroicon-o-book-open', route('knowledge.roadmap'));
        }
        if (! $supervisor && TelegramDeliveryReport::canAccess()) {
            $url = TelegramDeliveryReport::getUrl();
            try {
                $delivery = app(TelegramDeliveryCenter::class)->snapshot();
                $count = $delivery['counts']['failed'] + $delivery['counts']['missed'] + $delivery['counts']['uncertain'];
                if ($count > 0 || ! $delivery['journal_available'] || $delivery['source_error'] || $delivery['counts']['unmonitored'] > 0) {
                    $attention[] = self::item('Доставка Telegram', $count > 0 ? $count : '—', 'heroicon-o-paper-airplane', $url, 'Ошибки, пропуски или неполный учёт', true);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $attention[] = self::item('Доставка Telegram', '—', 'heroicon-o-paper-airplane', $url, 'Источник недоступен', true);
            }
            $links[] = self::link('Центр доставки', 'heroicon-o-paper-airplane', $url);
        }

        $activityUrl = null;
        if (! $supervisor && ActivityResource::canViewAny()) {
            $activityUrl = ActivityResource::getUrl();
            $activity = ActivityResource::getEloquentQuery()->with('causer')->reorder()->latest('id')->limit(5)->get()
                ->map(fn (Activity $record) => [
                    'label' => self::eventLabel($record->event),
                    'description' => $record->causer instanceof User ? $record->causer->name : 'Система',
                    'time' => $record->created_at?->setTimezone('Europe/Rome')->format('d.m H:i'),
                    'url' => ActivityResource::canView($record) ? ActivityResource::getUrl('view', ['record' => $record]) : $activityUrl,
                ])->all();
        }

        return compact('supervisor', 'today', 'attention', 'metrics', 'links', 'activity', 'activityUrl');
    }

    private static function filteredUrl(string $resource, string $filter, string $value): string
    {
        return $resource::getUrl('index', ['tableFilters' => [$filter => ['value' => $value]]]);
    }

    private static function item(string $label, int|string $value, string $icon, ?string $url = null, ?string $description = null, bool $critical = false): array
    {
        return compact('label', 'value', 'icon', 'url', 'description', 'critical');
    }

    private static function link(string $label, string $icon, string $url): array
    {
        return compact('label', 'icon', 'url');
    }

    private static function eventLabel(?string $event): string
    {
        return match ($event) {
            'day_off_request_created' => 'Заявка на выходной',
            'vacation_request_created' => 'Заявка на отпуск',
            'inventory_request_created' => 'Заявка на инвентарь',
            'salary_question_created' => 'Вопрос по зарплате',
            'schedule_question_created' => 'Вопрос по графику',
            'feedback_suggestion_created' => 'Обратная связь',
            'control_completed' => 'Контроль завершён',
            'created' => 'Создание записи',
            'updated' => 'Изменение записи',
            'deleted' => 'Удаление записи',
            default => $event ?: 'Действие',
        };
    }
}
