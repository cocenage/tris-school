<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperationalEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class TelegramShiftSituationSource
{
    public function __construct(private readonly TelegramDistrictRouteRegistry $districts) {}

    /** Only explicit current ledger evidence within the district's Rome calendar day qualifies. */
    public function events(?string $date, ?string $district): Collection
    {
        $route = $this->districts->sourceRoutes()->firstWhere('key', $district);
        if ($route === null || blank($date)) {
            return collect();
        }
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date, 'Europe/Rome');
            if ($day->toDateString() !== $date) {
                return collect();
            }
        } catch (Throwable) {
            return collect();
        }

        return TelegramOperationalEvent::query()
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', $route['chat_id']))
            ->whereHas('evidence', fn ($query) => $query->where('is_current_revision', true)
                ->where('occurred_at', '>=', $day->copy()->utc())
                ->where('occurred_at', '<', $day->copy()->addDay()->utc()))
            ->with(['evidence' => fn ($query) => $query->where('is_current_revision', true)
                ->where('occurred_at', '<', $day->copy()->addDay()->utc())
                ->orderByDesc('occurred_at')->orderByDesc('id')])
            ->orderBy('id')->get();
    }
}
