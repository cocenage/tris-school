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
    public string $search = '';
    public string $tab = 'all';
    public array $categories = [];
    public string $statusFilter = 'all';
    public string $sort = 'new';

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

    public function statusLabel(?string $status, ?string $requestType = null): string
    {
        return match ($status) {
            'approved' => 'одобрено',
            'rejected' => in_array($requestType, ['salary','schedule','inventory'], true) ? 'отказано' : 'отклонено',
            'partially_approved' => 'частично',
            'issued' => 'выдано',
            'partially_issued' => 'частично',
            'cancelled' => 'отменено',
            'in_progress' => 'в работе',
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

    public function decisionAt(object $request): ?Carbon
    {
        foreach (['reviewed_at', 'processed_at', 'answered_at'] as $field) {
            if (! empty($request->{$field})) {
                return Carbon::parse($request->{$field});
            }
        }

        return null;
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
            'approved' => 'одобрено',
            'partially_approved' => 'частично одобрено',
            'rejected' => 'отказано',
            'issued' => 'выдано',
            'partially_issued' => 'частично',
            'cancelled' => 'не выдано',
            default => 'ожидает',
        };
    }

    public function itemStatusClasses(?string $status): string
    {
        return match ($status) {
            'approved', 'issued' => 'text-[#1F7A45]',
            'rejected', 'cancelled' => 'text-[#C53B32]',
            'partially_approved', 'partially_issued' => 'text-[#B76A16]',
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
    public function categoryLabels(): array
    {
        return [
            'inventory' => 'Инвентарь',
            'salary' => 'Зарплата',
            'day_off' => 'Выходной',
            'vacation' => 'Отпуск',
            'schedule' => 'График',
            'feedback' => 'Обратная связь',
        ];
    }

    public function titleFor(string $type): string
    {
        return match ($type) {
            'inventory' => 'Запрос инвентаря',
            'salary' => 'Вопрос по зарплате',
            'day_off' => 'Запрос выходного',
            'vacation' => 'Заявка на отпуск',
            'schedule' => 'Вопрос по графику работы',
            'feedback' => 'Обратная связь',
            default => 'Заявка',
        };
    }

    public function subtitleFor(object $request): string
    {
        return match ($request->request_type) {
            'inventory' => $request->lines->count().' '.$this->positionsWord($request->lines->count()),
            'day_off', 'vacation' => $this->requestRange($request).' · '.$request->days->count().' '.$this->daysWord($request->days->count()),
            default => $request->type ?: 'Без категории',
        };
    }

    public function statusGroup(?string $status): string
    {
        return match ($status) {
            'partially_approved', 'partially_issued' => 'partial',
            'approved', 'issued', 'reviewed', 'rejected', 'cancelled', 'closed', 'in_progress' => $status,
            default => 'pending',
        };
    }

    public function tone(?string $status): string
    {
        return match ($this->statusGroup($status)) {
            'approved', 'issued', 'reviewed' => 'green',
            'rejected' => 'red',
            'partial' => 'orange',
            'cancelled', 'closed' => 'neutral',
            default => 'blue',
        };
    }

    public function label(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    public function isActive(object $request): bool
    {
        $status = $this->statusGroup($request->status);
        if (in_array($status, ['pending', 'in_progress'], true)) {
            return true;
        }

        if ($status === 'partial') {
            $items = match ($request->request_type) {
                'inventory' => $request->lines,
                'day_off', 'vacation' => $request->days,
                default => collect(),
            };

            return $items->contains(fn ($item) => in_array($this->statusGroup($item->status), ['pending', 'in_progress'], true));
        }

        return false;
    }

    public function filterStatusLabels(): array
    {
        return [
            'all' => 'Все статусы', 'pending' => 'На рассмотрении',
            'in_progress' => 'В работе', 'approved' => 'Одобрено',
            'partial' => 'Частично', 'rejected' => 'Отказано / отклонено',
            'issued' => 'Выдано', 'reviewed' => 'Рассмотрено',
            'cancelled' => 'Отменено', 'closed' => 'Закрыто',
        ];
    }

    public function applyFilters(array $categories, string $status, string $sort): void
    {
        $this->categories = array_values(array_intersect(array_keys($this->categoryLabels()), array_filter($categories, 'is_string')));
        $this->statusFilter = array_key_exists($status, $this->filterStatusLabels()) ? $status : 'all';
        $this->sort = in_array($sort, ['new', 'old', 'updated'], true) ? $sort : 'new';
    }

    public function resetListFilters(): void
    {
        $this->reset('search', 'tab', 'categories', 'statusFilter', 'sort');
    }

    public function searchText(object $request): string
    {
        $parts = [
            $this->titleFor($request->request_type), $this->subtitleFor($request),
            $this->statusLabel($request->status, $request->request_type),
            $request->comment, $request->reason, $request->admin_comment,
            $request->created_at?->translatedFormat('d F Y H:i'),
            $request->created_at?->format('d.m.Y'),
        ];

        if ($request->request_type === 'inventory') {
            foreach ($request->lines as $line) {
                array_push($parts, $line->item_name, $line->variant_label, $line->admin_comment, $this->itemStatusLabel($line->status));
            }
        } elseif (in_array($request->request_type, ['day_off', 'vacation'], true)) {
            foreach ($request->days as $day) {
                array_push($parts, Carbon::parse($day->date)->translatedFormat('d F Y'), Carbon::parse($day->date)->format('d.m.Y'), $day->admin_comment, $this->dayStatusLabel($day->status));
            }
        }

        return mb_strtolower(implode(' ', array_filter($parts, fn ($part) => is_scalar($part))));
    }

    public function filteredRequests(Collection $requests): Collection
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($this->search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $items = $requests->filter(function ($request) use ($words) {
            if ($this->tab === 'active' && ! $this->isActive($request)) return false;
            if ($this->tab === 'completed' && $this->isActive($request)) return false;
            if ($this->categories && ! in_array($request->request_type, $this->categories, true)) return false;
            if ($this->statusFilter !== 'all' && $this->statusGroup($request->status) !== $this->statusFilter) return false;
            if (! $words) return true;
            $haystack = $this->searchText($request);
            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) return false;
            }
            return true;
        });

        return match ($this->sort) {
            'old' => $items->sortBy(fn ($item) => $item->created_at)->values(),
            'updated' => $items->sortByDesc(fn ($item) => $item->updated_at ?? $item->created_at)->values(),
            default => $items->values(),
        };
    }
};
?>

<x-slot:header><div class="hidden"></div></x-slot:header>

@php
    $requests = $this->requests;
    $visible = $this->filteredRequests($requests);
    $activeCount = $requests->filter(fn ($request) => $this->isActive($request))->count();
    $filterCount = count($categories) + (int) ($statusFilter !== 'all') + (int) ($sort !== 'new');
@endphp

<div class="tris-mobile-requests min-h-full bg-[#F5F5F5] px-5 pt-[42px] pb-8 text-[#171717]"
    x-data="{
        draftCategories: [],
        draftStatus: 'all',
        draftSort: 'new',
        openFilters() {
            this.draftCategories = [...$wire.categories];
            this.draftStatus = $wire.statusFilter;
            this.draftSort = $wire.sort;
            this.$refs.requestFilters.showModal();
        },
        async apply() {
            await $wire.applyFilters(this.draftCategories, this.draftStatus, this.draftSort);
            this.$refs.requestFilters.close();
        },
        resetDraft() { this.draftCategories = []; this.draftStatus = 'all'; this.draftSort = 'new'; }
    }">
    @php
        $total = $requests->count();
        $totalLabel = $total.' '.match(true) {
            $total % 10 === 1 && $total % 100 !== 11 => 'заявка',
            in_array($total % 10, [2,3,4]) && !in_array($total % 100, [12,13,14]) => 'заявки',
            default => 'заявок',
        };
    @endphp
    <x-mobile.page-header title="Заявки" :subtitle="$totalLabel" class="mb-5" />
    <div class="flex items-center gap-2.5">
        <x-mobile.search-bar wire:model.live.debounce.250ms="search" placeholder="Поиск заявок" />
        <x-mobile.filter-button x-on:click="openFilters()" :count="$filterCount" label="Фильтры заявок" />
    </div>
    <x-mobile.segmented-tabs :options="['all' => 'Все', 'active' => 'Активные '.$activeCount, 'completed' => 'Завершённые']"
        :selected="$tab" model="tab" label="Раздел заявок" class="mt-[15px] mb-[26px]" />

    <div wire:key="request-tab-{{ $tab }}" class="tris-mobile-tab-panel space-y-[12px]" aria-live="polite">
        @forelse($visible as $request)
            <x-mobile.expandable-card wire:key="request-{{ $request->request_type }}-{{ $request->id }}"
                :title="$this->titleFor($request->request_type)" :subtitle="$this->subtitleFor($request)"
                :status="$this->label($this->statusLabel($request->status, $request->request_type))"
                :tone="$this->tone($request->status)" :date="$request->created_at?->translatedFormat('j M, H:i')">
                <x-slot:icon><x-mobile.request-icon :type="$request->request_type" /></x-slot:icon>
                @if($request->request_type === 'inventory')
                    @if(filled($request->comment))
                        <div class="text-sm"><div class="mb-1 text-xs text-[#737373]">Комментарий</div><div class="whitespace-pre-wrap break-words">{{ $request->comment }}</div></div>
                    @endif
                    <div class="divide-y divide-[#EAEAEA]">
                        @foreach($request->lines as $item)
                            <x-mobile.decision-row
                                :title="$item->item_name.($item->variant_label ? ' · '.$item->variant_label : '')"
                                :status="$this->label($this->itemStatusLabel($item->status))"
                                :tone="$item->status === 'cancelled' ? 'red' : $this->tone($item->status)"
                                :description="'Запрошено: '.$item->requested_qty.' · '.($request->telegram_review_version !== null ? 'Согласовано: '.($item->approved_qty ?? '—') : 'Выдано: '.$item->issued_qty)"
                                :comment="$item->admin_comment" />
                        @endforeach
                    </div>
                @elseif(in_array($request->request_type, ['day_off','vacation'], true))
                    @if(filled($request->reason))
                        <div class="text-sm"><div class="mb-1 text-xs text-[#737373]">Причина</div><div class="whitespace-pre-wrap break-words">{{ $request->reason }}</div></div>
                    @endif
                    <div class="divide-y divide-[#EAEAEA]">
                        @foreach($request->days->sortBy('date') as $day)
                            <x-mobile.decision-row
                                :title="Carbon::parse($day->date)->translatedFormat('j F Y')"
                                :status="$this->label($this->dayStatusLabel($day->status))"
                                :tone="$this->tone($day->status)" :comment="$day->admin_comment" />
                        @endforeach
                    </div>
                @else
                    @if(filled($request->comment))
                        <div class="text-sm"><div class="mb-1 text-xs text-[#737373]">Обращение</div><div class="whitespace-pre-wrap break-words leading-relaxed">{{ $request->comment }}</div></div>
                    @endif
                    @if(!empty($request->attachments))
                        <div class="text-xs text-[#737373]">{{ count($request->attachments) }} {{ $this->filesWord(count($request->attachments)) }}</div>
                    @endif
                @endif
                @if(filled($request->admin_comment))
                    <x-mobile.response-block :comment="$request->admin_comment" :date="$this->decisionAt($request)?->translatedFormat('j F Y, H:i')" />
                @elseif($this->decisionAt($request))
                    <div class="text-xs text-[#737373]">Решение: {{ $this->decisionAt($request)->translatedFormat('j F Y, H:i') }}</div>
                @endif
            </x-mobile.expandable-card>
        @empty
            @php
                $hasFilters = filled(trim($search)) || $filterCount > 0;
                [$emptyTitle, $emptyDescription] = match (true) {
                    $requests->isEmpty() => ['Пока нет заявок', 'Здесь появится история ваших обращений.'],
                    $hasFilters => ['Ничего не найдено', 'Попробуйте другой запрос или измените фильтры.'],
                    $tab === 'active' => ['Всё рассмотрено', 'Новых заявок в работе пока нет.'],
                    $tab === 'completed' => ['Пока без решений', 'Рассмотренные заявки появятся здесь.'],
                    default => ['Ничего не найдено', 'Попробуйте изменить условия поиска.'],
                };
            @endphp
            <x-mobile.empty-state :title="$emptyTitle" :description="$emptyDescription">
                @if($requests->isNotEmpty() && $hasFilters)
                    <button type="button" wire:click="resetListFilters" class="tris-mobile-press rounded-full bg-white px-4 py-2.5 text-[13px] font-semibold text-[#2A1F1A]">Сбросить фильтры</button>
                @endif
            </x-mobile.empty-state>
        @endforelse
    </div>

    <x-mobile.bottom-sheet title="Фильтры" ref="requestFilters" wire:ignore.self>
        <x-slot:actions>
            <button type="button" @click="resetDraft()" class="ml-auto text-sm text-[#737373]">Сбросить</button>
        </x-slot:actions>
        <form id="request-filter-form" @submit.prevent="apply()" class="space-y-6">
            <fieldset>
                <legend class="mb-3 font-medium">Категория</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach($this->categoryLabels() as $value => $label)
                        <label class="cursor-pointer">
                            <input type="checkbox" value="{{ $value }}" x-model="draftCategories" class="peer sr-only" />
                            <span class="inline-flex rounded-full bg-[#F2F2F2] px-3 py-2 text-sm text-[#444444] peer-checked:bg-[#2A1F1A] peer-checked:text-white peer-focus-visible:outline-2">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset>
                <legend class="mb-3 font-medium">Статус</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach($this->filterStatusLabels() as $value => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="request-status" value="{{ $value }}" x-model="draftStatus" class="peer sr-only" />
                            <span class="inline-flex rounded-full bg-[#F2F2F2] px-3 py-2 text-sm text-[#444444] peer-checked:bg-[#2A1F1A] peer-checked:text-white peer-focus-visible:outline-2">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset class="space-y-3 border-t border-[#EEEEEE] pt-4">
                <legend class="font-medium">Сортировка</legend>
                @foreach(['new'=>'Сначала новые','old'=>'Сначала старые','updated'=>'Недавно обновлённые'] as $value => $label)
                    <label class="flex cursor-pointer items-center gap-3 text-sm">
                        <input type="radio" name="request-sort" value="{{ $value }}" x-model="draftSort" class="size-4 accent-[#2A1F1A]" />{{ $label }}
                    </label>
                @endforeach
            </fieldset>
        </form>
        <x-slot:footer>
            <button type="submit" form="request-filter-form" wire:loading.attr="disabled" wire:target="applyFilters"
                class="w-full rounded-2xl bg-[#2A1F1A] px-4 py-3.5 text-sm font-medium text-white disabled:opacity-60">Показать заявки</button>
        </x-slot:footer>
    </x-mobile.bottom-sheet>
</div>
