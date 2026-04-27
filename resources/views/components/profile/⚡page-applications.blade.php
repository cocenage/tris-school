<?php

use App\Models\DayOffRequest;
use App\Models\FeedbackSuggestion;
use App\Models\InventoryRequest;
use App\Models\SalaryQuestion;
use App\Models\ScheduleQuestion;
use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        foreach ([
            DayOffRequest::class,
            VacationRequest::class,
            InventoryRequest::class,
            SalaryQuestion::class,
            ScheduleQuestion::class,
            FeedbackSuggestion::class,
        ] as $model) {
            $model::query()
                ->where('user_id', auth()->id())
                ->whereNull('answer_seen_at')
                ->where(function ($query) {
                    $query
                        ->whereNotNull('answered_at')
                        ->orWhereNotNull('admin_comment')
                        ->orWhereIn('status', [
                            'approved',
                            'rejected',
                            'partially_approved',
                            'issued',
                            'partially_issued',
                            'cancelled',
                            'reviewed',
                            'closed',
                        ]);
                })
                ->update([
                    'answer_seen_at' => now(),
                ]);
        }
    }

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
                return $request;
            });

        $inventories = InventoryRequest::query()
            ->with(['lines'])
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'inventory';
                $request->request_type_label = 'Инвентарь';
                return $request;
            });

        $salaryQuestions = SalaryQuestion::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'salary';
                $request->request_type_label = 'Зарплата';
                return $request;
            });

        $scheduleQuestions = ScheduleQuestion::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'schedule';
                $request->request_type_label = 'График';
                return $request;
            });

        $feedbackSuggestions = FeedbackSuggestion::query()
            ->where('user_id', auth()->id())
            ->latest()
            ->get()
            ->map(function ($request) {
                $request->request_type = 'feedback';
                $request->request_type_label = 'Отзыв';
                return $request;
            });

        return $dayOffs
            ->concat($vacations)
            ->concat($inventories)
            ->concat($salaryQuestions)
            ->concat($scheduleQuestions)
            ->concat($feedbackSuggestions)
            ->sortByDesc(fn ($request) => $request->created_at)
            ->values();
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'одобрено',
            'rejected' => 'отклонено',
            'partially_approved' => 'частично',
            'issued' => 'выдано',
            'partially_issued' => 'частично',
            'cancelled' => 'отменено',
            'reviewed' => 'рассмотрено',
            'closed' => 'закрыто',
            default => 'на рассмотрении',
        };
    }

    public function statusBadgeClasses(?string $status): string
    {
        return match ($status) {
            'approved', 'issued', 'reviewed' => 'bg-[#EAF7EF] text-[#1F7A45]',
            'rejected', 'cancelled', 'closed' => 'bg-[#FDEDEC] text-[#C53B32]',
            'partially_approved', 'partially_issued' => 'bg-[#FFF4E5] text-[#B76A16]',
            default => 'bg-[#EEF4FF] text-[#2457A5]',
        };
    }

    public function dayStatusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'одобрено',
            'rejected' => 'отказ',
            default => 'ожидает',
        };
    }

    public function dayStatusClasses(?string $status): string
    {
        return match ($status) {
            'approved' => 'text-[#1F7A45]',
            'rejected' => 'text-[#C53B32]',
            default => 'text-[#2457A5]',
        };
    }

    public function itemStatusLabel(?string $status): string
    {
        return match ($status) {
            'issued' => 'выдано',
            'partially_issued' => 'частично',
            'cancelled' => 'не выдано',
            default => 'ожидает',
        };
    }

    public function itemStatusClasses(?string $status): string
    {
        return match ($status) {
            'issued' => 'text-[#1F7A45]',
            'cancelled' => 'text-[#C53B32]',
            'partially_issued' => 'text-[#B76A16]',
            default => 'text-[#2457A5]',
        };
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'vacation' => 'Отпуск',
            'inventory' => 'Инвентарь',
            'salary' => 'Зарплата',
            'schedule' => 'График',
            'feedback' => 'Отзыв',
            default => 'Выходной',
        };
    }

    public function typeIcon(string $type): string
    {
        return match ($type) {
            'vacation' => '🏖',
            'inventory' => '📦',
            'salary' => '💰',
            'schedule' => '📅',
            'feedback' => '💡',
            default => '🌿',
        };
    }

    public function plusUrl(): string
    {
        return route('page-profile.applications');
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

    public function filesWord(int $count): string
    {
        return match (true) {
            $count % 10 === 1 && $count % 100 !== 11 => 'файл',
            in_array($count % 10, [2, 3, 4]) && ! in_array($count % 100, [12, 13, 14]) => 'файла',
            default => 'файлов',
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

<x-slot:header>
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
     <button
            type="button"
            onclick="history.back()"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-arrow-left
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
        </button>
        <span class="flex items-center justify-center text-[18px] leading-none">
            Мои заявки
        </span>

     <button
            type="button"
     
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
        </button>
    </div>
</x-slot:header>

<div
    x-data="{
        tab: 'all',
        matches(type) {
            return this.tab === 'all' || this.tab === type
        }
    }"
    class="flex h-full flex-col overflow-hidden"
>
    <div class="shrink-0 px-[15px] pt-[20px] pb-[10px]">
    

        <div class="mt-[18px] flex gap-[8px] overflow-x-auto no-scrollbar">
            @foreach ([
                'all' => 'Все',
                'day_off' => 'Выходные',
                'vacation' => 'Отпуск',
                'inventory' => 'Инвентарь',
                'salary' => 'Зарплата',
                'schedule' => 'График',
                'feedback' => 'Отзывы',
            ] as $value => $label)
                <button
                    type="button"
                    @click="tab = '{{ $value }}'"
                    :class="tab === '{{ $value }}'
                        ? 'bg-[#111111] text-white'
                        : 'bg-white text-[#777770]'"
                    class="h-[36px] shrink-0 rounded-full px-[14px] text-[13px] font-medium shadow-[0_1px_0_rgba(0,0,0,0.04)] transition active:scale-[0.98]"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="flex-1 overflow-y-auto px-[18px] pb-[28px]">
        @php
            $requests = $this->requests;

            $hasRequests = $requests->isNotEmpty();

            $counts = [
                'day_off' => $requests->where('request_type', 'day_off')->count(),
                'vacation' => $requests->where('request_type', 'vacation')->count(),
                'inventory' => $requests->where('request_type', 'inventory')->count(),
                'salary' => $requests->where('request_type', 'salary')->count(),
                'schedule' => $requests->where('request_type', 'schedule')->count(),
                'feedback' => $requests->where('request_type', 'feedback')->count(),
            ];
        @endphp

        @if ($hasRequests)
            <div class="space-y-[10px]">
                @foreach ($requests as $request)
                    <article
                        x-show="matches('{{ $request->request_type }}')"
                        x-transition.opacity.duration.150ms
                        class="rounded-[26px] bg-zinc-50 p-[16px] shadow-[0_1px_0_rgba(0,0,0,0.04)]"
                    >
                        <div class="flex items-start justify-between gap-[14px]">
                            <div class="min-w-0">
                                <div class="flex items-center gap-[8px]">
                                    <div class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full bg-[#F4F4F1] text-[15px]">
                                        {{ $this->typeIcon($request->request_type) }}
                                    </div>

                                    <div class="truncate text-[13px] font-medium text-[#8A8A84]">
                                        {{ $this->typeLabel($request->request_type) }}
                                    </div>
                                </div>

                                <div class="mt-[12px] text-[19px] font-semibold leading-[1.15] tracking-[-0.03em] text-[#111111]">
                                    @if ($request->request_type === 'inventory')
                                        Запрос инвентаря
                                    @elseif (in_array($request->request_type, ['salary', 'schedule', 'feedback'], true))
                                        {{ $request->request_type_label }}
                                    @else
                                        {{ $this->requestRange($request) }}
                                    @endif
                                </div>

                                <div class="mt-[6px] text-[13px] leading-[1.4] text-[#8A8A84]">
                                    @if ($request->request_type === 'inventory')
                                        {{ $request->lines->count() }} {{ $this->positionsWord($request->lines->count()) }}
                                    @elseif (in_array($request->request_type, ['salary', 'schedule', 'feedback'], true))
                                        {{ $request->type ?: 'Без категории' }}
                                    @else
                                        {{ $request->days->count() }} {{ $this->daysWord($request->days->count()) }}
                                    @endif

                                    <span class="text-[#C2C2BC]">·</span>

                                    {{ $request->created_at->translatedFormat('d F, H:i') }}
                                </div>
                            </div>

                            <span class="inline-flex shrink-0 rounded-full px-[10px] py-[5px] text-[12px] font-medium {{ $this->statusBadgeClasses($request->status) }}">
                                {{ $this->statusLabel($request->status) }}
                            </span>
                        </div>

                        @if ($request->request_type === 'inventory')
                            @if ($request->lines->isNotEmpty())
                                <div class="mt-[14px] space-y-[6px]">
                                    @foreach ($request->lines as $item)
                                        <div class="rounded-[18px] bg-[#F7F7F5] px-[13px] py-[11px]">
                                            <div class="flex items-start justify-between gap-[12px]">
                                                <div class="min-w-0">
                                                    <div class="text-[14px] font-medium leading-[1.35] text-[#1A1A1A]">
                                                        {{ $item->item_name }}

                                                        @if ($item->variant_label)
                                                            <span class="font-normal text-[#8A8A84]">
                                                                · {{ $item->variant_label }}
                                                            </span>
                                                        @endif
                                                    </div>

                                                    <div class="mt-[4px] text-[12px] text-[#8A8A84]">
                                                        Запрошено: {{ $item->requested_qty }}
                                                        <span class="text-[#C2C2BC]">·</span>
                                                        Выдано: {{ $item->issued_qty }}
                                                    </div>
                                                </div>

                                                <div class="shrink-0 text-[12px] font-medium {{ $this->itemStatusClasses($item->status) }}">
                                                    {{ $this->itemStatusLabel($item->status) }}
                                                </div>
                                            </div>

                                            @if ($item->admin_comment)
                                                <div class="mt-[7px] text-[13px] leading-[1.45] text-[#6F6F69]">
                                                    {{ $item->admin_comment }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @elseif (in_array($request->request_type, ['salary', 'schedule', 'feedback'], true))
                            @if ($request->comment)
                                <div class="mt-[14px] rounded-[18px] bg-[#F7F7F5] px-[13px] py-[11px] text-[14px] leading-[1.55] text-[#4F4F49]">
                                    {{ $request->comment }}
                                </div>
                            @endif

                            @if (! empty($request->attachments))
                                <div class="mt-[10px] text-[13px] text-[#8A8A84]">
                                    {{ count($request->attachments) }} {{ $this->filesWord(count($request->attachments)) }}
                                </div>
                            @endif
                        @else
                            @if ($request->reason)
                                <div class="mt-[14px] rounded-[18px] bg-[#F7F7F5] px-[13px] py-[11px] text-[14px] leading-[1.55] text-[#4F4F49]">
                                    <span class="text-[#8A8A84]">Причина:</span>
                                    {{ $request->reason }}
                                </div>
                            @endif

                            @if ($request->days->isNotEmpty())
                                <div class="mt-[12px] divide-y divide-[#EFEFEB] overflow-hidden rounded-[18px] bg-[#F7F7F5]">
                                    @foreach ($request->days->sortBy('date') as $day)
                                        <div class="px-[13px] py-[10px]">
                                            <div class="flex items-center justify-between gap-[12px]">
                                                <div class="min-w-0 text-[14px] text-[#1A1A1A]">
                                                    {{ Carbon::parse($day->date)->translatedFormat('d F Y') }}
                                                </div>

                                                <div class="shrink-0 text-[12px] font-medium {{ $this->dayStatusClasses($day->status) }}">
                                                    {{ $this->dayStatusLabel($day->status) }}
                                                </div>
                                            </div>

                                            @if ($day->admin_comment)
                                                <div class="mt-[5px] text-[13px] leading-[1.45] text-[#6F6F69]">
                                                    {{ $day->admin_comment }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif

                        @if ($request->admin_comment)
                            <div class="mt-[12px] rounded-[18px] bg-[#F1F1EE] px-[13px] py-[11px] text-[13px] leading-[1.5] text-[#5F5F59]">
                                <div class="mb-[3px] text-[12px] font-medium text-[#8A8A84]">
                                    Ответ администратора
                                </div>

                                {{ $request->admin_comment }}
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>

            <div
                x-show="
                    (tab === 'day_off' && !{{ $counts['day_off'] }}) ||
                    (tab === 'vacation' && !{{ $counts['vacation'] }}) ||
                    (tab === 'inventory' && !{{ $counts['inventory'] }}) ||
                    (tab === 'salary' && !{{ $counts['salary'] }}) ||
                    (tab === 'schedule' && !{{ $counts['schedule'] }}) ||
                    (tab === 'feedback' && !{{ $counts['feedback'] }})
                "
                x-transition.opacity.duration.150ms
                class="flex min-h-[360px] flex-col items-center justify-center px-[20px] text-center"
            >
                <div class="mb-[14px] flex h-[64px] w-[64px] items-center justify-center rounded-full bg-white shadow-[0_1px_0_rgba(0,0,0,0.04)]">
                    <x-heroicon-o-inbox class="h-[30px] w-[30px] text-[#A5A5A0]" />
                </div>

                <h2 class="text-[19px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Здесь пусто
                </h2>

                <p class="mt-[7px] max-w-[260px] text-[14px] leading-[1.5] text-[#8A8A84]">
                    В этом разделе пока нет заявок.
                </p>
            </div>
        @else
            <div class="flex min-h-[520px] flex-col items-center justify-center px-[20px] text-center">
                <div class="mb-[14px] flex h-[68px] w-[68px] items-center justify-center rounded-full bg-white shadow-[0_1px_0_rgba(0,0,0,0.04)]">
                    <x-heroicon-o-inbox class="h-[32px] w-[32px] text-[#A5A5A0]" />
                </div>

                <h2 class="text-[20px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Пока нет заявок
                </h2>

                <p class="mt-[8px] max-w-[280px] text-[14px] leading-[1.5] text-[#8A8A84]">
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