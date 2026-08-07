<?php

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use App\Jobs\SendTelegramNotificationJob;
use App\Services\Forms\DayOffRequestTelegramService;
use App\Services\Calendar\CalendarEventsService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    public Carbon $month;

    public bool $policyModalOpen = false;
    public bool $peakModalOpen = false;
    public bool $successSheetOpen = false;
    public bool $sundayWarningConfirmed = false;

    public ?string $draftStartDate = null;
    public ?string $peakModalDate = null;

    public array $ranges = [];
    public string $comment = '';
    public ?string $successMessage = null;

    public array $requestStatuses = [];

    public string $adminChatUrl = '';

    public function mount(): void
    {
        Carbon::setLocale('ru');

        $this->month = now()->startOfMonth();
        $this->adminChatUrl = (string) config('services.day_off.admin_chat_url', '');

        $this->restoreDraft();
        $this->requestStatuses = $this->requestStatusesByDate();
    }

    protected function toast(
        string $type,
        string $title,
        string $message = '',
        int $duration = 3500,
    ): void {
        $this->dispatch(
            'toast',
            type: $type,
            title: $title,
            message: $message,
            duration: $duration,
        );
    }

public function getFormProgressProperty(): int
{
    $total = 2;
    $done = 0;

    if (! empty($this->ranges)) {
        $done++;
    }

    if (mb_strlen(trim($this->comment)) >= 5) {
        $done++;
    }

    return (int) round(($done / $total) * 100);
}

public function getFormReadyProperty(): bool
{
    return $this->formProgress >= 100;
}

public function getFormButtonTextProperty(): string
{
    return match (true) {
        $this->formProgress >= 100 => 'Отправить',
        ! empty($this->ranges) && mb_strlen(trim($this->comment)) < 5 => 'Опишите причину',
        empty($this->ranges) => 'Выбери дату',
        default => 'Заполните',
    };
}

    protected function draftKey(): string
    {
        return 'day_off_request_draft_' . (Auth::id() ?: 'guest');
    }

    protected function persistDraft(): void
    {
        session()->put($this->draftKey(), [
            'ranges' => $this->ranges,
            'comment' => $this->comment,
            'draftStartDate' => $this->draftStartDate,
            'month' => $this->month->toDateString(),
        ]);
    }

    protected function restoreDraft(): void
    {
        $draft = session()->get($this->draftKey());

        if (! is_array($draft)) {
            return;
        }

        $this->ranges = is_array($draft['ranges'] ?? null) ? $draft['ranges'] : [];
        $this->comment = (string) ($draft['comment'] ?? '');
        $this->draftStartDate = ! empty($draft['draftStartDate'])
            ? (string) $draft['draftStartDate']
            : null;

        if (! empty($draft['month'])) {
            try {
                $this->month = Carbon::parse($draft['month'])->startOfMonth();
            } catch (\Throwable $e) {
                $this->month = now()->startOfMonth();
            }
        }
    }

    protected function clearDraft(): void
    {
        session()->forget($this->draftKey());
    }

    public function updatedComment(): void
    {
        $this->persistDraft();
    }

    public function prevMonth(): void
    {
        $this->month = $this->month->copy()->subMonth()->startOfMonth();
        $this->persistDraft();
    }

    public function nextMonth(): void
    {
        $this->month = $this->month->copy()->addMonth()->startOfMonth();
        $this->persistDraft();
    }

    public function closePolicyModal(): void
    {
        $this->policyModalOpen = false;
        $this->sundayWarningConfirmed = false;
    }

    public function closePeakModal(): void
    {
        $this->peakModalOpen = false;
        $this->peakModalDate = null;
    }

    public function confirmSundaySubmission(): void
    {
        $this->policyModalOpen = false;
        $this->sundayWarningConfirmed = true;
        $this->submit();
    }

    public function closeSuccessSheet(): void
    {
        $this->successSheetOpen = false;
        $this->successMessage = null;
    }

    public function resetForm(): void
    {
        $this->ranges = [];
        $this->comment = '';
        $this->draftStartDate = null;
        $this->policyModalOpen = false;
        $this->peakModalOpen = false;
        $this->peakModalDate = null;
        $this->sundayWarningConfirmed = false;

        $this->resetErrorBag();
        $this->resetValidation();
        $this->clearDraft();
    }

    protected function requestStatusesByDate(): array
    {
        return DayOffRequestDay::query()
            ->where('user_id', Auth::id())
            ->get(['date', 'status'])
            ->mapWithKeys(fn ($item) => [
                Carbon::parse($item->date)->toDateString() => $item->status,
            ])
            ->all();
    }

    protected function isAlreadyRequested(string $date): bool
    {
        return array_key_exists($date, $this->requestStatuses);
    }

    protected function isInsideExistingRange(string $date, ?int $ignoreIndex = null): bool
    {
        $current = Carbon::parse($date)->startOfDay();

        foreach ($this->ranges as $index => $range) {
            if ($ignoreIndex !== null && $index === $ignoreIndex) {
                continue;
            }

            $start = Carbon::parse($range['start'])->startOfDay();
            $end = Carbon::parse($range['end'])->startOfDay();

            if ($current->betweenIncluded($start, $end)) {
                return true;
            }
        }

        return false;
    }

    protected function isSunday(string $date): bool
    {
        return Carbon::parse($date)->isSunday();
    }

    protected function isPeakDay(string $date): bool
    {
        return app(CalendarEventsService::class)->isPeakDay(Carbon::parse($date));
    }

    protected function peakDatesForRange(Carbon $start, Carbon $end): array
    {
        return app(CalendarEventsService::class)
            ->getPeakDatesForRange($start, $end)
            ->flip()
            ->all();
    }

    protected function findRangeIndexByDate(string $date): ?int
    {
        $current = Carbon::parse($date)->startOfDay();

        foreach ($this->ranges as $index => $range) {
            $start = Carbon::parse($range['start'])->startOfDay();
            $end = Carbon::parse($range['end'])->startOfDay();

            if ($current->betweenIncluded($start, $end)) {
                return $index;
            }
        }

        return null;
    }

    protected function rangeConflictReason(string $startDate, string $endDate, ?int $ignoreIndex = null): ?array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            return null;
        }

        $peakDates = [];
        $requestedDates = [];
        $selectedDates = [];
        $peakDateSet = $this->peakDatesForRange($start, $end);

        foreach (CarbonPeriod::create($start, $end) as $periodDate) {
            $date = $periodDate->toDateString();

            if (isset($peakDateSet[$date])) {
                $peakDates[] = Carbon::parse($date)->format('d.m');
                continue;
            }

            if ($this->isAlreadyRequested($date)) {
                $requestedDates[] = Carbon::parse($date)->format('d.m');
                continue;
            }

            if ($this->isInsideExistingRange($date, $ignoreIndex)) {
                $selectedDates[] = Carbon::parse($date)->format('d.m');
            }
        }

        if (! empty($peakDates)) {
            return [
                'title' => 'Пиковая дата недоступна',
                'message' => 'В диапазон попадают даты пиковой нагрузки: ' . implode(', ', $peakDates),
            ];
        }

        if (! empty($requestedDates)) {
            return [
                'title' => count($requestedDates) === 1 ? 'Дата занята' : 'Даты заняты',
                'message' => count($requestedDates) === 1
                    ? 'На ' . $requestedDates[0] . ' уже есть заявка'
                    : 'Уже есть заявки на: ' . implode(', ', $requestedDates),
            ];
        }

        if (! empty($selectedDates)) {
            return [
                'title' => 'Уже выбрано',
                'message' => count($selectedDates) === 1
                    ? 'Дата ' . $selectedDates[0] . ' уже входит в другой диапазон'
                    : 'Эти даты уже входят в другой диапазон',
            ];
        }

        return null;
    }

    protected function previewRangeForDate(string $date): array
    {
        if ($this->draftStartDate === null) {
            return [
                'preview_start' => false,
                'preview_inside' => false,
                'preview_end' => false,
                'preview_invalid' => false,
            ];
        }

        $start = Carbon::parse($this->draftStartDate)->startOfDay();
        $current = Carbon::parse($date)->startOfDay();

        if ($current->lt($start)) {
            return [
                'preview_start' => false,
                'preview_inside' => false,
                'preview_end' => false,
                'preview_invalid' => false,
            ];
        }

        if ($current->equalTo($start)) {
            return [
                'preview_start' => true,
                'preview_inside' => false,
                'preview_end' => false,
                'preview_invalid' => false,
            ];
        }

        $conflict = $this->rangeConflictReason($this->draftStartDate, $date);

        if ($conflict !== null) {
            return [
                'preview_start' => false,
                'preview_inside' => false,
                'preview_end' => false,
                'preview_invalid' => true,
            ];
        }

        return [
            'preview_start' => false,
            'preview_inside' => true,
            'preview_end' => true,
            'preview_invalid' => false,
        ];
    }

    public function removeRange(int $index): void
    {
        if (! isset($this->ranges[$index])) {
            return;
        }

        unset($this->ranges[$index]);
        $this->ranges = array_values($this->ranges);
        $this->persistDraft();
    }

    public function selectDate(string $date): void
    {
        $picked = Carbon::parse($date)->startOfDay();

        if ($picked->lt(now()->startOfDay())) {
            return;
        }

        $this->resetErrorBag('ranges');

        if ($this->isAlreadyRequested($date)) {
            $this->toast(
                'warning',
                'Дата занята',
                'На ' . Carbon::parse($date)->format('d.m') . ' уже есть заявка'
            );

            return;
        }

        $existingRangeIndex = $this->findRangeIndexByDate($date);

        if ($existingRangeIndex !== null) {
            $this->removeRange($existingRangeIndex);

            return;
        }

        if ($this->isPeakDay($date)) {
            $this->peakModalDate = Carbon::parse($date)->format('d.m.Y');
            $this->peakModalOpen = true;

            return;
        }

        $this->ranges[] = [
            'start' => $date,
            'end' => $date,
        ];

        $this->draftStartDate = null;

        $this->persistDraft();
    }

    public function calendarDays(): array
    {
        $start = $this->month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $this->month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $requestStatuses = $this->requestStatuses;
        $peakDates = $this->peakDatesForRange(
            $start->copy()->startOfDay(),
            $end->copy()->endOfDay(),
        );
        $days = [];

        while ($start->lte($end)) {
            $cursor = $start->copy();
            $date = $cursor->toDateString();

            $selected = $this->findRangeIndexByDate($date) !== null;
            $status = $requestStatuses[$date] ?? null;

            $days[] = [
                'date' => $date,
                'day' => $cursor->day,
                'current' => $cursor->month === $this->month->month,
                'past' => $cursor->lt(now()->startOfDay()),

                'selected' => $selected,
                'inside' => false,
                'start' => $selected,
                'end' => $selected,
                'draft_start' => false,

                'requested' => $status === 'pending',
                'approved' => $status === 'approved',
                'rejected' => $status === 'rejected',
                'sunday' => $status === null && $this->isSunday($date),
                'peak' => $status === null && isset($peakDates[$date]),

                'preview_start' => false,
                'preview_inside' => false,
                'preview_end' => false,
                'preview_invalid' => false,
            ];

            $start->addDay();
        }

        return $days;
    }

    protected function buildSuccessMessage(): string
    {
        $now = now()->setTimezone(config('app.timezone'));

        $start = $now->copy()->setTime(10, 0);
        $end = $now->copy()->setTime(18, 0);

        if ($now->between($start, $end)) {
            return 'Ответ ожидайте сегодня с 10:00 до 18:00';
        }

        if ($now->greaterThan($end)) {
            return 'Мы получили её после окончания рабочего дня. Ответ ожидайте завтра с 10:00 до 18:00';
        }

        return 'Ответ ожидайте сегодня с 10:00 до 18:00';
    }

    public function submit(): void
    {
        $sundayWarningConfirmed = $this->sundayWarningConfirmed;
        $this->sundayWarningConfirmed = false;

        if (empty($this->ranges)) {
            $this->toast(
                'warning',
                'Нет дат',
                'Сначала выбери хотя бы один день'
            );
            return;
        }

        if (blank(trim($this->comment))) {
            $this->addError('comment', 'Напишите причину отсутствия.');

            $this->toast(
                'warning',
                'Нужна причина',
                'Напиши, почему тебе нужен выходной'
            );
            return;
        }

        if (mb_strlen(trim($this->comment)) < 5) {
            $this->addError('comment', 'Причина должна быть чуть подробнее.');

            $this->toast(
                'warning',
                'Слишком коротко',
                'Опиши причину чуть подробнее'
            );
            return;
        }

        if (mb_strlen(trim($this->comment)) > 500) {
            $this->addError('comment', 'Максимум 500 символов.');

            $this->toast(
                'warning',
                'Слишком длинно',
                'Максимум 500 символов'
            );
            return;
        }

        try {
            $dates = [];

            $peakDates = [];

            foreach ($this->ranges as $range) {
                $rangeStart = Carbon::parse($range['start'])->startOfDay();
                $rangeEnd = Carbon::parse($range['end'])->startOfDay();
                $peakDateSet = $this->peakDatesForRange($rangeStart, $rangeEnd);

                foreach (CarbonPeriod::create($rangeStart, $rangeEnd) as $day) {
                    $date = $day->toDateString();

                    if ($this->isAlreadyRequested($date)) {
                        $this->toast(
                            'warning',
                            'Дата занята',
                            'Некоторые даты уже были отправлены раньше'
                        );
                        return;
                    }

                    if (isset($peakDateSet[$date])) {
                        $peakDates[] = Carbon::parse($date)->format('d.m.Y');
                        continue;
                    }

                    $dates[] = $date;
                }
            }

            if (! empty($peakDates)) {
                $message = 'Недоступные даты пиковой нагрузки: ' . implode(', ', array_unique($peakDates));
                $this->addError('ranges', $message);
                $this->toast('warning', 'Заявка не отправлена', $message);

                return;
            }

            $dates = array_values(array_unique($dates));
            sort($dates);

            if (collect($dates)->contains(fn (string $date) => $this->isSunday($date)) && ! $sundayWarningConfirmed) {
                $this->policyModalOpen = true;

                return;
            }

            $request = DB::transaction(function () use ($dates) {
    $request = DayOffRequest::create([
        'user_id' => Auth::id(),
        'reason' => trim($this->comment),
        'status' => 'pending',
    ]);

    foreach ($dates as $date) {
        DayOffRequestDay::create([
            'day_off_request_id' => $request->id,
            'user_id' => Auth::id(),
            'date' => $date,
            'status' => 'pending',
        ]);
    }

    activity()
        ->causedBy(Auth::user())
        ->performedOn($request)
        ->event('day_off_request_created')
        ->withProperties([
            'days_count' => count($dates),
            'dates' => $dates,
        ])
        ->log('Пользователь отправил заявку на выходной');

    return $request;
});

            try {
                SendTelegramNotificationJob::dispatch(
                    DayOffRequestTelegramService::class,
                    'sendCreated',
                    DayOffRequest::class,
                    $request->id,
                )->afterCommit();
            } catch (\Throwable $e) {
                Log::error('Telegram failed but request saved', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->ranges = [];
            $this->draftStartDate = null;
            $this->comment = '';
            $this->sundayWarningConfirmed = false;

            $this->resetErrorBag();
            $this->resetValidation();
            $this->clearDraft();

            $this->requestStatuses = $this->requestStatusesByDate();

            $this->successMessage = $this->buildSuccessMessage();
            $this->successSheetOpen = true;
        } catch (QueryException $e) {
            Log::error('Day off request duplicate date error', [
                'error' => $e->getMessage(),
                'ranges' => $this->ranges,
                'user_id' => Auth::id(),
            ]);

            $this->requestStatuses = $this->requestStatusesByDate();

            $this->toast(
                'warning',
                'Дата занята',
                'Похоже, часть дат уже успела попасть в другую заявку'
            );
        } catch (\Throwable $e) {
            Log::error('Day off request submit error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ranges' => $this->ranges,
                'user_id' => Auth::id(),
            ]);

            $this->addError('form', 'Произошла ошибка при отправке. Пожалуйста, попробуйте позже.');

            $this->toast(
                'error',
                'Не получилось отправить',
                'Попробуй ещё раз через пару минут',
                5000
            );
        }
    }
};

?>

<x-slot:header>
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
   <button
    type="button"
    onclick="history.back()"
    class="group flex h-[40px] min-w-[40px] items-center justify-center rounded-full cursor-pointer bg-[#E1E1E1] text-white backdrop-blur-md transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[#7D7D7D] hover:scale-[1.04] active:scale-[0.92]"
>
    <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4] transition-transform duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-[1.08]" />
</button>

        <span class="text-[18px] leading-none flex items-center justify-center">
            Запрос выходного
        </span>



<x-ui.guide-trigger />

    </div>
</x-slot:header>

<div
    x-data="{
        lastScrollTop: 0,
        buttonsHidden: false,
        nearBottom: false,

        init() {
            const el = this.$refs.scrollArea;
            if (!el) return;

            const onScroll = () => {
                const current = el.scrollTop;
                const maxScroll = el.scrollHeight - el.clientHeight;

                this.nearBottom = current >= (maxScroll - 140);

                if (this.nearBottom) {
                    this.buttonsHidden = false;
                    this.lastScrollTop = current;
                    return;
                }

                if (current <= 8) {
                    this.buttonsHidden = false;
                    this.lastScrollTop = current;
                    return;
                }

                if (current > this.lastScrollTop + 8) {
                    this.buttonsHidden = true;
                } else if (current < this.lastScrollTop - 8) {
                    this.buttonsHidden = false;
                }

                this.lastScrollTop = current;
            };

            onScroll();
            el.addEventListener('scroll', onScroll, { passive: true });
        },

scrollToNextRequired(hasRanges, hasComment) {
    this.buttonsHidden = false;

    this.$nextTick(() => {
        const scrollArea = this.$refs.scrollArea;

        if (!scrollArea) return;

        let target = null;

        if (!hasRanges) {
            target = this.$refs.calendarBlock;
        } else if (!hasComment) {
            target = this.$refs.reasonBlock;
        }

        if (!target) return;

        const top =
            target.offsetTop
            - scrollArea.offsetTop
            - 16;

        scrollArea.scrollTo({
            top: Math.max(top, 0),
            behavior: 'smooth'
        });

        if (target === this.$refs.reasonBlock) {
            setTimeout(() => {
                this.$refs.reasonInput?.focus();
            }, 500);
        }
    });
},

    }"
    class="flex h-full min-h-0 flex-col bg-[#F4F7FB]"
>
    <form wire:submit="submit" class="flex h-full min-h-0 flex-col">
        <div
            x-ref="scrollArea"
            class="flex-1 min-h-0 overflow-y-auto"
        >
            <div class="min-h-full rounded-t-[38px] bg-white">
              <div class="p-[20px] pb-[82px]">
                    <div class="mb-[24px]" x-ref="calendarBlock">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                           Когда нужен выходной?
                        </h2>

                        <div class="rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] py-[20px]">
                            <div class="mb-[18px] flex items-center justify-between">
                          <button
    type="button"
    wire:click="prevMonth"
    class="ml-[15px] group flex h-[40px] w-[40px] items-center justify-center rounded-full text-[#213259] transition-all duration-200 cursor-pointer active:scale-[0.96]"
>
    <x-heroicon-o-chevron-left class="h-[20px] w-[20px] stroke-[2.5px] transition-transform duration-200 group-hover:-translate-x-[2px]" />
</button>

                                <div class="text-[17px] tracking-[-0.02em] text-[#213259] capitalize">
                                    {{ $month->translatedFormat('F Y') }}
                                </div>

                     <button
    type="button"
    wire:click="nextMonth"
        class="mr-[15px] group flex h-[40px] w-[40px] items-center justify-center rounded-full text-[#213259] transition-all duration-200 cursor-pointer active:scale-[0.96]"
>
    <x-heroicon-o-chevron-right class="h-[20px] w-[20px] stroke-[2.5px] transition-transform duration-200 group-hover:translate-x-[2px]" />
</button>
                            </div>

                            <div class="mb-[12px] grid grid-cols-7">
                                @foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $weekday)
                                    <div class="text-center text-[11px] font-semibold uppercase tracking-[0.04em] text-[#7D8CA3]">
                                        {{ $weekday }}
                                    </div>
                                @endforeach
                            </div>

                            <div class="grid grid-cols-7 gap-y-[10px]">
                                @foreach ($this->calendarDays() as $day)
                                    @php
                                        $style = 'opacity:' . ($day['current'] ? '1' : '.28') . ';';
                                        $class = 'relative mx-auto flex h-[42px] w-[42px] items-center justify-center rounded-full text-[15px] transition duration-150';

                                        if ($day['past']) {
                                            $style .= 'color:#C3CDD8;';
                                            $class .= ' cursor-not-allowed';
                                        } elseif (!empty($day['peak'])) {
                                            $style .= 'background:#FDE2E2;color:#B42318;';
                                            $class .= ' cursor-pointer font-semibold ring-1 ring-[#F3A6A0]';
                                        } elseif (!empty($day['draft_start'])) {
                                            $style .= 'background:#213259;color:#FFFFFF;';
                                            $class .= ' font-semibold ';
                                        } elseif (!empty($day['selected'])) {
                                            $style .= 'background:#213259;color:#FFFFFF;';
                                            $class .= ' font-semibold ';
                                        } elseif (!empty($day['inside'])) {
                                            $style .= 'background:#DDE8F5;color:#213259;';
                                        } elseif (!empty($day['preview_inside'])) {
                                            $style .= 'background:#EAF2FB;color:#35527A;';
                                        } elseif (!empty($day['preview_end'])) {
                                            $style .= 'background:#D8E6F7;color:#213259;';
                                            $class .= ' font-medium';
                                        } elseif (!empty($day['approved'])) {
                                            $style .= 'background:#ECFDF3;color:#027A48;';
                                        } elseif (!empty($day['requested'])) {
                                            $style .= 'background:#F6EFE4;color:#8A5A2B;';
                                        } elseif (!empty($day['rejected'])) {
                                            $style .= 'background:#FDECEC;color:#C74A4A;';
                                        } else {
                                            $style .= 'color:#213259;';
                                            $class .= ' hover:bg-white active:scale-[0.96]';
                                        }
                                    @endphp

                                    <button
                                        type="button"
                                        wire:click="selectDate('{{ $day['date'] }}')"
                                        class="{{ $class }}"
                                        style="{{ $style }}"
                                        @disabled(!$day['current'] || $day['past'])
                                        @if (!empty($day['peak']))
                                            title="Показать причину недоступности"
                                            aria-label="{{ $day['day'] }}: дата недоступна из-за высокой загрузки"
                                        @elseif (!empty($day['sunday']))
                                            title="Воскресенье можно выбрать, перед отправкой потребуется подтверждение"
                                            aria-label="{{ $day['day'] }}: воскресенье, перед отправкой потребуется подтверждение"
                                        @endif
                                    >
                                        {{ $day['day'] }}

                                        @if (!empty($day['peak']))
                                            <span class="absolute bottom-[3px] left-1/2 -translate-x-1/2 text-[7px] font-semibold uppercase leading-none text-[#B42318]">пик</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>

                        </div>
                    </div>

                    <div class="mb-[8px]" x-ref="reasonBlock">
                        <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                         Опишите причину
                        </h2>

                        <x-ui.textarea
                                x-ref="reasonInput"
    wire:model.live.debounce.500ms="comment"
                            rows="4"
                            maxlength="500"
                            placeholder="Например: нужен выходной в выбранные даты"
                        />

                        @error('comment')
                            <div class="mt-[8px] px-[4px] text-[15px] text-[#D92D20]">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    @error('form')
                        <div class="mt-[14px] rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
                            ⚠️ {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>

<div
    x-ref="footerBar"
    class="shrink-0 overflow-hidden bg-transparent"
    :class="buttonsHidden ? 'max-h-0' : 'max-h-[82px]'"
    style="transition: max-height 300ms ease;"
>
           <div class="border-t border-[#E3EAF0] bg-white/95 px-5 pb-5 pt-4 backdrop-blur transition-all duration-300 supports-[backdrop-filter]:bg-white/80">
                <div class="grid grid-cols-3 gap-[10px]">
                    <div class="col-span-1">
                        <x-ui.button
                            type="button"
                            variant="secondary"
                            wire:click="resetForm"
                        >
                            Сбросить
                        </x-ui.button>
                    </div>

                    <div class="col-span-2">
                     <x-ui.button
    type="submit"
    variant="primary"
    :progress="$this->formProgress"
    wire:loading.attr="disabled"
    wire:target="submit"
    x-on:click="
        if (!{{ $this->formReady ? 'true' : 'false' }}) {
            $event.preventDefault();

            scrollToNextRequired(
                {{ ! empty($this->ranges) ? 'true' : 'false' }},
                {{ mb_strlen(trim($this->comment)) >= 5 ? 'true' : 'false' }}
            );
        }
    "
>
    <span wire:loading.remove wire:target="submit">
        {{ $this->formButtonText }}
    </span>

    <span wire:loading wire:target="submit" class="inline-flex items-center gap-[2px]">
        <span>Сохраняем</span>

        <span class="inline-flex items-end leading-none">
            <span class="animate-[dotFade_1.4s_infinite]">.</span>
            <span class="animate-[dotFade_1.4s_infinite_0.2s]">.</span>
            <span class="animate-[dotFade_1.4s_infinite_0.4s]">.</span>
        </span>
    </span>
</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div x-data="{ modalOpen: @entangle('policyModalOpen').live }">
        <x-ui.modal x-model="modalOpen">
            <div class="p-5 text-center">
                <img
                    class="mt-[28px] h-[135px] w-full object-contain"
                    src="{{ asset('images/warning.webp') }}"
                    alt="warning cat"
                >

                <h1 class="mt-[28px] text-[22px]! font-semibold tracking-[-0.02em] text-[#111111]">
                    Подтвердите заявку на воскресенье
                </h1>

                <p class="pt-[18px] text-[16px] leading-[1.5] text-black/55 flex flex-col">
                    <span>Согласование выходного на воскресенье возможно только в пятницу, когда будет понятна ожидаемая нагрузка и сможем ли мы выполнить свои обязательства перед клиентами.</span>
                </p>

                <div class="flex gap-[10px] pt-[32px]">
                    <x-ui.button
                        variant="secondary"
                        type="button"
                        wire:click="closePolicyModal"
                    >
                        Вернуться
                    </x-ui.button>

                    <x-ui.button
                        variant="primary"
                        type="button"
                        wire:click="confirmSundaySubmission"
                    >
                        Понятно, отправить заявку
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    </div>

    <div x-data="{ sheetOpen: @entangle('successSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5 text-center">
                <img
                    class="mt-[28px] h-[135px] w-full object-contain"
                    src="{{ asset('images/success.webp') }}"
                    alt="success"
                >

                <h1 class="mt-[28px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Заявка на выходной успешно отправлена
                </h1>

                <p class="pt-[18px] text-[15px] leading-[1.5] text-black/55">
                    {{ $successMessage }}
                </p>

                <div class="flex gap-[10px] pt-[32px]">
                <x-ui.button
    variant="secondary"
    href="{{ route('page-profile.applications') }}"
>
    К заявкам
</x-ui.button>

<x-ui.button
    variant="primary"
    @click="sheetOpen = false"
>
    Понятно
</x-ui.button>
            </div>
        </x-ui.bottom-sheet>

    </div>

    <div x-data="{ modalOpen: @entangle('peakModalOpen').live }">
        <x-ui.modal x-model="modalOpen">
            <div class="p-5 text-center">
                <h1 class="text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Эта дата сейчас недоступна
                </h1>

                <p class="pt-[18px] text-[16px] leading-[1.5] text-black/60">
                    На {{ $peakModalDate ?? 'этот день' }} ожидается высокая загрузка, поэтому оформить выходной сейчас нельзя.
                    Выберите другую дату или свяжитесь с администратором, если ситуация срочная.
                </p>

                <div class="pt-[28px]">
                    <x-ui.button
                        variant="primary"
                        type="button"
                        wire:click="closePeakModal"
                    >
                        Понятно
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    </div>

<x-ui.guide guide-key="day-off-request-guide-v4">
    <div
        x-data="{
            current: 0,

            steps: [
                {
                    image: '/images/weekend/1.webp',
                    title: 'Запрос выходного',
                    text: 'Выберите даты, укажите причину и отправьте заявку на согласование.',
                },
                {
                        image: '/images/weekend/2.webp',
                    title: 'Выберите даты',
                    text: 'Нажимайте на нужные даты по одной. Повторное нажатие убирает дату из выбора.',
                },
                {
                             image: '/images/weekend/3.webp',
                    title: 'Можно выбрать несколько дней',
                    text: 'Выберите все нужные даты. Они добавятся в одну заявку отдельными днями.',
                },
                {
                             image: '/images/weekend/4.webp',
                     title: 'Воскресенье можно выбрать',
                     text: 'Заявку на воскресенье можно отправить заранее. Перед отправкой появится предупреждение, а решение примут в пятницу с учётом ожидаемой нагрузки.',
                },
                  {
                             image: '/images/weekend/5.webp',
                    title: 'Добавьте причину',
                        text: 'Коротко объясните, почему нужен выходной. Так заявку будет проще согласовать.',
                },
                {
                            image: '/images/weekend/6.webp',
                    title: 'Отправьте заявку',
                    text: 'После отправки заявка попадёт на рассмотрение, а статус вы можете посмотреть в разделе «Мои заявки».',
                },
            ],

            init() {
                window.addEventListener('open-guide', () => {
                    this.current = 0;
                });
            },

            next() {
                if (this.current >= this.steps.length - 1) {
                    window.dispatchEvent(new CustomEvent('close-guide', {
                        detail: { save: true }
                    }));

                    return;
                }

                this.current++;
            },

            back() {
                if (this.current > 0) {
                    this.current--;
                }
            },
        }"
        class="flex min-h-[80vh] flex-col bg-white"
    >
        <div class="flex items-center justify-between px-[20px] pt-[20px]">
            <div class="flex gap-[6px]">
                <template x-for="(_, index) in steps" :key="index">
                    <div
                        class="h-[5px] rounded-full transition-all duration-300"
                        :class="index <= current ? 'w-[26px] bg-[#111111]' : 'w-[5px] bg-[#DADADA]'"
                    ></div>
                </template>
            </div>

            <button
                type="button"
                onclick="window.dispatchEvent(new CustomEvent('close-guide', { detail: { save: true } }))"
                class="flex h-[42px] w-[42px] items-center justify-center rounded-full bg-[#F4F4F4] text-[#111111] transition-all duration-300 active:scale-[0.94]"
            >
                <x-heroicon-o-x-mark class="h-[20px] w-[20px] stroke-[2.4]" />
            </button>
        </div>

        <div class="flex flex-1 flex-col px-[20px] pt-[18px] pb-[8px]">
            <div class="mb-[18px] rounded-[18px] bg-[#F7F9FC] px-[14px] py-[13px] text-[14px] leading-[1.5] text-[#52627A]">
                <p class="font-semibold text-[#213259]">Как оформить выходной</p>
                <ul class="mt-[7px] list-disc space-y-[4px] pl-[18px] text-left">
                    <li>Выберите одну или несколько свободных дат в календаре.</li>
                    <li>Красная дата недоступна из-за высокой загрузки — нажмите на неё, чтобы увидеть причину.</li>
                    <li>Заполните причину: это обязательное поле, минимум несколько слов.</li>
                    <li>После отправки заявка появится в разделе «Мои заявки». О решении сообщит система и Telegram.</li>
                    <li>Если ответ задерживается, проверьте статус в «Моих заявках» и обратитесь к администратору.</li>
                </ul>
            </div>
            <div class="flex flex-1 flex-col">
                <div class="relative flex h-[330px] shrink-0 items-center justify-center overflow-hidden rounded-[36px] bg-gradient-to-br from-[#F4F7FB] via-[#EEF4FF] to-[#F7F2EC] p-[18px] ">
              

                    <img
                        :src="steps[current].image"
                        :alt="steps[current].title"
                        class="relative z-[1] max-h-full max-w-full object-contain "
                    >
                </div>

                <div class="min-h-[100px] pt-[18px] text-center">
                    <h1
                        class=" leading-[1.05] tracking-[-0.05em] text-[#111111]"
                        x-text="steps[current].title"
                    ></h1>

                    <h2
                        class="mx-auto mt-[8px] max-w-[330px] text-[15px] leading-[1.45] text-black/55"
                        x-text="steps[current].text"
                    ></h2>
                </div>
            </div>
        </div>

        <div class="px-[20px] pb-[20px]">
            <div class="grid grid-cols-2 gap-[10px]">
                <x-ui.button
                    type="button"
                    variant="secondary"
                    @click="back()"
                    x-bind:class="current === 0 ? 'opacity-0 pointer-events-none' : ''"
                >
                    Назад
                </x-ui.button>

                <x-ui.button
                    type="button"
                    variant="primary"
                    progress="100"
                    @click="next()"
                >
                    <span x-text="current === steps.length - 1 ? 'Понятно' : 'Далее'"></span>
                </x-ui.button>
            </div>
        </div>
    </div>
</x-ui.guide>
</div>
