<?php

use App\Models\DayOffRequest;
use App\Models\InventoryRequest;
use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component
{
    public function getRequestsProperty(): Collection
    {
        $dayOffs = DayOffRequest::query()
            ->with(['days'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'day_off';
                $request->request_type_label = 'Выходной';
                $request->request_type_icon = '🌿';

                return $request;
            });

        $vacations = VacationRequest::query()
            ->with(['days'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'vacation';
                $request->request_type_label = 'Отпуск';
                $request->request_type_icon = '🏖';

                return $request;
            });

        $inventories = InventoryRequest::query()
            ->with(['items'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'inventory';
                $request->request_type_label = 'Инвентарь';
                $request->request_type_icon = '📦';

                return $request;
            });

        return $dayOffs
            ->concat($vacations)
            ->concat($inventories)
            ->sortByDesc(fn ($request) => $request->created_at)
            ->values();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'одобрено',
            'rejected' => 'отклонено',
            'partially_approved' => 'частично одобрено',
            default => 'на рассмотрении',
        };
    }

    public function statusTextClasses(string $status): string
    {
        return match ($status) {
            'approved' => 'text-[#1F7A45]',
            'rejected' => 'text-[#C53B32]',
            'partially_approved' => 'text-[#B76A16]',
            default => 'text-[#2457A5]',
        };
    }

    public function dayStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'одобрено',
            'rejected' => 'отказ',
            default => 'на рассмотрении',
        };
    }

    public function dayStatusClasses(string $status): string
    {
        return match ($status) {
            'approved' => 'text-[#1F7A45]',
            'rejected' => 'text-[#C53B32]',
            default => 'text-[#2457A5]',
        };
    }

    public function itemStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'одобрено',
            'rejected' => 'отказ',
            default => 'на рассмотрении',
        };
    }

    public function itemStatusClasses(string $status): string
    {
        return match ($status) {
            'approved' => 'text-[#1F7A45]',
            'rejected' => 'text-[#C53B32]',
            default => 'text-[#2457A5]',
        };
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'vacation' => 'отпуск',
            'inventory' => 'инвентарь',
            default => 'выходной',
        };
    }

    public function plusUrl(): string
    {
        return route('page-profile.applications');
    }

    public function stats(): array
    {
        $requests = $this->requests;

        return [
            'all' => $requests->count(),
            'pending' => $requests->where('status', 'pending')->count(),
            'approved' => $requests->where('status', 'approved')->count(),
        ];
    }

    public function daysWord(int $count): string
    {
        return match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'день',
            in_array($count % 10, [2, 3, 4]) && ! in_array($count % 100, [12, 13, 14]) => 'дня',
            default => 'дней',
        };
    }

    public function positionsWord(int $count): string
    {
        return match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'позиция',
            in_array($count % 10, [2, 3, 4]) && ! in_array($count % 100, [12, 13, 14]) => 'позиции',
            default => 'позиций',
        };
    }

    public function requestRange(object $request): string
    {
        $days = $request->days->sortBy('date')->values();

        if ($days->isEmpty()) {
            return 'Даты не указаны';
        }

        $first = Carbon::parse($days->first()->date);
        $last = Carbon::parse($days->last()->date);

        if ($first->isSameDay($last)) {
            return $first->translatedFormat('d F');
        }

        if ($first->isSameMonth($last)) {
            return $first->translatedFormat('d') . '–' . $last->translatedFormat('d F');
        }

        return $first->translatedFormat('d F') . ' – ' . $last->translatedFormat('d F');
    }
};
?>

<div
    x-data="{
        tab: 'all',
        matches(type) {
            return this.tab === 'all' || this.tab === type
        }
    }"
    class="flex h-full flex-col overflow-hidden bg-[#F6F7F8]"
>
    <div class="shrink-0 px-[16px] pt-[20px] pb-[14px]">
        <div class="flex items-start justify-between gap-[12px]">
            <div class="min-w-0">
                <h1 class="text-[28px] font-semibold leading-none tracking-[-0.03em] text-[#111111]">
                    Мои заявки
                </h1>

                <p class="mt-[8px] text-[14px] leading-[1.45] text-[#7E7E77]">
                    Выходные, отпуск и инвентарь
                </p>
            </div>

            <a
                href="{{ $this->plusUrl() }}"
                class="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-full bg-[#111111] text-white transition active:scale-[0.96]"
            >
                <x-heroicon-o-plus class="h-[22px] w-[22px] stroke-[2.5]" />
            </a>
        </div>

        <div class="mt-[16px] flex gap-[8px] overflow-x-auto no-scrollbar">
            <button
                type="button"
                @click="tab = 'all'"
                :class="tab === 'all'
                    ? 'bg-[#111111] text-white'
                    : 'bg-white text-[#5F5F59] border border-[#E8EAEE]'"
                class="h-[38px] shrink-0 rounded-full px-[15px] text-[14px] font-medium transition"
            >
                Все
            </button>

            <button
                type="button"
                @click="tab = 'day_off'"
                :class="tab === 'day_off'
                    ? 'bg-[#111111] text-white'
                    : 'bg-white text-[#5F5F59] border border-[#E8EAEE]'"
                class="h-[38px] shrink-0 rounded-full px-[15px] text-[14px] font-medium transition"
            >
                Выходные
            </button>

            <button
                type="button"
                @click="tab = 'vacation'"
                :class="tab === 'vacation'
                    ? 'bg-[#111111] text-white'
                    : 'bg-white text-[#5F5F59] border border-[#E8EAEE]'"
                class="h-[38px] shrink-0 rounded-full px-[15px] text-[14px] font-medium transition"
            >
                Отпуск
            </button>

            <button
                type="button"
                @click="tab = 'inventory'"
                :class="tab === 'inventory'
                    ? 'bg-[#111111] text-white'
                    : 'bg-white text-[#5F5F59] border border-[#E8EAEE]'"
                class="h-[38px] shrink-0 rounded-full px-[15px] text-[14px] font-medium transition"
            >
                Инвентарь
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto px-[16px] pb-[28px]">
        @php
            $hasRequests = $this->requests->isNotEmpty();
            $dayOffCount = $this->requests->where('request_type', 'day_off')->count();
            $vacationCount = $this->requests->where('request_type', 'vacation')->count();
            $inventoryCount = $this->requests->where('request_type', 'inventory')->count();
        @endphp

        @if ($hasRequests)
            <div class="space-y-[10px]">
                @foreach ($this->requests as $request)
                    @if ($request->request_type === 'inventory')
                        <article
                            x-show="matches('{{ $request->request_type }}')"
                            x-transition.opacity.duration.150ms
                            class="rounded-[24px] border border-[#E8EAEE] bg-white px-[18px] py-[16px]"
                        >
                            <div class="flex items-start justify-between gap-[16px]">
                                <div class="min-w-0">
                                    <div class="text-[24px] font-semibold leading-[1.1] tracking-[-0.03em] text-[#111111]">
                                        Запрос инвентаря
                                    </div>

                                    <div class="mt-[6px] text-[14px] text-[#7B7B76]">
                                        {{ $request->items->count() }} {{ $this->positionsWord($request->items->count()) }}
                                        ·
                                        {{ $this->typeLabel($request->request_type) }}
                                    </div>
                                </div>

                                <div class="shrink-0 text-right">
                                    <div class="text-[14px] font-medium {{ $this->statusTextClasses($request->status) }}">
                                        {{ $this->statusLabel($request->status) }}
                                    </div>
                                </div>
                            </div>

                            @if ($request->comment)
                                <div class="mt-[14px] text-[14px] leading-[1.55] text-[#4F4F49]">
                                    <span class="text-[#8B8B84]">Комментарий:</span>
                                    {{ $request->comment }}
                                </div>
                            @endif

                            <div class="mt-[14px] border-t border-[#F0F1F3] pt-[10px]">
                                <div class="space-y-[8px]">
                                    @foreach ($request->items as $item)
                                        <div class="rounded-[18px] bg-[#F8F9FB] px-[14px] py-[12px]">
                                            <div class="flex items-start justify-between gap-[12px]">
                                                <div class="min-w-0">
                                                    <div class="text-[15px] text-[#151515]">
                                                        {{ $item->item_name }}
                                                    </div>

                                                    <div class="mt-[6px] text-[13px] text-[#7B7B76]">
                                                        Запрошено: {{ $item->requested_qty }}
                                                        ·
                                                        Одобрено: {{ $item->approved_qty }}
                                                    </div>

                                                    @if ($item->admin_comment)
                                                        <div class="mt-[4px] text-[13px] leading-[1.45] text-[#777770]">
                                                            {{ $item->admin_comment }}
                                                        </div>
                                                    @endif
                                                </div>

                                                <div class="shrink-0 text-right">
                                                    <div class="text-[13px] font-medium {{ $this->itemStatusClasses($item->status) }}">
                                                        {{ $this->itemStatusLabel($item->status) }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-[12px] text-[12px] text-[#9A9A93]">
                                Создано {{ $request->created_at->translatedFormat('d F Y, H:i') }}
                            </div>

                            @if ($request->admin_comment)
                                <div class="mt-[8px] text-[13px] leading-[1.5] text-[#6C6C66]">
                                    <span class="text-[#90908A]">Комментарий администратора:</span>
                                    {{ $request->admin_comment }}
                                </div>
                            @endif
                        </article>
                    @else
                        <article
                            x-show="matches('{{ $request->request_type }}')"
                            x-transition.opacity.duration.150ms
                            class="rounded-[24px] border border-[#E8EAEE] bg-white px-[18px] py-[16px]"
                        >
                            <div class="flex items-start justify-between gap-[16px]">
                                <div class="min-w-0">
                                    <div class="text-[24px] font-semibold leading-[1.1] tracking-[-0.03em] text-[#111111]">
                                        {{ $this->requestRange($request) }}
                                    </div>

                                    <div class="mt-[6px] text-[14px] text-[#7B7B76]">
                                        {{ $request->days->count() }} {{ $this->daysWord($request->days->count()) }}
                                        ·
                                        {{ $this->typeLabel($request->request_type) }}
                                    </div>
                                </div>

                                <div class="shrink-0 text-right">
                                    <div class="text-[14px] font-medium {{ $this->statusTextClasses($request->status) }}">
                                        {{ $this->statusLabel($request->status) }}
                                    </div>
                                </div>
                            </div>

                            @if ($request->reason)
                                <div class="mt-[14px] text-[14px] leading-[1.55] text-[#4F4F49]">
                                    <span class="text-[#8B8B84]">Причина:</span>
                                    {{ $request->reason }}
                                </div>
                            @endif

                            <div class="mt-[14px] border-t border-[#F0F1F3] pt-[10px]">
                                <div class="space-y-[2px]">
                                    @foreach ($request->days->sortBy('date') as $day)
                                        <div class="py-[9px]">
                                            <div class="flex items-start justify-between gap-[14px]">
                                                <div class="min-w-0 text-[15px] text-[#151515]">
                                                    {{ Carbon::parse($day->date)->translatedFormat('d F Y') }}
                                                </div>

                                                <div class="shrink-0 text-[14px] font-medium {{ $this->dayStatusClasses($day->status) }}">
                                                    {{ $this->dayStatusLabel($day->status) }}
                                                </div>
                                            </div>

                                            @if ($day->admin_comment)
                                                <div class="mt-[4px] text-[13px] leading-[1.5] text-[#777770]">
                                                    {{ $day->admin_comment }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mt-[12px] text-[12px] text-[#9A9A93]">
                                Создано {{ $request->created_at->translatedFormat('d F Y, H:i') }}
                            </div>

                            @if ($request->admin_comment)
                                <div class="mt-[8px] text-[13px] leading-[1.5] text-[#6C6C66]">
                                    <span class="text-[#90908A]">Комментарий администратора:</span>
                                    {{ $request->admin_comment }}
                                </div>
                            @endif
                        </article>
                    @endif
                @endforeach
            </div>

            <div
                x-show="
                    (tab === 'day_off' && !{{ $dayOffCount }}) ||
                    (tab === 'vacation' && !{{ $vacationCount }}) ||
                    (tab === 'inventory' && !{{ $inventoryCount }})
                "
                x-transition.opacity.duration.150ms
                class="flex h-full flex-col items-center justify-center px-[20px] text-center"
            >
                <div class="mb-[16px] flex h-[72px] w-[72px] items-center justify-center rounded-full border border-[#ECEEF2] bg-white">
                    <x-heroicon-o-calendar-days class="h-[34px] w-[34px] text-[#A5A5A0]" />
                </div>

                <h2 class="text-[20px] font-semibold text-[#111111]">
                    Здесь пока пусто
                </h2>

                <p class="mt-[8px] max-w-[280px] text-[15px] leading-[1.5] text-[#7B7B76]">
                    В этом разделе пока нет заявок.
                </p>
            </div>
        @else
            <div class="flex h-full flex-col items-center justify-center px-[20px] text-center">
                <div class="mb-[16px] flex h-[72px] w-[72px] items-center justify-center rounded-full border border-[#ECEEF2] bg-white">
                    <x-heroicon-o-calendar-days class="h-[34px] w-[34px] text-[#A5A5A0]" />
                </div>

                <h2 class="text-[20px] font-semibold text-[#111111]">
                    Пока нет заявок
                </h2>

                <p class="mt-[8px] max-w-[280px] text-[15px] leading-[1.5] text-[#7B7B76]">
                    Когда вы отправите первую заявку, она появится здесь.
                </p>

                <a
                    href="{{ $this->plusUrl() }}"
                    class="mt-[18px] inline-flex h-[44px] items-center justify-center rounded-full bg-[#111111] px-[18px] text-[14px] font-semibold text-white transition active:scale-[0.97]"
                >
                    Создать заявку
                </a>
            </div>
        @endif
    </div>
</div>