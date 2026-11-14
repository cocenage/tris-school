<?php

namespace App\Services\Scheduling;

use App\Models\Apartment;
use App\Models\User;
use App\Services\Apartments\ApartmentAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CleaningSchedule
{
    public function __construct(private readonly ApartmentAccessService $apartments) {}

    /** @return list<array{id: string, time: string, apartment: string|null, url: string|null}>|null */
    public function today(User $employee): ?array
    {
        $url = config('services.cleaning_schedule.url');
        $token = config('services.cleaning_schedule.token');
        if (! $employee->isApproved() || ! $employee->is_active || ! is_string($url) || ! Str::startsWith($url, 'https://') || blank($token)) {
            return null;
        }

        $timezone = config('app.timezone');
        $date = CarbonImmutable::now($timezone)->toDateString();

        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->connectTimeout(2)->timeout(4)->get($url, [
                    'employee_id' => $employee->id,
                    'date' => $date,
                    'timezone' => $timezone,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            return null;
        }

        if (($payload['date'] ?? null) !== $date || ($payload['timezone'] ?? null) !== $timezone || ! is_array($payload['assignments'] ?? null) || ! array_is_list($payload['assignments'])) {
            return null;
        }

        $validation = Validator::make($payload, [
            'assignments' => ['array', 'max:100'],
            'assignments.*.id' => ['required', 'string', 'max:80', 'distinct'],
            'assignments.*.employee_id' => ['required', 'integer', 'min:1'],
            'assignments.*.apartment_code' => ['required', 'string', 'max:80'],
            'assignments.*.starts_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'assignments.*.status' => ['required', 'in:scheduled,cancelled'],
        ]);
        if ($validation->fails()) {
            return null;
        }

        $assignments = collect($payload['assignments'])
            ->filter(fn (array $item): bool => (int) $item['employee_id'] === $employee->id && $item['status'] === 'scheduled')
            ->map(function (array $item) use ($timezone): array {
                $item['start'] = CarbonImmutable::parse($item['starts_at'])->setTimezone($timezone);

                return $item;
            })
            ->filter(fn (array $item): bool => $item['start']->toDateString() === $date)
            ->sortBy('start');

        if ($assignments->isEmpty()) {
            return [];
        }

        $uniqueApartmentCodes = Apartment::query()->where('is_active', true)
            ->whereIn('code', $assignments->pluck('apartment_code')->unique())
            ->select('code')->groupBy('code')->havingRaw('COUNT(*) = 1');
        $apartments = $this->apartments->visibleQuery($employee)->where('is_active', true)
            ->whereIn('code', $uniqueApartmentCodes)->get(['id', 'name', 'code'])->keyBy('code');

        return $assignments->map(function (array $item) use ($apartments): array {
            $apartment = $apartments->get($item['apartment_code']);

            return [
                'id' => $item['id'],
                'time' => $item['start']->format('H:i'),
                'apartment' => $apartment?->name,
                'url' => $apartment ? route('page-apartments.show', $apartment) : null,
            ];
        })->values()->all();
    }
}
