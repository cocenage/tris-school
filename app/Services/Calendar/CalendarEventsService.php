<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\DayOffRequestDay;
use App\Models\User;
use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarEventsService
{
    public function getEventsForDay(Carbon $day, string $filter = 'all'): Collection
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        return $this->getExpandedEvents($start, $end, $filter)
            ->filter(function (array $event) use ($start) {
                return $start->betweenIncluded(
                    $event['start']->copy()->startOfDay(),
                    $event['end']->copy()->startOfDay(),
                );
            })
            ->sortBy([
                ['priority', 'desc'],
                ['start', 'asc'],
                ['title', 'asc'],
            ])
            ->values();
    }

    public function getExpandedEvents(Carbon $rangeStart, Carbon $rangeEnd, string $filter = 'all'): Collection
    {
        $baseEvents = CalendarEvent::query()
            ->with('user')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('start_date')
            ->get();

        $expanded = collect();

        foreach ($baseEvents as $event) {
            $expanded = $expanded->merge(
                $this->expandEventOccurrences($event, $rangeStart->copy(), $rangeEnd->copy())
            );
        }

        $users = User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNotNull('birthday')
                    ->orWhereNotNull('work_started_at');
            })
            ->get();

        foreach ($users as $user) {
            if ($user->birthday) {
                $birthday = Carbon::parse($user->birthday);

                for ($year = $rangeStart->year - 1; $year <= $rangeEnd->year + 1; $year++) {
                    $date = $this->safeDateForYear($birthday, $year);

                    if ($date && $date->betweenIncluded($rangeStart, $rangeEnd)) {
                        $expanded->push([
                            'id' => 'birthday_' . $user->id . '_' . $year,
                            'title' => "{$user->name}{$this->formatUserDip($user)} — День рождения",
                            'description' => null,
                            'short' => mb_strimwidth("🎂 {$user->name}", 0, 12, '...'),
                            'type' => 'holiday',
                            'priority' => 100,
                            'start' => $date->copy()->startOfDay(),
                            'end' => $date->copy()->startOfDay(),
                            'style' => $this->eventStyle('holiday'),
                        ]);
                    }
                }
            }

            if ($user->work_started_at) {
                $workStarted = Carbon::parse($user->work_started_at);

                for ($year = $rangeStart->year - 1; $year <= $rangeEnd->year + 1; $year++) {
                    $date = $this->safeDateForYear($workStarted, $year);

                    if ($date && $date->betweenIncluded($rangeStart, $rangeEnd)) {
                        $years = Carbon::parse($user->work_started_at)->diffInYears($date);

                        $expanded->push([
                            'id' => 'work_' . $user->id . '_' . $year,
                            'title' => "{$user->name}{$this->formatUserDip($user)} — {$years} лет в компании",
                            'description' => null,
                            'short' => mb_strimwidth("🏢 {$user->name}", 0, 12, '...'),
                            'type' => 'holiday',
                            'priority' => 90,
                            'start' => $date->copy()->startOfDay(),
                            'end' => $date->copy()->startOfDay(),
                            'style' => $this->eventStyle('holiday'),
                        ]);
                    }
                }
            }
        }

        $dayOffDays = DayOffRequestDay::query()
            ->with(['user', 'request'])
            ->whereBetween('date', [
                $rangeStart->copy()->toDateString(),
                $rangeEnd->copy()->toDateString(),
            ])
            ->whereHas('request', function ($query) {
                $query->whereIn('status', ['approved', 'partially_approved']);
            })
            ->get();

        foreach ($dayOffDays as $dayOffDay) {
            $user = $dayOffDay->user;
            $date = Carbon::parse($dayOffDay->date)->startOfDay();

            $statusLabel = match ($dayOffDay->request?->status) {
                'approved' => 'Выходной',
                'partially_approved' => 'Выходной частично одобрен',
                'rejected' => 'Выходной отклонён',
                default => 'Выходной',
            };

            $expanded->push([
                'id' => 'day_off_' . $dayOffDay->id,
                'title' => "{$user?->name}{$this->formatUserDip($user)} — {$statusLabel}",
                'description' => $dayOffDay->request?->reason,
                'short' => mb_strimwidth("🌿 " . ($user?->name ?? 'Выходной'), 0, 12, '...'),
                'type' => 'vacation',
                'priority' => 85,
                'start' => $date->copy(),
                'end' => $date->copy(),
                'style' => $this->eventStyle('vacation'),
            ]);
        }

        $vacationRequests = VacationRequest::query()
            ->with(['user', 'days'])
            ->whereIn('status', ['approved', 'partially_approved'])
            ->whereHas('days', function ($query) use ($rangeStart, $rangeEnd) {
                $query->whereBetween('date', [
                    $rangeStart->copy()->toDateString(),
                    $rangeEnd->copy()->toDateString(),
                ]);
            })
            ->get();

        foreach ($vacationRequests as $vacationRequest) {
            $user = $vacationRequest->user;

            $vacationDays = $vacationRequest->days
                ->filter(fn($day) => Carbon::parse($day->date)->betweenIncluded($rangeStart, $rangeEnd))
                ->sortBy('date')
                ->values();

            if ($vacationDays->isEmpty()) {
                continue;
            }

            $start = Carbon::parse($vacationDays->first()->date)->startOfDay();
            $end = Carbon::parse($vacationDays->last()->date)->startOfDay();

            $statusLabel = match ($vacationRequest->status) {
                'approved' => 'Отпуск',
                'partially_approved' => 'Отпуск частично одобрен',
                default => 'Отпуск',
            };

            $expanded->push([
                'id' => 'vacation_' . $vacationRequest->id . '_' . $start->format('Ymd'),
                'title' => "{$user?->name}{$this->formatUserDip($user)} — {$statusLabel}",
                'description' => $vacationRequest->reason,
                'short' => mb_strimwidth("🏖 " . ($user?->name ?? 'Отпуск'), 0, 12, '...'),
                'type' => 'vacation',
                'priority' => 80,
                'start' => $start,
                'end' => $end,
                'style' => $this->eventStyle('vacation'),
            ]);
        }

        if ($filter !== 'all') {
            $expanded = $expanded
                ->filter(fn(array $event) => $event['type'] === $filter)
                ->values();
        }

        return $expanded
            ->sortByDesc('priority')
            ->values();
    }

    protected function formatUserDip(?User $user): string
    {
        if (!$user) {
            return '';
        }

        return $user->dip ? ' • DIP' : ' • NO DIP';
    }

    protected function expandEventOccurrences(CalendarEvent $event, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $occurrences = collect();

        $start = Carbon::parse($event->start_date)->startOfDay();
        $end = $event->end_date
            ? Carbon::parse($event->end_date)->startOfDay()
            : $start->copy();

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $durationDays = $start->diffInDays($end);

        if ($event->repeat_type === 'none') {
            if ($start->lte($rangeEnd) && $end->gte($rangeStart)) {
                $occurrences->push($this->mapEventOccurrence($event, $start, $end));
            }

            return $occurrences;
        }

        if ($event->repeat_type === 'weekly') {
            $cursor = $start->copy();

            while ($cursor->lt($rangeStart)) {
                $cursor->addWeek();
            }

            $cursor->subWeek();

            while ($cursor->lte($rangeEnd)) {
                $occurrenceStart = $cursor->copy();
                $occurrenceEnd = $cursor->copy()->addDays($durationDays);

                if ($event->repeat_until && $occurrenceStart->gt(Carbon::parse($event->repeat_until)->endOfDay())) {
                    break;
                }

                if ($occurrenceStart->lte($rangeEnd) && $occurrenceEnd->gte($rangeStart)) {
                    $occurrences->push($this->mapEventOccurrence($event, $occurrenceStart, $occurrenceEnd));
                }

                $cursor->addWeek();
            }

            return $occurrences;
        }

        if ($event->repeat_type === 'monthly') {
            $cursor = $start->copy()->startOfMonth();

            while ($cursor->lt($rangeStart->copy()->startOfMonth())) {
                $cursor->addMonth();
            }

            $cursor->subMonth();

            while ($cursor->lte($rangeEnd)) {
                $daysInMonth = $cursor->copy()->endOfMonth()->day;
                $day = min($start->day, $daysInMonth);

                $occurrenceStart = $cursor->copy()->day($day)->startOfDay();

                if ($event->repeat_until && $occurrenceStart->gt(Carbon::parse($event->repeat_until)->endOfDay())) {
                    break;
                }

                $occurrenceEnd = $occurrenceStart->copy()->addDays($durationDays);

                if ($occurrenceStart->lte($rangeEnd) && $occurrenceEnd->gte($rangeStart)) {
                    $occurrences->push($this->mapEventOccurrence($event, $occurrenceStart, $occurrenceEnd));
                }

                $cursor->addMonth();
            }

            return $occurrences;
        }

        if ($event->repeat_type === 'yearly') {
            $startYear = $rangeStart->year - 1;
            $endYear = $rangeEnd->year + 1;

            for ($year = $startYear; $year <= $endYear; $year++) {
                $occurrenceStart = $this->safeDateForYear($start, $year);

                if (!$occurrenceStart) {
                    continue;
                }

                if ($event->repeat_until && $occurrenceStart->gt(Carbon::parse($event->repeat_until)->endOfDay())) {
                    continue;
                }

                $occurrenceEnd = $occurrenceStart->copy()->addDays($durationDays);

                if ($occurrenceStart->lte($rangeEnd) && $occurrenceEnd->gte($rangeStart)) {
                    $occurrences->push($this->mapEventOccurrence($event, $occurrenceStart, $occurrenceEnd));
                }
            }
        }

        return $occurrences;
    }

    protected function safeDateForYear(Carbon $source, int $year): ?Carbon
    {
        try {
            return Carbon::create($year, $source->month, $source->day, 0, 0, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function mapEventOccurrence(CalendarEvent $event, Carbon $start, Carbon $end): array
    {
        $user = $event->user;

        $title = $event->title;

        if ($user) {
            $title .= ' — ' . $user->name . $this->formatUserDip($user);
        }

        return [
            'id' => $event->id . '_' . $start->format('Ymd'),
            'title' => $title,
            'description' => $event->description,
            'short' => mb_strimwidth($title, 0, 12, '...'),
            'type' => $event->type,
            'priority' => (int) ($event->priority ?? 0),
            'start' => $start,
            'end' => $end,
            'style' => $this->eventStyle($event->type),
        ];
    }

    protected function eventStyle(string $type): string
    {
        return match ($type) {
            'workflow' => 'background:#CFE8FF;color:#111111;',
            'finance' => 'background:#CBEED9;color:#111111;',
            'holiday' => 'background:#F3B8B8;color:#111111;',
            'peak' => 'background:#F3E69C;color:#111111;',
            'vacation' => 'background:#CDBEFF;color:#111111;',
            'strike' => 'background:#F4C9A8;color:#111111;',
            default => 'background:#E9E9E9;color:#111111;',
        };
    }
}