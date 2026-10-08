<?php

namespace App\Services\Telegram;

use App\Models\DayOffRequestDay;
use App\Models\VacationRequestDay;
use App\Services\Weather\MilanWeatherService;
use Carbon\CarbonImmutable;

class TelegramTomorrowSummaryBuilder
{
    public function __construct(private readonly MilanWeatherService $weather) {}

    public function build(CarbonImmutable $date): array
    {
        $date = $date->setTimezone('Europe/Rome')->startOfDay();
        $absences = [];

        foreach (DayOffRequestDay::query()
            ->with('user')
            ->whereDate('date', $date->toDateString())
            ->where('status', 'approved')
            ->whereHas('user', fn ($query) => $query->activeStaff())
            ->get() as $day) {
            $absences[$day->user_id] = ['user_id' => $day->user_id, 'name' => $day->user->name, 'reason' => 'выходной'];
        }

        foreach (VacationRequestDay::query()
            ->with('user')
            ->whereDate('date', $date->toDateString())
            ->where('status', 'approved')
            ->whereHas('user', fn ($query) => $query->activeStaff())
            ->get() as $day) {
            $absences[$day->user_id] = ['user_id' => $day->user_id, 'name' => $day->user->name, 'reason' => 'отпуск'];
        }

        $absences = collect($absences)->sortBy('name')->values()->all();

        return [
            'date' => $date->toDateString(),
            'timezone' => 'Europe/Rome',
            'absences' => $absences,
            'weather' => $this->weather->forDate($date),
        ];
    }
}
