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

    public string $adminChatUrl = 'https://t.me/Tris_Admin_Tatiana';

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

    if ($this->activeRangeIndex === null || ! isset($this->ranges[$this->activeRangeIndex])) {
        if ($this->isSundayOrMonday($date)) {
            $this->openPolicyModal($date);
            return;
        }

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
        if ($this->isSundayOrMonday($date)) {
            $this->addError('ranges', 'Эту дату нужно согласовывать отдельно с администратором.');
            return;
        }

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
        if ($this->isSundayOrMonday($date)) {
            $this->addError('ranges', 'Эту дату нужно согласовывать отдельно с администратором.');
            return;
        }

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
            $this->addError(
                'ranges',
                'В диапазон попадает ' . Carbon::parse($periodString)->translatedFormat('d.m.Y') . '. Воскресенье и понедельник согласовываются отдельно.'
            );
            return;
        }

        if (
            $periodString !== $start->toDateString()
            && (
                $this->isAlreadyRequested($periodString)
                || $this->isInsideExistingRange($periodString, $this->activeRangeIndex)
            )
        ) {
            $this->addError('ranges', 'В этом диапазоне уже есть выбранная или отправленная дата. Нажмите "Готово", чтобы выбрабать новые даты.');
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

protected function requestStatusesByDate(): array
{
    return DayOffRequestDay::query()
        ->where('user_id', Auth::id())
        ->get(['date', 'status'])
        ->mapWithKeys(function ($item) {
            return [
                Carbon::parse($item->date)->toDateString() => $item->status,
            ];
        })
        ->all();
}

protected function isAlreadyRequested(string $date): bool
{
    $statuses = $this->requestStatusesByDate();

    return array_key_exists($date, $statuses);
}

public function calendarDays(): array
{
    $start = $this->month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
    $end = $this->month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

    $requestStatuses = $this->requestStatusesByDate();

    $days = [];

    while ($start->lte($end)) {
        $cursor = $start->copy();
        $date = $cursor->toDateString();

        $selected = false;
        $inside = false;
        $rangeStart = false;
        $rangeEnd = false;

        $status = $requestStatuses[$date] ?? null;

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

            'requested' => $status === 'pending',
            'approved' => $status === 'approved',
            'rejected' => $status === 'rejected',

            'policy' => $status === null && $this->isSundayOrMonday($date),
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
            return 'Ответ ожидайте сегодня с 10:00 до 18:00';
        }

        if ($now->greaterThan($end)) {
            return 'Мы получили его после окончания рабочего дня. Ответ ожидайте завтра с 10:00 до 18:00';
        }

        return 'Ответ ожидайте сегодня с 10:00 до 18:00';
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
    class="min-h-[45px] border border-[#E1E1E1] w-full rounded-[100px] bg-[#E1E1E1] px-[20px] flex items-center flex-wrap gap-[8px] cursor-pointer"
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

 

@php
    $colors = [
        'selected' => [
            'bg' => '#2563EB',
            'text' => '#FFFFFF',
            'shadow' => 'shadow-[0_8px_18px_rgba(37,99,235,0.24)]',
        ],
        'inside' => [
            'bg' => '#EEF4FF',
            'text' => '#35527A',
            'ring' => '#D9E7FF',
        ],
        'requested' => [
            'bg' => '#F6EFE4',
            'text' => '#8A5A2B',
        ],
        'approved' => [
            'bg' => '#ECFDF3',
            'text' => '#027A48',
        ],
       'policy' => [
    'bg' => '#F1EEE8',
    'text' => '#6D665C',
],
        'rejected' => [
            'bg' => '#FDECEC',
            'text' => '#C74A4A',
        ],
    ];


    $rangesCount = count($ranges ?? []);
    $buttonText = $activeRangeIndex !== null ? 'Готово' : 'Применить';
@endphp

<div
    x-show="open"
    x-transition
    x-on:click.outside="
        if (!$wire.policyModalOpen) {
            open = false;
            $wire.closeCalendar();
        }
    "
    class="absolute left-0 top-full z-30 mt-[10px] w-full rounded-[28px] border border-[#E8E8E3] bg-white p-[18px] shadow-[0_16px_40px_rgba(17,17,17,0.08)]"
>
    <div class="mb-[18px] flex items-center justify-between">
        <button
            type="button"
            wire:click="prevMonth"
            class="flex h-[44px] w-[44px] items-center justify-center rounded-full bg-[#F7F7F4] transition hover:bg-[#EFEFEA] active:scale-[0.97]"
        >
            <x-heroicon-o-chevron-left class="h-[18px] w-[18px] text-[#111111]" />
        </button>

        <div class="text-[18px] font-semibold tracking-[-0.02em] text-[#111111] capitalize">
            {{ $month->translatedFormat('F Y') }}
        </div>

        <button
            type="button"
            wire:click="nextMonth"
            class="flex h-[44px] w-[44px] items-center justify-center rounded-full bg-[#F7F7F4] transition hover:bg-[#EFEFEA] active:scale-[0.97]"
        >
            <x-heroicon-o-chevron-right class="h-[18px] w-[18px] text-[#111111]" />
        </button>
    </div>

    <div class="mb-[12px] grid grid-cols-7">
        @foreach (['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $weekday)
            <div class="text-center text-[12px] font-medium text-[#9A9A93]">
                {{ $weekday }}
            </div>
        @endforeach
    </div>

<div class="grid grid-cols-7 gap-y-[10px]">
    @foreach ($this->calendarDays() as $day)
        @php
            $style = 'opacity:' . ($day['current'] ? '1' : '.25') . ';';

            if ($day['past']) {
                $style .= 'color:#D0D0CC;';
            } elseif (!empty($day['selected'])) {
                $style .= 'background:' . $colors['selected']['bg'] . ';color:' . $colors['selected']['text'] . ';';
            } elseif (!empty($day['inside'])) {
                $style .= 'background:' . $colors['inside']['bg'] . ';color:' . $colors['inside']['text'] . ';border:1px solid ' . $colors['inside']['ring'] . ';';
            } elseif (!empty($day['rejected'])) {
                $style .= 'background:' . $colors['rejected']['bg'] . ';color:' . $colors['rejected']['text'] . ';';
            } elseif (!empty($day['approved'])) {
                $style .= 'background:' . $colors['approved']['bg'] . ';color:' . $colors['approved']['text'] . ';';
            } elseif (!empty($day['requested'])) {
                $style .= 'background:' . $colors['requested']['bg'] . ';color:' . $colors['requested']['text'] . ';';
            } elseif (!empty($day['policy'])) {
                $style .= 'background:' . $colors['policy']['bg'] . ';color:' . $colors['policy']['text'] . ';';
            } else {
                $style .= 'color:#111111;';
            }

            $class = 'relative mx-auto flex h-[44px] w-[44px] items-center justify-center rounded-full text-[15px] transition duration-150';

            if ($day['past']) {
                $class .= ' cursor-not-allowed';
            } elseif (!empty($day['selected'])) {
                $class .= ' z-10 font-semibold ' . $colors['selected']['shadow'];
            } else {
                $class .= ' hover:bg-[#F3F3F1] active:scale-[0.96]';
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

<div class="mt-[16px] flex flex-wrap items-center gap-x-[14px] gap-y-[8px] border-t border-[#F0F0EB] pt-[14px]">
    <div class="inline-flex items-center gap-[6px] text-[12px] text-[#6C6C65]">
        <span class="block w-[10px] h-[10px] rounded-full" style="background: {{ $colors['selected']['bg'] }};"></span>
        <span>Выбрано</span>
    </div>

    <div class="inline-flex items-center gap-[6px] text-[12px] text-[#6C6C65]">
        <span class="block w-[10px] h-[10px] rounded-full border" style="background: {{ $colors['inside']['bg'] }}; border-color: {{ $colors['inside']['ring'] }};"></span>
        <span>Период</span>
    </div>

    <div class="inline-flex items-center gap-[6px] text-[12px] text-[#6C6C65]">
        <span class="block w-[10px] h-[10px] rounded-full" style="background: {{ $colors['requested']['bg'] }};"></span>
        <span>На рассмотрении</span>
    </div>

    <div class="inline-flex items-center gap-[6px] text-[12px] text-[#6C6C65]">
        <span class="block w-[10px] h-[10px] rounded-full" style="background: {{ $colors['approved']['bg'] }};"></span>
        <span>Согласовано</span>
    </div>

    <div class="inline-flex items-center gap-[6px] text-[12px] text-[#6C6C65]">
        <span class="block w-[10px] h-[10px] rounded-full" style="background: {{ $colors['policy']['bg'] }};"></span>
        <span>Нужно согласование</span>
    </div>

    <div class="inline-flex items-center gap-[6px] text-[12px] text-[#6C6C65]">
        <span class="block w-[10px] h-[10px] rounded-full" style="background: {{ $colors['rejected']['bg'] }};"></span>
        <span>Отказано</span>
    </div>
</div>

    @error('ranges')
        <div class="mt-[14px] rounded-[18px] border border-[#F3D4D4] bg-[#FFF6F6] px-[14px] py-[11px] text-[13px] leading-[1.4] text-[#B54747]">
            {{ $message }}
        </div>
    @enderror

<div class="mt-[15px]">
    <button
        type="button"
        wire:click="finishCurrentRange"
        @disabled($activeRangeIndex === null && $rangesCount === 0)
        class="w-full flex items-center justify-center pl-[20px]
               h-[45px] rounded-full text-base text-white
               bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)]
               bg-[length:200%_100%] bg-left
               hover:bg-right
               transition-[background-position,transform]
               duration-1000 ease-in-out
               disabled:opacity-40 disabled:cursor-not-allowed"
    >
        {{ $buttonText }}
    </button>
</div>
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
            class="w-full rounded-[23px] bg-[#E1E1E1] px-[20px] py-[13px] text-[16px] placeholder:text-black/50 placeholder:text-[16px] outline-none border border-[#E1E1E1]"
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
     class="w-full flex items-center justify-center pl-[20px] mt-[15px]
                       h-[45px] rounded-full text-base text-white
                       bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)]
                       bg-[length:200%_100%] bg-left
                       hover:bg-right
                       transition-[background-position,transform]
                       duration-1000 ease-in-out
                       disabled:opacity-70 disabled:cursor-not-allowed"
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

   <div
    x-data="{ open: @entangle('policyModalOpen').live }"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[110]"
>
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-220"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-180"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute inset-0 bg-black/40"
        @click="open = false; $wire.closePolicyModal()"
    ></div>

    <div class="absolute inset-0 flex items-center justify-center p-[20px]">
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-260"
            x-transition:enter-start="opacity-0 scale-[0.96] translate-y-[10px]"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-180"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-[0.96] translate-y-[10px]"
            class="w-full max-w-[768px] rounded-[30px] bg-white px-[20px] py-[20px] shadow-[0_30px_80px_rgba(0,0,0,0.18)] text-center items-center justify-center"
        >
       
            <span class="text-[96px] text-center">🫸</span>

            <h1 class="pt-[20px] text-center">
                Нужна отдельная договорённость
            </h1>

            
            <p class="mt-[30px] text-[16px] text-center flex flex-col">
              <span>    Воскресенье и понедельник — обязательные рабочие дни</span>
            
                  <span>Согласовать выходной на эти даты можно только в пятницу через администратора</span>
            </p>

   

            <a
                href="{{ $adminChatUrl }}"
                target="_blank"
                    class="w-full flex items-center justify-center pl-[20px] mt-[40px]
                       h-[45px] rounded-full text-base text-white
                       bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)]
                       bg-[length:200%_100%] bg-left
                       hover:bg-right
                       transition-[background-position,transform]
                       duration-1000 ease-in-out
                       disabled:opacity-70 disabled:cursor-not-allowed"
            >
                Написать администратору
            </a>

            <button
                type="button"
                @click="open = false; $wire.closePolicyModal()"
                class="mt-[10px] w-full h-[45px] rounded-[23px] bg-[#E1E1E1] text-[16px] hover:bg-[#7D7D7D] hover:text-white duration-500"
            >
                Понятно
            </button>
        </div>
    </div>
</div>

   <div
    x-data="{
        open: @entangle('successSheetOpen').live,
        translateY: 0,
        startY: 0,
        dragging: false,

        begin(e) {
            if (!this.open) return
            this.dragging = true
            this.startY = e.touches[0].clientY
        },

        move(e) {
            if (!this.dragging) return
            const currentY = e.touches[0].clientY
            const delta = currentY - this.startY
            this.translateY = Math.max(0, delta)
        },

        finish() {
            if (!this.dragging) return
            this.dragging = false

            if (this.translateY > 120) {
                this.translateY = 0
                this.open = false
                $wire.closeSuccessSheet()
                return
            }

            this.translateY = 0
        }
    }"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[120]"
>
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-220"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-180"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute inset-0 bg-black/40"
        @click="open = false; $wire.closeSuccessSheet()"
    ></div>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-260"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        class="absolute inset-x-0 bottom-0 bg-white rounded-t-[36px] px-[20px] pt-[14px] pb-[24px] will-change-transform text-center"
        :style="`transform: translateY(${translateY}px)`"
    >
        <div
            class="pb-[6px]"
            @touchstart.passive="begin($event)"
            @touchmove.passive="move($event)"
            @touchend.passive="finish()"
        >
            <div class="w-[50px] h-[5px] rounded-full bg-[#D9D9D5] mx-auto"></div>
        </div>

  <span class="text-[96px] text-center">👍</span>

            <h1 class="pt-[20px] text-center">
                Заявка успешно отправлена
            </h1>

            
            <p class="mt-[30px] text-[16px] text-center flex flex-col">
                    {{ $successMessage }}
            
                 
            </p>



 

  

<div class="flex gap-[10px] pt-[40px]">
      
    <button
               type="button"
            @click="open = false; $wire.closeSuccessSheet()"
                class=" w-full h-[45px] rounded-[23px] bg-[#E1E1E1] text-[16px] hover:bg-[#7D7D7D] hover:text-white duration-500"
            >
                Понятно
            </button>

         <a
                   href="{{ route('page-profile.weekend') }}"
             
                    class="w-full flex items-center justify-center pl-[20px] 
                       h-[45px] rounded-full text-base text-white
                       bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)]
                       bg-[length:200%_100%] bg-left
                       hover:bg-right
                       transition-[background-position,transform]
                       duration-1000 ease-in-out
                       disabled:opacity-70 disabled:cursor-not-allowed"
            >
                В мои заявки
            </a>

        
        </div>
    </div>
</div>
</form>
</div></div>
</div>