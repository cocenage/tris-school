<?php

use App\Models\VacationRequest;
use App\Models\VacationRequestDay;
use App\Services\Forms\VacationRequestTelegramService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    public Carbon $month;

    public bool $successSheetOpen = false;

    public ?string $draftStartDate = null;
    public ?string $draftEndDate = null;

    public string $comment = '';
    public ?string $successMessage = null;

    public array $requestStatuses = [];

    public string $adminChatUrl = '';

    public function mount(): void
    {
        Carbon::setLocale('ru');

        $this->month = now()->startOfMonth();
        $this->adminChatUrl = (string) config('services.vacation.admin_chat_url', '');

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
    $total = 3;
    $done = 0;

    if (filled($this->draftStartDate)) {
        $done++;
    }

    if (filled($this->draftEndDate)) {
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
        $this->formProgress >= 70 => 'Почти готово',
        $this->formProgress >= 30 => 'Продолжайте',
        default => 'Заполните',
    };
}

    protected function draftKey(): string
    {
        return 'vacation_request_draft_' . (Auth::id() ?: 'guest');
    }

    protected function persistDraft(): void
    {
        session()->put($this->draftKey(), [
            'comment' => $this->comment,
            'draftStartDate' => $this->draftStartDate,
            'draftEndDate' => $this->draftEndDate,
            'month' => $this->month->toDateString(),
        ]);
    }

    protected function restoreDraft(): void
    {
        $draft = session()->get($this->draftKey());

        if (! is_array($draft)) {
            return;
        }

        $this->comment = (string) ($draft['comment'] ?? '');
        $this->draftStartDate = ! empty($draft['draftStartDate']) ? (string) $draft['draftStartDate'] : null;
        $this->draftEndDate = ! empty($draft['draftEndDate']) ? (string) $draft['draftEndDate'] : null;

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

    public function closeSuccessSheet(): void
    {
        $this->successSheetOpen = false;
        $this->successMessage = null;
    }

    public function resetForm(): void
    {
        $this->draftStartDate = null;
        $this->draftEndDate = null;
        $this->comment = '';

        $this->resetErrorBag();
        $this->resetValidation();
        $this->clearDraft();
    }

    protected function requestStatusesByDate(): array
    {
        return VacationRequestDay::query()
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

    protected function conflictReason(string $startDate, string $endDate): ?array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            return [
                'title' => 'Неверный период',
                'message' => 'Дата окончания не может быть раньше даты начала',
            ];
        }

        $requestedDates = [];

        foreach (CarbonPeriod::create($start, $end) as $periodDate) {
            $date = $periodDate->toDateString();

            if ($this->isAlreadyRequested($date)) {
                $requestedDates[] = Carbon::parse($date)->format('d.m');
            }
        }

        if (! empty($requestedDates)) {
            return [
                'title' => count($requestedDates) === 1 ? 'Дата занята' : 'Даты заняты',
                'message' => count($requestedDates) === 1
                    ? 'На ' . $requestedDates[0] . ' уже есть заявка'
                    : 'Уже есть заявки на: ' . implode(', ', $requestedDates),
            ];
        }

        return null;
    }

    public function selectDate(string $date): void
    {
        $picked = Carbon::parse($date)->startOfDay();

        if ($picked->lt(now()->startOfDay())) {
            return;
        }

        if ($this->draftStartDate === null) {
            if ($this->isAlreadyRequested($date)) {
                $this->toast(
                    'warning',
                    'Дата занята',
                    'На ' . Carbon::parse($date)->format('d.m') . ' уже есть заявка'
                );
                return;
            }

            $this->draftStartDate = $date;
            $this->draftEndDate = null;
            $this->persistDraft();
            return;
        }

        if ($this->draftEndDate === null) {
            if ($picked->lt(Carbon::parse($this->draftStartDate)->startOfDay())) {
                if ($this->isAlreadyRequested($date)) {
                    $this->toast(
                        'warning',
                        'Дата занята',
                        'На ' . Carbon::parse($date)->format('d.m') . ' уже есть заявка'
                    );
                    return;
                }

                $this->draftStartDate = $date;
                $this->persistDraft();
                return;
            }

            $reason = $this->conflictReason($this->draftStartDate, $date);

            if ($reason !== null) {
                $this->toast(
                    'warning',
                    $reason['title'],
                    $reason['message'],
                    4200
                );
                return;
            }

            $this->draftEndDate = $date;
            $this->persistDraft();
            return;
        }

        if ($this->isAlreadyRequested($date)) {
            $this->toast(
                'warning',
                'Дата занята',
                'На ' . Carbon::parse($date)->format('d.m') . ' уже есть заявка'
            );
            return;
        }

        $this->draftStartDate = $date;
        $this->draftEndDate = null;
        $this->persistDraft();
    }

    protected function previewRangeForDate(string $date): array
    {
        if ($this->draftStartDate === null) {
            return [
                'selected' => false,
                'inside' => false,
                'start' => false,
                'end' => false,
            ];
        }

        $start = Carbon::parse($this->draftStartDate)->startOfDay();
        $end = $this->draftEndDate
            ? Carbon::parse($this->draftEndDate)->startOfDay()
            : null;
        $current = Carbon::parse($date)->startOfDay();

        if ($end === null) {
            return [
                'selected' => $current->equalTo($start),
                'inside' => false,
                'start' => $current->equalTo($start),
                'end' => $current->equalTo($start),
            ];
        }

        return [
            'selected' => $current->betweenIncluded($start, $end),
            'inside' => $current->gt($start) && $current->lt($end),
            'start' => $current->equalTo($start),
            'end' => $current->equalTo($end),
        ];
    }

    public function calendarDays(): array
    {
        $start = $this->month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $this->month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $requestStatuses = $this->requestStatuses;
        $days = [];

        while ($start->lte($end)) {
            $cursor = $start->copy();
            $date = $cursor->toDateString();

            $status = $requestStatuses[$date] ?? null;
            $preview = $this->previewRangeForDate($date);

            $days[] = [
                'date' => $date,
                'day' => $cursor->day,
                'current' => $cursor->month === $this->month->month,
                'past' => $cursor->lt(now()->startOfDay()),
                'requested' => $status === 'pending',
                'approved' => $status === 'approved',
                'rejected' => $status === 'rejected',
                ...$preview,
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

    protected function selectedDaysCount(): int
    {
        if (! $this->draftStartDate) {
            return 0;
        }

        if (! $this->draftEndDate) {
            return 1;
        }

        return Carbon::parse($this->draftStartDate)
            ->startOfDay()
            ->diffInDays(Carbon::parse($this->draftEndDate)->startOfDay()) + 1;
    }

    public function submit(VacationRequestTelegramService $telegram): void
    {
        if (! $this->draftStartDate) {
            $this->toast(
                'warning',
                'Нет дат',
                'Сначала выбери период отпуска'
            );
            return;
        }

        if (! $this->draftEndDate) {
            $this->toast(
                'warning',
                'Период не завершён',
                'Выбери дату окончания отпуска'
            );
            return;
        }

        if (blank(trim($this->comment))) {
            $this->addError('comment', 'Напишите причину отсутствия.');

            $this->toast(
                'warning',
                'Нужна причина',
                'Напиши, почему тебе нужен отпуск'
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

        $reason = $this->conflictReason($this->draftStartDate, $this->draftEndDate);

        if ($reason !== null) {
            $this->toast(
                'warning',
                $reason['title'],
                $reason['message'],
                4200
            );
            return;
        }

        try {
            $start = Carbon::parse($this->draftStartDate)->startOfDay();
            $end = Carbon::parse($this->draftEndDate)->startOfDay();

            $dates = [];

            foreach (CarbonPeriod::create($start, $end) as $day) {
                $date = $day->toDateString();

                if ($this->isAlreadyRequested($date)) {
                    $this->toast(
                        'warning',
                        'Дата занята',
                        'Некоторые даты уже были отправлены раньше'
                    );
                    return;
                }

                $dates[] = $date;
            }

            $request = DB::transaction(function () use ($dates) {
    $request = VacationRequest::create([
        'user_id' => Auth::id(),
        'start_date' => $this->draftStartDate,
        'end_date' => $this->draftEndDate,
        'days_count' => count($dates),
        'reason' => trim($this->comment),
        'status' => 'pending',
    ]);

    foreach ($dates as $date) {
        VacationRequestDay::create([
            'vacation_request_id' => $request->id,
            'user_id' => Auth::id(),
            'date' => $date,
            'status' => 'pending',
        ]);
    }

    activity()
        ->causedBy(Auth::user())
        ->performedOn($request)
        ->event('vacation_request_created')
        ->withProperties([
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'days_count' => count($dates),
            'dates' => $dates,
        ])
        ->log('Пользователь отправил заявку на отпуск');

    return $request;
});

            try {
                $telegram->sendCreated($request);
            } catch (\Throwable $e) {
                Log::error('Vacation telegram failed but request saved', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->draftStartDate = null;
            $this->draftEndDate = null;
            $this->comment = '';

            $this->resetErrorBag();
            $this->resetValidation();
            $this->clearDraft();

            $this->requestStatuses = $this->requestStatusesByDate();

            $this->successMessage = $this->buildSuccessMessage();
            $this->successSheetOpen = true;
        } catch (QueryException $e) {
            Log::error('Vacation request duplicate date error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            $this->requestStatuses = $this->requestStatusesByDate();

            $this->toast(
                'warning',
                'Дата занята',
                'Похоже, часть дат уже успела попасть в другую заявку'
            );
        } catch (\Throwable $e) {
            Log::error('Vacation request submit error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
        </button>

        <span class="text-[18px] leading-none flex items-center justify-center">
            Заявка на отпуск
        </span>

        <button
            type="button"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
        </button>
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
        }
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
                    <div class="mb-[24px]">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                            Выберите период отпуска
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
                                        } elseif (!empty($day['start']) && !empty($day['end'])) {
                                            $style .= 'background:#213259;color:#FFFFFF;';
                                            $class .= ' font-semibold shadow-[0_8px_18px_rgba(33,50,89,0.22)]';
                                        } elseif (!empty($day['start']) || !empty($day['end'])) {
                                            $style .= 'background:#213259;color:#FFFFFF;';
                                            $class .= ' font-semibold shadow-[0_8px_18px_rgba(33,50,89,0.22)]';
                                        } elseif (!empty($day['inside'])) {
                                            $style .= 'background:#DDE8F5;color:#213259;';
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
                                    >
                                        {{ $day['day'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="mb-[8px]">
                        <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                            Опишите причину отпуска
                        </h2>

                        <textarea
                            wire:model.live.debounce.500ms="comment"
                            rows="4"
                            maxlength="500"
                            placeholder="Например: планирую отпуск в выбранные даты"
                            class="w-full rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[20px] py-[15px] text-[16px] placeholder:text-black/35 outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0"
                        ></textarea>

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
    :disabled="! $this->formReady"
    wire:loading.attr="disabled"
    wire:target="submit"
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

    <div x-data="{ sheetOpen: @entangle('successSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5 text-center">
                <img
                    class="mt-[28px] h-[135px] w-full object-contain"
                    src="{{ asset('images/success.webp') }}"
                    alt="success"
                >

                <h1 class="mt-[28px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                   Заявка на отпуск успешно отправлена
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
            </div>
        </x-ui.bottom-sheet>
    </div>
</div>