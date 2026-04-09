<?php

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component
{
    public Carbon $month;

    public bool $calendarOpen = false;

    public bool $policyModalOpen = false;
    public bool $successSheetOpen = false;

    public ?string $policyDate = null;
    public ?int $activeRangeIndex = null;

    public array $ranges = [];
    public string $comment = '';
    public ?string $successMessage = null;

    public string $adminChatUrl = 'https://t.me/admin_username';

    public function mount(): void
    {
        Carbon::setLocale('ru');
        $this->month = now()->startOfMonth();
    }

    public function openCalendar(): void
    {
        $this->calendarOpen = true;
    }

    public function closeCalendar(): void
    {
        $this->calendarOpen = false;
        $this->resetErrorBag('ranges');
    }

    public function prevMonth(): void
    {
        $this->calendarOpen = true;
        $this->month = $this->month->copy()->subMonth()->startOfMonth();
    }

    public function nextMonth(): void
    {
        $this->calendarOpen = true;
        $this->month = $this->month->copy()->addMonth()->startOfMonth();
    }

    public function openPolicyModal(string $date): void
    {
        $this->policyDate = $date;
        $this->policyModalOpen = true;
        $this->calendarOpen = true;
    }

    public function closePolicyModal(): void
    {
        $this->policyModalOpen = false;
        $this->policyDate = null;
        $this->calendarOpen = true;
    }

    public function closeSuccessSheet(): void
    {
        $this->successSheetOpen = false;
        $this->successMessage = null;
    }

    public function removeRange(int $index): void
    {
        unset($this->ranges[$index]);
        $this->ranges = array_values($this->ranges);

        if ($this->activeRangeIndex === $index) {
            $this->activeRangeIndex = null;
            return;
        }

        if ($this->activeRangeIndex !== null && $index < $this->activeRangeIndex) {
            $this->activeRangeIndex--;
        }
    }

    public function finishCurrentRange(): void
    {
        $this->activeRangeIndex = null;
        $this->resetErrorBag('ranges');
        $this->calendarOpen = false;
    }

    public function selectDate(string $date): void
    {
        $picked = Carbon::parse($date)->startOfDay();

        if ($picked->lt(now()->startOfDay())) {
            return;
        }

        if ($this->isAlreadyRequested($date)) {
            return;
        }

        if ($this->isSundayOrMonday($date)) {
            $this->openPolicyModal($date);
            return;
        }

        if ($this->activeRangeIndex === null || ! isset($this->ranges[$this->activeRangeIndex])) {
            array_unshift($this->ranges, [
                'start' => $date,
                'end' => $date,
            ]);

            $this->activeRangeIndex = 0;
            $this->resetErrorBag('ranges');
            return;
        }

        $active = $this->ranges[$this->activeRangeIndex];
        $start = Carbon::parse($active['start'])->startOfDay();
        $end = Carbon::parse($active['end'])->startOfDay();

        if (! $start->equalTo($end)) {
            array_unshift($this->ranges, [
                'start' => $date,
                'end' => $date,
            ]);

            $this->activeRangeIndex = 0;
            $this->resetErrorBag('ranges');
            return;
        }

        if ($picked->equalTo($start)) {
            $this->activeRangeIndex = null;
            $this->resetErrorBag('ranges');
            $this->calendarOpen = false;
            return;
        }

        if ($picked->lt($start)) {
            array_unshift($this->ranges, [
                'start' => $date,
                'end' => $date,
            ]);

            $this->activeRangeIndex = 0;
            $this->resetErrorBag('ranges');
            return;
        }

        foreach (CarbonPeriod::create($start, $picked) as $periodDate) {
            $periodString = $periodDate->toDateString();

            if ($this->isSundayOrMonday($periodString)) {
                $this->openPolicyModal($periodString);
                return;
            }

            if (
                $periodString !== $start->toDateString()
                && (
                    $this->isAlreadyRequested($periodString)
                    || $this->isInsideExistingRange($periodString, $this->activeRangeIndex)
                )
            ) {
                $this->addError('ranges', 'В этом диапазоне уже есть выбранная или отправленная дата.');
                return;
            }
        }

        $this->ranges[$this->activeRangeIndex]['end'] = $date;
        $this->activeRangeIndex = null;
        $this->resetErrorBag('ranges');
        $this->calendarOpen = false;
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

    protected function isSundayOrMonday(string $date): bool
    {
        return in_array(Carbon::parse($date)->dayOfWeekIso, [1, 7], true);
    }

    protected function requestedDates(): array
    {
        return DayOffRequestDay::query()
            ->where('user_id', Auth::id())
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }

    protected function isAlreadyRequested(string $date): bool
    {
        return in_array($date, $this->requestedDates(), true);
    }

    public function calendarDays(): array
    {
        $start = $this->month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $this->month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $days = [];

        while ($start->lte($end)) {
            $cursor = $start->copy();
            $date = $cursor->toDateString();

            $selected = false;
            $inside = false;
            $rangeStart = false;
            $rangeEnd = false;
            $requested = $this->isAlreadyRequested($date);
            $isPolicyDay = $this->isSundayOrMonday($date);

            foreach ($this->ranges as $range) {
                $rangeStartDate = Carbon::parse($range['start'])->startOfDay();
                $rangeEndDate = Carbon::parse($range['end'])->startOfDay();

                if ($cursor->equalTo($rangeStartDate) && $cursor->equalTo($rangeEndDate)) {
                    $selected = true;
                    $rangeStart = true;
                    $rangeEnd = true;
                    continue;
                }

                if ($cursor->equalTo($rangeStartDate)) {
                    $selected = true;
                    $rangeStart = true;
                    continue;
                }

                if ($cursor->equalTo($rangeEndDate)) {
                    $selected = true;
                    $rangeEnd = true;
                    continue;
                }

                if ($cursor->gt($rangeStartDate) && $cursor->lt($rangeEndDate)) {
                    $inside = true;
                }
            }

            $days[] = [
                'date' => $date,
                'day' => $cursor->day,
                'current' => $cursor->month === $this->month->month,
                'past' => $cursor->lt(now()->startOfDay()),
                'selected' => $selected,
                'inside' => $inside,
                'start' => $rangeStart,
                'end' => $rangeEnd,
                'requested' => $requested,
                'policy' => $isPolicyDay,
            ];

            $start->addDay();
        }

        return $days;
    }

    protected function buildSuccessMessage(): string
    {
        $now = now()->setTimezone(config('app.timezone', 'Europe/Stockholm'));

        $start = $now->copy()->setTime(10, 0);
        $end = $now->copy()->setTime(18, 0);

        if ($now->between($start, $end)) {
            return 'Ваш запрос успешно отправлен. Ответ ожидайте сегодня с 10:00 до 18:00.';
        }

        if ($now->greaterThan($end)) {
            return 'Ваш запрос успешно отправлен. Мы получили его после окончания рабочего дня. Ответ ожидайте завтра с 10:00 до 18:00.';
        }

        return 'Ваш запрос успешно отправлен. Ответ ожидайте сегодня с 10:00 до 18:00.';
    }

    protected function sendTelegramNotification(array $dates, DayOffRequest $request): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id_day');
        $threadId = config('services.telegram.thread_id_day');

        if (blank($token) || blank($chatId)) {
            Log::warning('Telegram notification skipped: missing credentials');
            return;
        }

        $user = auth()->user();

        Carbon::setLocale('ru');

        $formattedDates = collect($dates)
            ->map(fn (string $date) => Carbon::parse($date)->translatedFormat('d.m.Y (l)'))
            ->implode("\n• ");

        $name = $user?->name ?: 'Неизвестный пользователь';

        $tgRaw = $user?->tg
            ? ltrim(trim($user->tg), '@')
            : null;

        $tg = ($tgRaw && preg_match('/^[A-Za-z0-9_]{5,32}$/', $tgRaw))
            ? $tgRaw
            : null;

        $dipText = isset($user?->dip)
            ? ($user->dip ? 'dip' : 'no dip')
            : '—';

        $text = "📌 <b>Новая заявка на выходной</b>\n\n";

        if ($tg) {
            $text .= "👤 <b>Сотрудник:</b> <a href='https://t.me/{$tg}'>" . e($name) . "</a>\n";
        } else {
            $text .= "👤 <b>Сотрудник:</b> " . e($name) . "\n";
        }

        $text .= "🏷️ <b>Dip:</b> {$dipText}\n";
        $text .= "🆔 <b>ID заявки:</b> {$request->id}\n";
        $text .= "📅 <b>Даты:</b>\n• {$formattedDates}\n\n";
        $text .= "💬 <b>Причина:</b>\n<blockquote>" . e(trim($this->comment)) . "</blockquote>";

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (filled($threadId)) {
            $payload['message_thread_id'] = (int) $threadId;
        }

        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            $payload
        );

        if ($response->failed()) {
            Log::error('Telegram send failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'request_id' => $request->id,
            ]);
        }
    }

    public function submit(): void
    {
        $this->validate([
            'comment' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'comment.required' => 'Напишите причину отсутствия.',
            'comment.min' => 'Причина должна быть чуть подробнее.',
            'comment.max' => 'Максимум 500 символов.',
        ]);

        if (empty($this->ranges)) {
            $this->addError('ranges', 'Выберите хотя бы одну дату.');
            return;
        }

        try {
            $dates = [];

            foreach ($this->ranges as $range) {
                $rangeStart = Carbon::parse($range['start'])->startOfDay();
                $rangeEnd = Carbon::parse($range['end'])->startOfDay();

                foreach (CarbonPeriod::create($rangeStart, $rangeEnd) as $day) {
                    $date = $day->toDateString();

                    if ($this->isAlreadyRequested($date)) {
                        $this->addError('ranges', 'Некоторые даты уже были отправлены раньше.');
                        return;
                    }

                    $dates[] = $date;
                }
            }

            $dates = array_values(array_unique($dates));
            sort($dates);

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

                return $request;
            });

            try {
                $this->sendTelegramNotification($dates, $request);
            } catch (\Throwable $e) {
                Log::error('Telegram failed but request saved', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->ranges = [];
            $this->activeRangeIndex = null;
            $this->comment = '';
            $this->calendarOpen = false;
            $this->resetErrorBag();
            $this->resetValidation();

            $this->successMessage = $this->buildSuccessMessage();
            $this->successSheetOpen = true;
        } catch (\Throwable $e) {
            Log::error('Day off request submit error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ranges' => $this->ranges,
                'user_id' => Auth::id(),
            ]);

            $this->addError('form', 'Произошла ошибка при отправке. Пожалуйста, попробуйте позже.');
        }
    }

    public function formatRange(array $range): string
    {
        $start = Carbon::parse($range['start'])->startOfDay();
        $end = Carbon::parse($range['end'])->startOfDay();

        if ($start->equalTo($end)) {
            return $start->format('d.m');
        }

        return $start->format('d.m') . ' — ' . $end->format('d.m');
    }
};
?>
<div class="h-full flex flex-col overflow-hidden">
   <div class="w-full h-[90px] flex items-center justify-between px-[30px]">
        <button type="button" onclick="history.back()"
            class="w-[44px] h-[44px] rounded-full bg-[#F3F3F3] hover:bg-[#E7E7E7] active:bg-[#DCDCDC] duration-200 flex items-center justify-center shrink-0">
            <x-heroicon-o-arrow-left class="w-[30px] h-[30px] stroke-[2.5]" />
        </button>

       
        <h2>Заявки</h2>
        <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2.5] shrink-0" />

    </div>
        <div class="flex-1 overflow-hidden bg-white rounded-t-[50px]">
        <div class="w-full h-full bg-white rounded-t-[50px] overflow-y-auto">
            <div class="w-full flex pt-[20px] pb-[30px] pl-[20px] justify-center">
                <h1>Запрос выходного</h1>
            </div>
<form wire:submit="submit" class="space-y-[20px] px-[20px] relative">
    <div class="relative z-20" x-data="{ open: @entangle('calendarOpen').live }">
        <label class="block text-[16px] font-medium mb-[15px]">
            Когда вас не будет?
        </label>
<div
    class="min-h-[45px] w-full rounded-[100px] bg-[#E1E1E1] px-[20px] flex items-center flex-wrap gap-[8px] cursor-pointer"
    @click="open = true; $wire.openCalendar()"
>
    @forelse ($ranges as $index => $range)
        <button
            type="button"
            wire:click.stop="removeRange({{ $index }})"
            class="px-[11px] py-[6px] gap-[5px] rounded-full bg-white flex items-center shrink-0 overflow-hidden hover:bg-[#FEE2E2] active:scale-[0.95] transition"
        >
            <div class="text-[16px] whitespace-nowrap">
                {{ $this->formatRange($range) }}
            </div>

            <x-heroicon-o-x-mark class="w-[16px] h-[16px] stroke-[2.5] opacity-50" />
        </button>
    @empty
        <span class="text-[16px] opacity-50">Выберите даты</span>
    @endforelse

    <div class="ml-auto shrink-0">
        <x-heroicon-o-calendar-days class="w-[20px] h-[20px]" />
    </div>
</div>

        @error('ranges')
            <div class="mt-[10px] text-[14px] text-[#991B1B]">{{ $message }}</div>
        @enderror

        <div
            x-show="open"
            x-transition
            x-on:click.outside="
                if (!$wire.policyModalOpen) {
                    open = false;
                    $wire.closeCalendar();
                }
            "
            class="absolute left-0 top-full mt-[10px] w-full rounded-[23px] border border-[#E1E1E1] bg-white p-[15px]"
        >
            <div class="flex items-center justify-between mb-[15px]">
                <button
                    type="button"
                    wire:click="prevMonth"
                    class="w-[36px] h-[36px] rounded-full hover:bg-[#F3F3F1] flex items-center justify-center"
                >
                    <x-heroicon-o-chevron-left class="w-[18px] h-[18px]" />
                </button>

                <div class="text-[17px] font-semibold text-[#111111] capitalize">
                    {{ $month->translatedFormat('F Y') }}
                </div>

                <button
                    type="button"
                    wire:click="nextMonth"
                    class="w-[36px] h-[36px] rounded-full hover:bg-[#F3F3F1] flex items-center justify-center"
                >
                    <x-heroicon-o-chevron-right class="w-[18px] h-[18px]" />
                </button>
            </div>

            <div class="grid grid-cols-7 mb-[10px]">
                @foreach (['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $weekday)
                    <div class="text-center text-[13px] font-medium text-[#969690]">
                        {{ $weekday }}
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-y-[8px]">
                @foreach ($this->calendarDays() as $day)
                    @php
                        $class = 'relative w-[40px] h-[40px] mx-auto text-[15px] flex items-center justify-center transition ';

                        if (! $day['current']) {
                            $class .= 'opacity-25 ';
                        }

                        if ($day['past']) {
                            $class .= 'text-[#D0D0CC] cursor-not-allowed ';
                        } elseif ($day['requested']) {
                            $class .= 'rounded-full bg-[#FFE9C9] text-[#A15D00] ';
                        } elseif ($day['selected']) {
                            $class .= 'rounded-full bg-[#5D9CF5] text-white z-10 ';
                        } elseif ($day['inside']) {
                            $class .= 'bg-[#DDEAFE] text-[#111111] rounded-none ';
                        } elseif ($day['policy']) {
                            $class .= 'rounded-full bg-[#FDECEC] text-[#B42318] ';
                        } else {
                            $class .= 'rounded-full text-[#111111] hover:bg-[#F3F3F1] ';
                        }
                    @endphp

                    <button
                        type="button"
                        wire:click="selectDate('{{ $day['date'] }}')"
                        class="{{ $class }}"
                        @disabled(!$day['current'] || $day['past'])
                    >
                        {{ $day['day'] }}
                    </button>
                @endforeach
            </div>

            <div class="mt-[14px] grid grid-cols-2 gap-[8px]">
                <div class="rounded-[14px] bg-[#F8F8F6] px-[10px] py-[8px] text-[12px] text-[#5E5E58] flex items-center gap-[8px]">
                    <span class="w-[12px] h-[12px] rounded-full bg-[#5D9CF5] block shrink-0"></span>
                    <span>Выбрано</span>
                </div>

                <div class="rounded-[14px] bg-[#F8F8F6] px-[10px] py-[8px] text-[12px] text-[#5E5E58] flex items-center gap-[8px]">
                    <span class="w-[12px] h-[12px] rounded-full bg-[#FFE9C9] block shrink-0"></span>
                    <span>Уже отправлено</span>
                </div>

                <div class="rounded-[14px] bg-[#F8F8F6] px-[10px] py-[8px] text-[12px] text-[#5E5E58] flex items-center gap-[8px] col-span-2">
                    <span class="w-[12px] h-[12px] rounded-full bg-[#FDECEC] block shrink-0"></span>
                    <span>Воскресенье и понедельник согласовываются отдельно</span>
                </div>
            </div>

            @if ($activeRangeIndex !== null)
                <div class="mt-[14px] rounded-[14px] bg-[#F5F8FF] px-[12px] py-[10px] text-[13px] text-[#4E5F7A]">
                    Дата уже добавлена. Нажмите ещё одну дату, чтобы продолжить этот чипс в диапазон.
                    Если ничего не выбирать дальше, останется один день.
                </div>

                <div class="mt-[14px] flex items-center gap-[10px]">
                    <div class="flex-1 rounded-[14px] bg-[#F5F8FF] px-[12px] py-[10px] text-[13px] text-[#4E5F7A] leading-[1.4]">
                        Можно выбрать ещё одну дату, чтобы сделать диапазон, или завершить выбор.
                    </div>

                    <button
                        type="button"
                        wire:click="finishCurrentRange"
                        class="h-[42px] shrink-0 rounded-full bg-[#111111] px-[18px] text-[14px] font-medium text-white active:scale-[0.97] transition"
                    >
                        Готово
                    </button>
                </div>
            @endif
        </div>
    </div>

    <div>
        <label class="block text-[16px] font-medium mb-[15px]">
            Почему вас не будет?
        </label>

        <textarea
            wire:model.live="comment"
            rows="4"
            maxlength="500"
            placeholder="Поделитесь планами на время отсутствия"
            class="w-full rounded-[23px] bg-[#E1E1E1] px-[20px] py-[13px] text-[16px] placeholder:text-black/50 placeholder:text-[16px] resize-none outline-none border border-[#E1E1E1] focus:border-black/50"
        ></textarea>

        <div class="mt-[8px] flex items-center justify-between">
            @error('comment')
                <div class="text-[15px] text-[#D92D20]">{{ $message }}</div>
            @else
       
            @enderror
        </div>
    </div>

    <button
        type="submit"
        wire:loading.attr="disabled"
        wire:target="submit"
        class="w-full h-[45px] rounded-full bg-[#CFE0F8] text-[#111111] text-[16px] font-semibold active:scale-[0.98] transition disabled:opacity-50 disabled:pointer-events-none"
        @disabled(empty($ranges) || blank($comment))
    >
        <span wire:loading.remove wire:target="submit">
            Отправить заявку
        </span>

        <span wire:loading wire:target="submit">
            Отправляем...
        </span>
    </button>

    @error('form')
        <div class="rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
            ⚠️ {{ $message }}
        </div>
    @enderror

    @if ($policyModalOpen)
        <div class="fixed inset-0 z-[110]">
            <div
                class="absolute inset-0 bg-black/40"
                wire:click="closePolicyModal"
            ></div>

            <div class="absolute inset-0 flex items-center justify-center p-[20px]">
                <div class="w-full max-w-[360px] rounded-[30px] bg-white px-[20px] py-[20px] shadow-[0_30px_80px_rgba(0,0,0,0.18)]">
                    <div class="w-[54px] h-[54px] rounded-full bg-[#FFF3D9] flex items-center justify-center mb-[16px]">
                        <x-heroicon-o-information-circle class="w-[28px] h-[28px] text-[#D28A00]" />
                    </div>

                    <h3 class="text-[22px] font-semibold text-[#111111] leading-[1.15]">
                        Нужна отдельная договорённость
                    </h3>

                    <p class="mt-[12px] text-[15px] leading-[1.5] text-[#666660]">
                        Воскресенье и понедельник — обязательные рабочие дни. Согласовать выходной на эти даты можно только в пятницу через администратора.
                    </p>

                    @if ($policyDate)
                        <div class="mt-[14px] rounded-[18px] bg-[#F7F7F5] px-[14px] py-[12px] text-[15px] font-medium text-[#111111]">
                            {{ Carbon::parse($policyDate)->translatedFormat('d.m.Y') }}
                        </div>
                    @endif

                    <a
                        href="{{ $adminChatUrl }}"
                        target="_blank"
                        class="mt-[20px] w-full h-[56px] rounded-[22px] bg-[#111111] text-white text-[16px] font-semibold flex items-center justify-center"
                    >
                        Написать администратору
                    </a>

                    <button
                        type="button"
                        wire:click="closePolicyModal"
                        class="mt-[10px] w-full h-[56px] rounded-[22px] bg-[#F3F3F1] text-[#111111] text-[16px] font-semibold"
                    >
                        Закрыть
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($successSheetOpen)
        <div class="fixed inset-0 z-[120]">
            <div
                class="absolute inset-0 bg-black/40"
                wire:click="closeSuccessSheet"
            ></div>

            <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-[36px] px-[20px] pt-[14px] pb-[24px]">
                <div class="w-[42px] h-[5px] rounded-full bg-[#D9D9D5] mx-auto mb-[20px]"></div>

                <div class="w-[56px] h-[56px] rounded-full bg-[#E7F5EA] flex items-center justify-center mb-[16px]">
                    <x-heroicon-o-check class="w-[28px] h-[28px] text-[#21663A]" />
                </div>

                <h3 class="text-[22px] font-semibold text-[#111111] leading-[1.15]">
                    Всё отлично
                </h3>

                <p class="mt-[12px] text-[15px] leading-[1.5] text-[#666660]">
                    {{ $successMessage }}
                </p>

                <button
                    type="button"
                    wire:click="closeSuccessSheet"
                    class="mt-[20px] w-full h-[56px] rounded-[22px] bg-[#111111] text-white text-[16px] font-semibold"
                >
                    Понятно
                </button>
            </div>
        </div>
    @endif
</form>
</div></div>
</div>