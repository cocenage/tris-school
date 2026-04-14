<?php

use App\Models\DayOffRequest;
use App\Models\DayOffRequestDay;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    public Carbon $month;

    public bool $policyModalOpen = false;
    public bool $successSheetOpen = false;

    public ?string $policyDate = null;
    public ?string $draftStartDate = null;

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

        if (!is_array($draft)) {
            return;
        }

        $this->ranges = is_array($draft['ranges'] ?? null) ? $draft['ranges'] : [];
        $this->comment = (string) ($draft['comment'] ?? '');
        $this->draftStartDate = !empty($draft['draftStartDate'])
            ? (string) $draft['draftStartDate']
            : null;

        if (!empty($draft['month'])) {
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

    public function openPolicyModal(string $date): void
    {
        $this->policyDate = $date;
        $this->policyModalOpen = true;
    }

    public function closePolicyModal(): void
    {
        $this->policyModalOpen = false;
        $this->policyDate = null;
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
        $this->policyDate = null;

        $this->resetErrorBag();
        $this->resetValidation();
        $this->clearDraft();
    }

    protected function requestStatusesByDate(): array
    {
        return DayOffRequestDay::query()
            ->where('user_id', Auth::id())
            ->get(['date', 'status'])
            ->mapWithKeys(fn($item) => [
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

    protected function isSundayOrMonday(string $date): bool
    {
        return in_array(Carbon::parse($date)->dayOfWeekIso, [1, 7], true);
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

        $policyDates = [];
        $requestedDates = [];
        $selectedDates = [];

        foreach (CarbonPeriod::create($start, $end) as $periodDate) {
            $date = $periodDate->toDateString();

            if ($this->isSundayOrMonday($date)) {
                $policyDates[] = mb_strtolower(Carbon::parse($date)->translatedFormat('D d.m'));
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

        if (!empty($policyDates)) {
            return [
                'title' => 'Нужно согласование',
                'message' => 'В диапазон попадают: ' . implode(', ', $policyDates),
            ];
        }

        if (!empty($requestedDates)) {
            return [
                'title' => count($requestedDates) === 1 ? 'Дата занята' : 'Даты заняты',
                'message' => count($requestedDates) === 1
                    ? 'На ' . $requestedDates[0] . ' уже есть заявка'
                    : 'Уже есть заявки на: ' . implode(', ', $requestedDates),
            ];
        }

        if (!empty($selectedDates)) {
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
        if (!isset($this->ranges[$index])) {
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

        $existingRangeIndex = $this->findRangeIndexByDate($date);

        if ($existingRangeIndex !== null) {
            $this->removeRange($existingRangeIndex);
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

            if ($this->isSundayOrMonday($date)) {
                $this->openPolicyModal($date);
                return;
            }

            $this->draftStartDate = $date;
            $this->persistDraft();
            return;
        }

        $start = Carbon::parse($this->draftStartDate)->startOfDay();

        if ($picked->equalTo($start)) {
            $this->ranges[] = [
                'start' => $date,
                'end' => $date,
            ];

            $this->draftStartDate = null;
            $this->persistDraft();
            return;
        }

        if ($picked->lt($start)) {
            if ($this->isAlreadyRequested($date)) {
                $this->toast(
                    'warning',
                    'Дата занята',
                    'На ' . Carbon::parse($date)->format('d.m') . ' уже есть заявка'
                );
                return;
            }

            if ($this->isSundayOrMonday($date)) {
                $this->openPolicyModal($date);
                return;
            }

            $this->draftStartDate = $date;
            $this->persistDraft();
            return;
        }

        $reason = $this->rangeConflictReason($this->draftStartDate, $date);

        if ($reason !== null) {
            $this->toast(
                'warning',
                $reason['title'],
                $reason['message'],
                4200
            );
            return;
        }

        $this->ranges[] = [
            'start' => $this->draftStartDate,
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
        $days = [];

        while ($start->lte($end)) {
            $cursor = $start->copy();
            $date = $cursor->toDateString();

            $selected = false;
            $inside = false;
            $rangeStart = false;
            $rangeEnd = false;

            foreach ($this->ranges as $range) {
                $startDate = Carbon::parse($range['start'])->startOfDay();
                $endDate = Carbon::parse($range['end'])->startOfDay();

                if ($cursor->equalTo($startDate) && $cursor->equalTo($endDate)) {
                    $selected = true;
                    $rangeStart = true;
                    $rangeEnd = true;
                    continue;
                }

                if ($cursor->equalTo($startDate)) {
                    $selected = true;
                    $rangeStart = true;
                    continue;
                }

                if ($cursor->equalTo($endDate)) {
                    $selected = true;
                    $rangeEnd = true;
                    continue;
                }

                if ($cursor->gt($startDate) && $cursor->lt($endDate)) {
                    $inside = true;
                }
            }

            $status = $requestStatuses[$date] ?? null;
            $preview = $this->previewRangeForDate($date);

            $days[] = [
                'date' => $date,
                'day' => $cursor->day,
                'current' => $cursor->month === $this->month->month,
                'past' => $cursor->lt(now()->startOfDay()),
                'selected' => $selected,
                'inside' => $inside,
                'start' => $rangeStart,
                'end' => $rangeEnd,
                'draft_start' => $this->draftStartDate === $date,
                'requested' => $status === 'pending',
                'approved' => $status === 'approved',
                'rejected' => $status === 'rejected',
                'policy' => $status === null && $this->isSundayOrMonday($date),
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

    protected function adminRecordUrl(DayOffRequest $request): string
    {
        return url('/admin/day-off-requests/' . $request->id . '/edit');
    }

    protected function sendTelegramNotification(DayOffRequest $request): void
    {
        $request->loadMissing(['user', 'days']);

        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id_formweekend');
        $threadId = config('services.telegram.thread_id_formweekend');

        if (blank($token) || blank($chatId)) {
            Log::warning('Telegram notification skipped: missing credentials');
            return;
        }

        $user = $request->user;

        Carbon::setLocale('ru');

        $formattedDates = $request->days
            ->pluck('date')
            ->map(fn($date) => Carbon::parse($date)->translatedFormat('d.m.Y (l)'))
            ->implode("\n• ");

        $name = $user?->name ?: 'Неизвестный пользователь';

        $tgRaw = $user?->tg
            ? ltrim(trim($user->tg), '@')
            : null;

        $tgUsername = ($tgRaw && preg_match('/^[A-Za-z0-9_]{5,32}$/', $tgRaw))
            ? $tgRaw
            : null;

        $userTelegramUrl = $tgUsername
            ? "https://t.me/{$tgUsername}"
            : null;

        $dipText = isset($user?->dip)
            ? ($user->dip ? 'dip' : 'no dip')
            : '—';

        $adminUrl = $this->adminRecordUrl($request);

        $text = "📌 <b>Новый запрос на выходной!</b>\n\n";

        if ($userTelegramUrl) {
            $text .= "👤 <b>Сотрудник:</b> <a href='{$userTelegramUrl}'>" . e($name) . "</a>\n";
        } else {
            $text .= "👤 <b>Сотрудник:</b> " . e($name) . "\n";
        }

        $text .= "🏷️ <b>Dip:</b> {$dipText}\n";
        $text .= "📅 <b>Даты:</b>\n• {$formattedDates}\n\n";
        $text .= "💬 <b>Причина:</b>\n<blockquote>" . e(trim((string) $request->reason)) . "</blockquote>\n\n";

        if ($userTelegramUrl) {
            $text .= "🔗 <b>Telegram:</b> <a href='{$userTelegramUrl}'>открыть профиль</a>\n";
        }

        $text .= "⛓️ <a href='{$adminUrl}'><b>Ссылка на запрос в админке</b></a>\n";

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

            foreach ($this->ranges as $range) {
                $rangeStart = Carbon::parse($range['start'])->startOfDay();
                $rangeEnd = Carbon::parse($range['end'])->startOfDay();

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
                $this->sendTelegramNotification($request);
            } catch (\Throwable $e) {
                Log::error('Telegram failed but request saved', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->ranges = [];
            $this->draftStartDate = null;
            $this->comment = '';

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

<div class="flex h-full min-h-0 flex-col">
    <form wire:submit="submit" class="flex h-full min-h-0 flex-col">
        <div class="flex-1 min-h-0 overflow-y-auto">
            <div class="min-h-full rounded-t-[50px] bg-white">
                <div class="space-y-[18px] px-[20px] pt-[20px] pb-[24px]">
                    <div
                        class="rounded-[30px] border border-[#E7E7E4] bg-[#F8F8F7] p-[18px] shadow-[0_8px_24px_rgba(17,17,17,0.04)]">
                        <div class="mb-[16px] flex items-center justify-between">
                            <button type="button" wire:click="prevMonth"
                                class="flex h-[42px] w-[42px] items-center justify-center rounded-full border border-[#E5E7EB] bg-white text-[#111111] transition hover:bg-[#F3F4F6]">
                                <x-heroicon-o-chevron-left class="h-[18px] w-[18px]" />
                            </button>

                            <div class="text-[18px] font-semibold tracking-[-0.02em] text-[#111111] capitalize">
                                {{ $month->translatedFormat('F Y') }}
                            </div>

                            <button type="button" wire:click="nextMonth"
                                class="flex h-[42px] w-[42px] items-center justify-center rounded-full border border-[#E5E7EB] bg-white text-[#111111] transition hover:bg-[#F3F4F6]">
                                <x-heroicon-o-chevron-right class="h-[18px] w-[18px]" />
                            </button>
                        </div>

                        <div class="mb-[10px] grid grid-cols-7">
                            @foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $weekday)
                                <div class="text-center text-[11px] font-medium uppercase tracking-[0.04em] text-[#A0A09A]">
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
                                        $style .= 'color:#D0D0CC;';
                                        $class .= ' cursor-not-allowed';
                                    } elseif (!empty($day['draft_start'])) {
                                        $style .= 'background:#213259;color:#FFFFFF;';
                                        $class .= ' font-semibold shadow-[0_8px_18px_rgba(33,50,89,0.22)]';
                                    } elseif (!empty($day['selected'])) {
                                        $style .= 'background:#213259;color:#FFFFFF;';
                                        $class .= ' font-semibold shadow-[0_8px_18px_rgba(33,50,89,0.22)]';
                                    } elseif (!empty($day['inside'])) {
                                        $style .= 'background:#E8EEF8;color:#213259;';
                                    } elseif (!empty($day['preview_inside'])) {
                                        $style .= 'background:#EDF3FF;color:#35527A;';
                                    } elseif (!empty($day['preview_end'])) {
                                        $style .= 'background:#DCE8FF;color:#213259;';
                                        $class .= ' font-medium';
                                    } elseif (!empty($day['approved'])) {
                                        $style .= 'background:#ECFDF3;color:#027A48;';
                                    } elseif (!empty($day['requested'])) {
                                        $style .= 'background:#F6EFE4;color:#8A5A2B;';
                                    } elseif (!empty($day['rejected'])) {
                                        $style .= 'background:#FDECEC;color:#C74A4A;';
                                    } else {
                                        $style .= 'color:#111111;';
                                        $class .= ' hover:bg-white active:scale-[0.96]';
                                    }
                                @endphp

                                <button type="button" wire:click="selectDate('{{ $day['date'] }}')" class="{{ $class }}"
                                    style="{{ $style }}" @disabled(!$day['current'] || $day['past'])>
                                    {{ $day['day'] }}

                                    @if (!empty($day['policy']))
                                        <span
                                            class="absolute bottom-[4px] left-1/2 block h-[5px] w-[5px] -translate-x-1/2 rounded-full bg-[#6D84A3]"></span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <textarea wire:model.live.debounce.500ms="comment" rows="4" maxlength="500"
                            placeholder="Причина"
                            class="w-full rounded-[23px] border border-[#E1E1E1] bg-[#E1E1E1] px-[20px] py-[13px] text-[16px] placeholder:text-[16px] placeholder:text-black/45 outline-none focus:border-[#213259]"></textarea>

                        @error('comment')
                            <div class="mt-[8px] text-[15px] text-[#D92D20]">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    @error('form')
                        <div class="rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
                            ⚠️ {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>

        <div class="shrink-0 border-t border-[#E5E7EB] bg-white px-[20px] pt-[14px] pb-[18px]">
            <div class="grid grid-cols-3 gap-[10px]">
                <button type="button" wire:click="resetForm"
                    class="col-span-1 h-[45px] rounded-[23px] bg-[#E1E1E1] text-[16px] duration-500 hover:bg-[#7D7D7D] hover:text-white">
                    Сбросить
                </button>

                <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                    class="col-span-2 flex h-[45px] items-center justify-center rounded-full bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)] bg-[length:200%_100%] bg-left text-base text-white transition-[background-position,transform] duration-1000 ease-in-out hover:bg-right disabled:cursor-not-allowed disabled:opacity-70"
                    @disabled(empty($ranges) || blank($comment))>
                    <span wire:loading.remove wire:target="submit">
                        Отправить заявку
                    </span>

                    <span wire:loading wire:target="submit">
                        Отправляем...
                    </span>
                </button>
            </div>
        </div>
    </form>

    <div x-data="{ modalOpen: @entangle('policyModalOpen').live }">
        <x-ui.modal x-model="modalOpen">
            <div class="p-5 pr-16 text-center sm:p-6">
          
     <img class="w-full h-[135px] object-contain" src="{{ asset('images/warning.webp') }}" alt="warning cat">

                <p class="mt-[20px] text-[16px]">
                    Эту дату нужно согласовать отдельно
                </p>

                <a href="{{ $adminChatUrl }}" target="_blank"
                    class="mt-[30px] flex h-[45px] w-full items-center justify-center rounded-full bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)] bg-[length:200%_100%] bg-left text-base text-white transition-[background-position,transform] duration-1000 ease-in-out hover:bg-right">
                    Написать администратору
                </a>

                <button type="button" @click="modalOpen = false"
                    class="mt-[10px] h-[45px] w-full rounded-[23px] bg-[#E1E1E1] text-[16px] duration-500 hover:bg-[#7D7D7D] hover:text-white">
                    Закрыть
                </button>
            </div>
        </x-ui.modal>
    </div>

    <div x-data="{ sheetOpen: @entangle('successSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5 pt-6 pr-16 text-center sm:p-6">
                <div class="text-[96px]">👍</div>

                <p class="mt-[20px] text-[16px]">
                    {{ $successMessage }}
                </p>

                <div class="flex gap-[10px] pt-[30px]">
                    <button type="button" @click="sheetOpen = false"
                        class="h-[45px] w-full rounded-[23px] bg-[#E1E1E1] text-[16px] duration-500 hover:bg-[#7D7D7D] hover:text-white">
                        Закрыть
                    </button>

                    <a href="{{ route('page-profile.weekend') }}"
                        class="flex h-[45px] w-full items-center justify-center rounded-full bg-[linear-gradient(90deg,#213259_0%,#2D6494_25%,#368DC4_100%)] bg-[length:200%_100%] bg-left text-base text-white transition-[background-position,transform] duration-1000 ease-in-out hover:bg-right">
                        Мои заявки
                    </a>
                </div>
            </div>
        </x-ui.bottom-sheet>
    </div>
</div>