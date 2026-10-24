<?php

use App\Models\Apartment;
use App\Models\Coaching;
use App\Models\ControlResponse;
use App\Models\User;
use App\Services\ProfileActivityTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public string $activityPeriod = 'all';

    public string $activityFilter = 'all';

    public string $workPeriod = 'all';

    public function mount(): void
    {
        abort_unless(Auth::user()?->role === 'admin', 403);
    }

    public function getDataProperty(): array
    {
        abort_unless(Auth::user()?->role === 'admin', 403);
        $cleaners = User::query()->where('is_active', true)->whereIn('role', ['cleaner', 'supervisor'])->get();
        $from = match ($this->activityPeriod) {
            '7' => now()->subDays(7)->toDateString(),
            '30' => now()->subDays(30)->toDateString(),
            default => null,
        };
        $stats = app(ProfileActivityTimeline::class)->statisticsForCleaners($cleaners->modelKeys(), $from);
        $search = mb_strtolower(trim($this->search));
        $apartmentEmployees = collect();
        if ($search !== '') {
            $apartmentIds = Apartment::query()->get(['id', 'name'])->filter(fn ($apartment) => str_contains(mb_strtolower((string) $apartment->name), $search))->modelKeys();
            $apartmentEmployees = ControlResponse::query()->whereIn('apartment_id', $apartmentIds)
                ->when($from, fn ($q) => $q->whereRaw('date(COALESCE(inspection_date, cleaning_date, sent_at, created_at)) >= ?', [$from]))->pluck('cleaner_id')
                ->merge(Coaching::query()->whereIn('apartment_id', $apartmentIds)->when($from, fn ($q) => $q->whereRaw('date(COALESCE(inspection_date, created_at)) >= ?', [$from]))->pluck('user_id'));
        }
        $cleaners = $cleaners->filter(function ($cleaner) use ($stats, $search, $apartmentEmployees) {
            $stat = $stats->get($cleaner->id);
            $count = (int) ($stat?->controls_count ?? 0) + (int) ($stat?->coachings_count ?? 0);
            if (($this->activityFilter === 'has_items' && $count === 0) || ($this->activityFilter === 'no_items' && $count > 0)) {
                return false;
            }
            if ($this->workPeriod !== 'all') {
                if (! $cleaner->work_started_at) {
                    return false;
                }
                $months = $cleaner->work_started_at->diffInMonths(now());
                if (($this->workPeriod === '1' && $months >= 1) || ($this->workPeriod === '2' && ($months < 1 || $months >= 2)) || ($this->workPeriod === '3' && ($months < 2 || $months >= 3)) || ($this->workPeriod === '3_plus' && $months < 3)) {
                    return false;
                }
            }

            return $search === '' || str_contains(mb_strtolower((string) $cleaner->name), $search) || $apartmentEmployees->contains($cleaner->id);
        })->sort(function ($a, $b) use ($stats) {
            $aDate = $stats->get($a->id)?->last_activity_at ?? $a->work_started_at;
            $bDate = $stats->get($b->id)?->last_activity_at ?? $b->work_started_at;

            return ($bDate ? Carbon::parse($bDate)->timestamp : 0) <=> ($aDate ? Carbon::parse($aDate)->timestamp : 0) ?: strcmp((string) $a->name, (string) $b->name);
        })->values();

        return compact('cleaners', 'stats');
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]"><a href="{{ route('page-profile') }}" aria-label="Назад" class="p-2"><x-heroicon-o-arrow-left class="size-5" /></a><span role="heading" aria-level="1" class="text-[18px] leading-none">История сотрудников</span></div>
</x-slot:header>
<div class="h-full overflow-y-auto bg-white px-5 pb-10 pt-5">
    <div class="mx-auto max-w-2xl space-y-5">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Поиск сотрудника или квартиры" aria-label="Поиск сотрудника или квартиры" class="min-h-12 w-full rounded-full border-0 bg-[#E2E2E2] px-5">
        <div class="grid grid-cols-3 gap-2">
            <select wire:model.live="activityPeriod" aria-label="Период активности" class="min-w-0 rounded-full border-0 bg-[#E2E2E2] p-3 text-sm"><option value="all">Всё время</option><option value="30">30 дней</option><option value="7">7 дней</option></select>
            <select wire:model.live="activityFilter" aria-label="Наличие записей" class="min-w-0 rounded-full border-0 bg-[#E2E2E2] p-3 text-sm"><option value="all">Все</option><option value="has_items">С записями</option><option value="no_items">Без записей</option></select>
            <select wire:model.live="workPeriod" aria-label="Стаж" class="min-w-0 rounded-full border-0 bg-[#E2E2E2] p-3 text-sm"><option value="all">Стаж</option><option value="1">До 1 мес.</option><option value="2">1–2 мес.</option><option value="3">2–3 мес.</option><option value="3_plus">3+ мес.</option></select>
        </div>
        @php($data = $this->data)
        @forelse($data['cleaners'] as $cleaner)
            @php($stat = $data['stats']->get($cleaner->id))
            <a wire:key="employee-{{ $cleaner->id }}" href="{{ route('page-profile.employee-history', $cleaner) }}" class="block rounded-[30px] border border-[#EAEAEA] p-4">
                <div class="font-semibold">{{ $cleaner->name }}</div>
                <p class="mt-2 text-sm text-black/50">Начало работы: {{ $cleaner->work_started_at?->format('d.m.Y') ?? '—' }}</p>
                <p class="mt-2 text-sm">Контроли: {{ (int) ($stat?->controls_count ?? 0) }} · Коучинги: {{ (int) ($stat?->coachings_count ?? 0) }}</p>
                <span class="mt-3 inline-block text-sm text-[#213259]">Открыть историю →</span>
            </a>
        @empty
            <p class="py-8 text-center text-black/50">Ничего не найдено</p>
        @endforelse
    </div>
</div>