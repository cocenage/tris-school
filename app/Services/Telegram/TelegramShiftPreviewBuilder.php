<?php

namespace App\Services\Telegram;

use App\Models\ShiftDuty;
use App\Models\ShiftSituation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TelegramShiftPreviewBuilder
{
    public function __construct(
        private readonly TelegramDistrictRouteRegistry $districts,
        private readonly TelegramEveningIntelligenceBuilder $evening,
        private readonly TelegramShiftSituationSource $situations,
    ) {}

    public function build(Carbon $date): array
    {
        $day = $date->copy()->setTimezone('Europe/Rome')->toDateString();
        $duties = ShiftDuty::query()->with('user')->whereDate('date', $day)->get()->keyBy('district_key');
        $assignments = ShiftSituation::query()->with(['user', 'recorder'])->whereDate('date', $day)->get()->groupBy('district_key');
        $districts = $this->districts->sourceRoutes()->map(function (array $route) use ($day, $duties, $assignments): array {
            $duty = $duties->get($route['key']);
            $user = $duty?->user;
            $eligible = $user && $user->is_active && $user->status === 'approved'
                && in_array($user->role, ['admin', 'supervisor', 'cleaner'], true);

            return [
                'key' => $route['key'],
                'label' => $route['label'],
                'duty_status' => $duty === null ? 'unassigned' : ($eligible ? 'assigned' : 'unavailable'),
                'duty' => $eligible ? ['user_id' => $user->id, 'name' => $user->name] : null,
                'report' => $this->evening->build($day, ['district' => $route]),
                'situations' => $this->situationRows($day, $route['key'], $assignments->get($route['key'], collect())),
            ];
        })->all();

        return [
            'date' => $day,
            'timezone' => 'Europe/Rome',
            'districts' => $districts,
            'route_diagnostics' => $this->districts->diagnostics()->all(),
            'mode' => ['read_only' => true, 'telegram_actions' => 0, 'mutations' => 0],
        ];
    }

    private function situationRows(string $day, string $district, Collection $assignments): array
    {
        $events = $this->situations->events($day, $district)->keyBy('event_key');
        $assignments = $assignments->keyBy('event_key');

        return $events->keys()->merge($assignments->keys())->unique()->map(function (string $key) use ($events, $assignments): array {
            $event = $events->get($key);
            $assignment = $assignments->get($key);
            $user = $assignment?->user;
            $eligible = $user && $user->is_active && $user->status === 'approved'
                && in_array($user->role, ['admin', 'supervisor', 'cleaner'], true);

            return [
                'event_key' => $key,
                'source_status' => $event === null ? 'unavailable' : 'matched',
                'summary' => $event?->summary,
                'ledger_status' => $event?->evidence->first()?->status_after,
                'assignment_status' => $assignment === null ? 'unassigned' : ($eligible ? 'assigned' : 'unavailable'),
                'assignee' => $user ? ['user_id' => $user->id, 'name' => $user->name] : null,
                'decision' => $assignment?->decision,
                'recorded_by' => $assignment?->recorder ? ['user_id' => $assignment->recorder->id, 'name' => $assignment->recorder->name] : null,
            ];
        })->values()->all();
    }
}
