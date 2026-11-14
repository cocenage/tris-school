<?php

namespace App\Services\Telegram;

use App\Models\ShiftDuty;
use Carbon\Carbon;

class TelegramShiftPreviewBuilder
{
    public function __construct(
        private readonly TelegramDistrictRouteRegistry $districts,
        private readonly TelegramEveningIntelligenceBuilder $evening,
    ) {}

    public function build(Carbon $date): array
    {
        $day = $date->copy()->setTimezone('Europe/Rome')->toDateString();
        $duties = ShiftDuty::query()->with('user')->whereDate('date', $day)->get()->keyBy('district_key');
        $districts = $this->districts->sourceRoutes()->map(function (array $route) use ($day, $duties): array {
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
}
