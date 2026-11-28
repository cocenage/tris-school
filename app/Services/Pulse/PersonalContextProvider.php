<?php

namespace App\Services\Pulse;

use App\Models\ControlResponse;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class PersonalContextProvider
{
    public function __construct(private EmployeeWorkCalendarService $calendar) {}

    /** @return list<PulseFact> */
    public function facts(User $user, CarbonImmutable $now): array
    {
        $facts = [];
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();
        // Personal assignment is narrower than Task::visibleFor (room membership / management).
        $tasks = Task::query()->notClosed()->where(function (Builder $query) use ($user): void {
            $query->where('assigned_to', $user->id)
                ->orWhereHas('assignees', fn (Builder $q) => $q->where('users.id', $user->id));
        });
        $overdue = (clone $tasks)->where('deadline_at', '<', $now)->count();
        if ($overdue > 0) {
            $facts[] = new PulseFact('tasks:overdue', 'overdue', 1, 'У тебя ',
                $overdue.' '.PulseComposer::plural($overdue, 'просроченная задача', 'просроченные задачи', 'просроченных задач'),
                url: route('page-tasks', [], false), icon: 'task');
        }
        $count = (clone $tasks)->where(fn (Builder $q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', $now))
            ->where(function (Builder $q) use ($today, $tomorrow): void {
                $q->where(fn (Builder $q) => $q->where('starts_at', '>=', $today)->where('starts_at', '<', $tomorrow))
                    ->orWhere(fn (Builder $q) => $q->where('deadline_at', '>=', $today)->where('deadline_at', '<', $tomorrow));
            })->count();
        if ($count > 0) {
            $facts[] = new PulseFact('tasks:today', 'tasks', 2, 'На сегодня ',
                $count.' '.PulseComposer::plural($count, 'задача', 'задачи', 'задач'),
                url: route('page-tasks', [], false), icon: 'task', expiresAt: $tomorrow);
        }

        if ($user->role !== 'admin') {
            $day = $this->calendar->forEmployee($user, $today, $today)->get($today->toDateString());
            $calendarText = match ($day['state'] ?? null) {
                'regular_day_off' => 'выходной',
                'approved_absence' => 'согласованный нерабочий день',
                'planned_work' => 'рабочий день',
                'no_work' => 'нерабочий день',
                default => null,
            };
            if ($calendarText !== null) {
                $facts[] = new PulseFact('calendar:today', 'calendar', 2, 'Сегодня у тебя ', $calendarText,
                    url: route('page-profile.calendar', [], false), icon: 'calendar', expiresAt: $tomorrow);
            }
        }

        // Review timestamps are authoritative; notification delivery does not mean acknowledged.
        // One newest final decision is sufficient for the initial personal summary.
        foreach (['day_off' => $user->dayOffRequests(), 'vacation' => $user->vacationRequests()] as $type => $query) {
            $request = $query->whereIn('status', ['approved', 'rejected', 'partially_approved'])
                ->where('reviewed_at', '>', $now->subDay())->where('reviewed_at', '<=', $now)
                ->orderByDesc('reviewed_at')->orderByDesc('id')->first();
            if ($request === null) {
                continue;
            }
            $prefix = $type === 'day_off' ? 'Твоя заявка на выходной ' : 'Твоя заявка на отпуск ';
            $accent = match ($request->status) {
                'approved' => 'одобрена',
                'partially_approved' => 'частично одобрена',
                default => 'отклонена',
            };
            $facts[] = new PulseFact('request:'.$type.':'.$request->id, 'request:'.$type, 3, $prefix, $accent,
                url: route('page-profile.applications', [], false), icon: 'request',
                expiresAt: CarbonImmutable::instance($request->reviewed_at)->addDay());
        }

        if ($user->role === 'cleaner') {
            $control = $user->controlResponsesAsCleaner()
                ->where('status', ControlResponse::STATUS_SENT)
                ->where('sent_at', '>', $now->subDay())->where('sent_at', '<=', $now)
                ->orderByDesc('sent_at')->orderByDesc('id')->first(['id', 'errors_count', 'result_zone', 'sent_at']);
            if ($control !== null) {
                $errors = $control->errors_count;
                $accent = $errors === null || $errors < 0
                    ? 'доступен'
                    : ($errors === 0 && $control->result_zone === 'green'
                        ? 'без нарушений'
                        : ($errors > 0 ? $errors.' '.PulseComposer::plural($errors, 'нарушение', 'нарушения', 'нарушений') : 'доступен'));
                $prefix = $accent === 'доступен' ? 'Результат нового контроля ' : 'Последний контроль: ';
                $facts[] = new PulseFact('control:'.$control->id, 'control', 3, $prefix, $accent,
                    url: route('page-profile.checks.result', ['controlResponse' => $control->id], false),
                    icon: 'control', expiresAt: CarbonImmutable::instance($control->sent_at)->addDay());
            }
        }

        return $facts;
    }
}
