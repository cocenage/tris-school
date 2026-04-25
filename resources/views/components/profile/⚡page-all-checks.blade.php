<?php


use App\Models\ControlResponse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
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
        $cleaners = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderByRaw('work_started_at IS NULL, work_started_at DESC')
            ->orderBy('name')
            ->get();

        $responses = ControlResponse::query()
            ->with(['control', 'user', 'supervisor'])
            ->get()
            ->map(function ($item) {
                $item->item_type = 'control';
                $item->sort_date = $item->cleaning_date ?? $item->inspection_date ?? $item->created_at;
                return $item;
            });

      

       $allItems = $responses
    ->sortByDesc(fn ($item) => $item->sort_date ?? $item->created_at)
    ->values();

        $periodFrom = match ($this->activityPeriod) {
            '7' => now()->subDays(7)->startOfDay(),
            '30' => now()->subDays(30)->startOfDay(),
            default => null,
        };

        $groupedItems = collect();
        $stats = collect();

        foreach ($cleaners as $cleaner) {
            $items = $allItems
                ->where('user_id', $cleaner->id)
                ->filter(function ($item) use ($periodFrom) {
                    if (! $periodFrom) {
                        return true;
                    }

                    $date = $item->sort_date ?? $item->created_at;

                    return $date && Carbon::parse($date)->gte($periodFrom);
                })
                ->values();

            $groupedItems[$cleaner->id] = $items;

            $lastItem = $items->first();

            $stats[$cleaner->id] = [
                'controls_count' => $items->where('item_type', 'control')->count(),
                'coachings_count' => $items->where('item_type', 'coaching')->count(),
                'last_activity_at' => $lastItem?->sort_date ?? $cleaner->work_started_at,
            ];
        }

        $cleaners = $cleaners
            ->filter(function ($cleaner) use ($groupedItems) {
                $items = $groupedItems[$cleaner->id] ?? collect();

                if ($this->activityFilter === 'has_items' && $items->isEmpty()) {
                    return false;
                }

                if ($this->activityFilter === 'no_items' && $items->isNotEmpty()) {
                    return false;
                }

                if ($this->workPeriod !== 'all') {
                    if (! $cleaner->work_started_at) {
                        return false;
                    }

                    $monthsWorked = Carbon::parse($cleaner->work_started_at)->diffInMonths(now());

                    if ($this->workPeriod === '1' && $monthsWorked >= 1) {
                        return false;
                    }

                    if ($this->workPeriod === '2' && ($monthsWorked < 1 || $monthsWorked >= 2)) {
                        return false;
                    }

                    if ($this->workPeriod === '3' && ($monthsWorked < 2 || $monthsWorked >= 3)) {
                        return false;
                    }

                    if ($this->workPeriod === '3_plus' && $monthsWorked < 3) {
                        return false;
                    }
                }

                $search = mb_strtolower(trim($this->search));

                if ($search === '') {
                    return true;
                }

                $hasUserMatch = str_contains(mb_strtolower((string) $cleaner->name), $search);

                $hasApartmentMatch = $items->contains(function ($item) use ($search) {
                    $apartmentName = ($item->item_type ?? null) === 'coaching'
                        ? (string) ($item->apartment->name ?? '')
                        : (string) ($item->apartment ?? '');

                    return $apartmentName !== ''
                        && str_contains(mb_strtolower($apartmentName), $search);
                });

                return $hasUserMatch || $hasApartmentMatch;
            })
            ->sort(function ($a, $b) use ($stats) {
                $aDate = $stats[$a->id]['last_activity_at'] ?? $a->work_started_at;
                $bDate = $stats[$b->id]['last_activity_at'] ?? $b->work_started_at;

                $aTs = $aDate ? Carbon::parse($aDate)->timestamp : 0;
                $bTs = $bDate ? Carbon::parse($bDate)->timestamp : 0;

                return $bTs <=> $aTs ?: strcmp((string) $a->name, (string) $b->name);
            })
            ->values();

        return [
            'cleaners' => $cleaners,
            'groupedItems' => $groupedItems,
            'stats' => $stats,
        ];
    }

    protected function percent($item): int
    {
        if (($item->item_type ?? null) !== 'control') {
            return 0;
        }

        $total = (int) ($item->total_points ?? 0);
        $max = (int) ($item->max_points ?? 0);

        return $max > 0 ? max(0, min(100, (int) round(($total / $max) * 100))) : 0;
    }
};
?>

<x-slot:header>
    <div class="w-full h-[70px] flex items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2]" />
        </button>

        <span class="text-[18px] leading-none">
            Все контроли
        </span>

        <div class="h-[36px] w-[36px]"></div>
    </div>
</x-slot:header>

@php
    $data = $this->data;
    $cleaners = $data['cleaners'];
    $groupedItems = $data['groupedItems'];
    $stats = $data['stats'];
@endphp

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="min-h-full rounded-t-[38px] bg-white">
            <div class="p-[20px] pb-[40px]">
                <div class="mb-[20px] space-y-[12px]">
                    <div class="flex h-[48px] items-center gap-[10px] rounded-full bg-[#E2E2E2] px-[18px]">
                        <x-heroicon-o-magnifying-glass class="h-[20px] w-[20px] shrink-0 text-black/45 stroke-[2]" />

                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Поиск сотрудника или квартиры"
                            class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 text-[15px] font-medium text-[#111111] placeholder:text-black/40 outline-none focus:ring-0"
                        >
                    </div>

                    <div class="grid grid-cols-3 gap-[8px]">
                        <select wire:model.live="activityPeriod" class="h-[42px] rounded-full border-0 bg-[#E2E2E2] px-[12px] text-[13px] focus:ring-0">
                            <option value="all">Всё время</option>
                            <option value="30">30 дней</option>
                            <option value="7">7 дней</option>
                        </select>

                        <select wire:model.live="activityFilter" class="h-[42px] rounded-full border-0 bg-[#E2E2E2] px-[12px] text-[13px] focus:ring-0">
                            <option value="all">Все</option>
                            <option value="has_items">С контролями</option>
                            <option value="no_items">Без контролей</option>
                        </select>

                        <select wire:model.live="workPeriod" class="h-[42px] rounded-full border-0 bg-[#E2E2E2] px-[12px] text-[13px] focus:ring-0">
                            <option value="all">Стаж</option>
                            <option value="1">До 1 мес.</option>
                            <option value="2">1–2 мес.</option>
                            <option value="3">2–3 мес.</option>
                            <option value="3_plus">3+ мес.</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-[14px]">
                    @forelse($cleaners as $cleaner)
                        @php
                            $items = ($groupedItems[$cleaner->id] ?? collect())
                                ->sortByDesc(fn ($r) => optional($r->sort_date ?? $r->created_at)->timestamp ?? 0)
                                ->values();

                            $controlsCount = $stats[$cleaner->id]['controls_count'] ?? 0;
                            $coachingsCount = $stats[$cleaner->id]['coachings_count'] ?? 0;

                            $monthsWorked = $cleaner->work_started_at
                                ? Carbon::parse($cleaner->work_started_at)->diffInMonths(now())
                                : null;

                            $borderColor = match (true) {
                                $monthsWorked !== null && $monthsWorked < 1 => '#86EFAC',
                                $monthsWorked !== null && $monthsWorked < 2 => '#7DD3FC',
                                $monthsWorked !== null && $monthsWorked < 3 => '#93C5FD',
                                default => '#EAEAEA',
                            };
                        @endphp

                        <div
                            x-data="{ open: false }"
                            class="overflow-hidden bg-white"
                            style="border: 1px solid {{ $borderColor }}; border-radius: 30px;"
                        >
                            <button
                                type="button"
                                @click="open = !open"
                                class="w-full px-[16px] py-[16px] text-left active:bg-black/[0.02]"
                            >
                                <div class="flex items-center justify-between gap-[14px]">
                                    <div class="min-w-0">
                                        <div class="truncate text-[17px] font-semibold text-[#111111]">
                                            {{ $cleaner->name }}
                                        </div>

                                        <div class="mt-[4px] text-[12px] text-black/45">
                                            Начало работы:
                                            <span class="font-semibold text-black/70">
                                                {{ optional($cleaner->work_started_at)->format('d.m.Y') ?? '—' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-[8px] shrink-0">
                                        <span class="rounded-full bg-[#F8F8F8] px-[10px] py-[6px] text-[12px] font-semibold text-black/50">
                                            {{ $controlsCount }} / {{ $coachingsCount }}
                                        </span>

                                        <span class="flex h-[38px] w-[38px] items-center justify-center rounded-full bg-[#F8F8F8]">
                                            <x-heroicon-o-chevron-down
                                                class="h-[18px] w-[18px] text-black/45 transition"
                                                x-bind:class="{ 'rotate-180': open }"
                                            />
                                        </span>
                                    </div>
                                </div>
                            </button>

                            <div x-show="open" x-collapse class="px-[12px] pb-[12px]" style="display:none;">
                                @if($items->isNotEmpty())
                                    <div class="space-y-[10px]">
                                        @foreach($items as $item)
                                            @php
                                                $isCoaching = ($item->item_type ?? null) === 'coaching';

                                                $date = $item->inspection_date?->format('d.m.Y')
                                                    ?? $item->cleaning_date?->format('d.m.Y')
                                                    ?? $item->created_at?->format('d.m.Y');

                                                if ($isCoaching) {
                                                    $url = route('page.coaching', $item->id);
                                                    $label = 'Коучинг';
                                                    $apartment = $item->apartment?->name ?? '—';
                                                    $circleColor = '#0EA5E9';
                                                    $note = trim((string) ($item->reason ?? ''));
                                                } else {
                                                    $url = route('page-profile.checks.show', $item);
                                                    $label = 'Контроль';
                                                    $apartment = $item->apartment ?: '—';
                                                    $pct = $this->percent($item);
                                                    $circleColor = match (true) {
                                                        $pct >= 80 => '#27AE60',
                                                        $pct >= 50 => '#2D6494',
                                                        $pct > 0 => '#D92D20',
                                                        default => '#7D7D7D',
                                                    };
                                                    $note = trim((string) ($item->supervisor_comment ?? ''));
                                                }
                                            @endphp

                                            <div x-data="{ noteOpen: false }">
                                                <a
                                                    href="{{ $url }}"
                                                    class="block rounded-[24px] border border-[#EAEAEA] bg-[#FAFAFA] px-[14px] py-[13px] active:scale-[0.99] transition"
                                                >
                                                    <div class="flex items-center justify-between gap-[12px]">
                                                        <div class="min-w-0">
                                                            <div class="flex items-center gap-[8px]">
                                                                <span class="text-[14px] font-semibold text-[#111111]">
                                                                    {{ $label }}
                                                                </span>

                                                                <span
                                                                    class="h-[14px] w-[14px] rounded-full"
                                                                    style="background: {{ $circleColor }};"
                                                                ></span>

                                                                <span class="text-[13px] text-black/45">
                                                                    {{ $date }}
                                                                </span>
                                                            </div>

                                                            <div class="mt-[4px] truncate text-[13px] text-black/45">
                                                                {{ $apartment }}
                                                            </div>
                                                        </div>

                                                        <button
                                                            type="button"
                                                            @click.prevent.stop="noteOpen = !noteOpen"
                                                            class="flex h-[38px] w-[38px] shrink-0 items-center justify-center rounded-full bg-white"
                                                        >
                                                            <x-heroicon-o-chat-bubble-left-right class="h-[19px] w-[19px] text-black/45" />
                                                        </button>
                                                    </div>
                                                </a>

                                                <div x-show="noteOpen" x-collapse class="mt-[8px]" style="display:none;">
                                                    <div class="rounded-[22px] bg-[#F8F8F8] px-[15px] py-[13px] text-[14px] leading-[1.4] text-black/60">
                                                        {{ $note !== '' ? $note : 'Комментарий не указан' }}
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="rounded-[24px] bg-[#F8F8F8] px-[16px] py-[18px] text-[14px] text-black/45">
                                        У этого человека пока нет контролей и коучингов
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="rounded-[28px] bg-[#F8F8F8] px-[18px] py-[20px] text-center text-[15px] text-black/45">
                            Ничего не найдено
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>