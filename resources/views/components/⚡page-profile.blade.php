<?php

use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use App\Services\UserApplicationBadgeService;
use App\Models\CalendarEvent;
use App\Models\DayOffRequestDay;
use App\Models\VacationRequest;
use App\Services\RolePreviewContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;

new class extends Component
{
    public bool $profileEditOpen = false;
public bool $profileRequired = false;
    public ?string $profileName = null;
    public ?string $profileBirthday = null;
    public ?string $profileWorkStartedAt = null;

    public ?string $calendarBadge = null;
    public ?string $applicationsBadge = null;

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

    public function mount(UserApplicationBadgeService $badgeService): void
    {
        $user = auth()->user();

        $this->applicationsBadge = $badgeService->label($user->id);
        $this->calendarBadge = $this->buildCalendarBadge();

        $this->fillProfileForm();

        $this->profileRequired = ! $this->isProfileComplete();

if ($this->profileRequired) {
    $this->profileEditOpen = true;
}
    }

protected function isProfileComplete(): bool
{
    $user = auth()->user();

    return filled($user->name)
        && filled($user->birthday)
        && filled($user->work_started_at);
}

    public function openProfileEdit(): void
    {
        $this->fillProfileForm();

        $this->profileEditOpen = true;
    }

    protected function fillProfileForm(): void
    {
        $user = auth()->user();

        $this->profileName = $user->name;
        $this->profileBirthday = $user->birthday?->format('Y-m-d');
        $this->profileWorkStartedAt = $user->work_started_at?->format('Y-m-d');
    }

    public function saveProfile(): void
    {
        $this->validate([
            'profileName' => ['required', 'string', 'max:255'],
            'profileBirthday' => ['required', 'date'],
            'profileWorkStartedAt' => ['required', 'date'],
        ], [
            'profileName.required' => 'Введите имя.',
            'profileName.max' => 'Имя слишком длинное.',
            'profileBirthday.date' => 'Некорректная дата рождения.',
            'profileWorkStartedAt.date' => 'Некорректная дата начала работы.',
        ]);

        auth()->user()->forceFill([
            'name' => trim($this->profileName),
            'birthday' => $this->profileBirthday ?: null,
            'work_started_at' => $this->profileWorkStartedAt ?: null,
        ])->save();
$this->profileRequired = false;
        $this->profileEditOpen = false;

        $this->toast(
            'success',
            'Профиль обновлён',
            'Данные успешно сохранены'
        );
    }

    protected function buildCalendarBadge(): ?string
    {
        Carbon::setLocale('ru');

        $startOffset = now()->hour >= 20 ? 1 : 0;

        for ($i = $startOffset; $i <= 7; $i++) {
            $day = now()->copy()->addDays($i)->startOfDay();
            $events = $this->eventsForDay($day);

            if ($events->isEmpty()) {
                continue;
            }

            $prefix = match (true) {
                $day->isToday() => 'Сегодня',
                $day->isTomorrow() => 'Завтра',
                default => $day->translatedFormat('j M'),
            };

            if ($events->count() === 1) {
                return $prefix . ': ' . $this->shortCalendarTitle($events->first()['title']);
            }

            return $prefix . ': ' . $events->count() . ' ' . $this->pluralEvents($events->count());
        }

        return 'Нет событий';
    }

    protected function shortCalendarTitle(string $title): string
    {
        $title = str_replace(' — ', ': ', $title);

        return mb_strimwidth($title, 0, 28, '...');
    }

    protected function pluralEvents(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'событие';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ! in_array($mod100, [12, 13, 14], true)) {
            return 'события';
        }

        return 'событий';
    }

    protected function eventsForDay(Carbon $day): Collection
    {
        $events = collect();

        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        CalendarEvent::query()
            ->where('is_active', true)
            ->get()
            ->each(function (CalendarEvent $event) use ($events, $start, $end) {
                $eventStart = Carbon::parse($event->start_date)->startOfDay();

                $eventEnd = $event->end_date
                    ? Carbon::parse($event->end_date)->startOfDay()
                    : $eventStart->copy();

                if ($eventEnd->lt($eventStart)) {
                    $eventEnd = $eventStart->copy();
                }

                if ($event->repeat_type === 'none') {
                    if ($eventStart->lte($end) && $eventEnd->gte($start)) {
                        $events->push([
                            'title' => $event->title,
                            'priority' => (int) ($event->priority ?? 0),
                        ]);
                    }

                    return;
                }

                if ($event->repeat_type === 'weekly' && $eventStart->dayOfWeek === $start->dayOfWeek) {
                    $events->push([
                        'title' => $event->title,
                        'priority' => (int) ($event->priority ?? 0),
                    ]);

                    return;
                }

                if ($event->repeat_type === 'monthly' && $eventStart->day === $start->day) {
                    $events->push([
                        'title' => $event->title,
                        'priority' => (int) ($event->priority ?? 0),
                    ]);

                    return;
                }

                if ($event->repeat_type === 'yearly' && $eventStart->month === $start->month && $eventStart->day === $start->day) {
                    $events->push([
                        'title' => $event->title,
                        'priority' => (int) ($event->priority ?? 0),
                    ]);
                }
            });

        DayOffRequestDay::query()
            ->with(['user', 'request'])
            ->whereDate('date', $start)
            ->whereHas('request', fn ($query) => $query->where('status', 'approved'))
            ->get()
            ->each(function ($dayOffDay) use ($events) {
                $events->push([
                    'title' => ($dayOffDay->user?->name ?? 'Сотрудник') . ' — выходной',
                    'priority' => 85,
                ]);
            });

        VacationRequest::query()
            ->with(['user', 'days'])
            ->where('status', 'approved')
            ->whereHas('days', fn ($query) => $query->whereDate('date', $start))
            ->get()
            ->each(function ($vacation) use ($events) {
                $events->push([
                    'title' => ($vacation->user?->name ?? 'Сотрудник') . ' — отпуск',
                    'priority' => 80,
                ]);
            });

        return $events
            ->sortByDesc('priority')
            ->values();
    }
};
?>

<x-slot:header>
    @php
        $user = auth()->user();
        $effectiveUiRole = app(RolePreviewContext::class)->effectiveUiRole();
    @endphp

    <div class="flex items-center gap-[10px] p-[15px]">
        <div class="h-[60px] w-[60px] shrink-0 overflow-hidden rounded-full bg-[#E1E1E1]">
            @if($user?->telegram_photo_url)
                <img
                    src="{{ $user->telegram_photo_url }}"
                    alt="{{ $user->name }}"
                    class="h-full w-full object-cover"
                >
            @elseif($user?->telegram_avatar_path)
                <img
                    src="{{ Storage::url($user->telegram_avatar_path) }}"
                    alt="{{ $user->name }}"
                    class="h-full w-full object-cover"
                >
            @else
                <div class="flex h-full w-full items-center justify-center text-[20px] font-medium text-[#666666]">
                    {{ mb_substr($user?->name ?? 'U', 0, 1) }}
                </div>
            @endif
        </div>

        <div class="flex min-w-0 flex-1 items-center justify-between gap-[10px]">
            <div class="flex min-w-0 flex-col">
                <span class="truncate text-[20px] font-medium">
                    {{ $user->name }}
                </span>

                <span class="text-[18px] opacity-50">
                    @switch($effectiveUiRole)
                        @case('admin')
                            Администратор
                            @break
                        @case('supervisor')
                            Супервайзер
                            @break
                        @case('cleaner')
                            Клинер
                            @break
                        @default
                            {{ $effectiveUiRole }}
                    @endswitch
                </span>
            </div>

            <button
                type="button"
                onclick="window.dispatchEvent(new CustomEvent('open-profile-edit'))"
                class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full bg-[#F8F7F5] text-[#213259] active:scale-[0.96]"
            >
                <x-heroicon-o-pencil-square class="h-[22px] w-[22px] stroke-[2]" />
            </button>
        </div>
    </div>
</x-slot:header>

<div
    x-data="{ profileSheetOpen: @entangle('profileEditOpen').live }"
    x-on:open-profile-edit.window="$wire.openProfileEdit()"
    class="w-full bg-white p-[15px]"
>
    @php
        $rolePreview = app(RolePreviewContext::class);
    @endphp

    @if($user?->isAdmin())
        <section class="mb-[20px] rounded-[24px] border border-[#E7EBF2] bg-[#F8FAFC] p-4">
            <h2 class="text-[16px] font-semibold text-[#213259]">Режим просмотра</h2>
            <p class="mt-1 text-[13px] leading-[1.4] text-black/55">Показывает клиентский интерфейс выбранной роли. Данные и права администратора не меняются.</p>
            <form method="POST" action="{{ route('role-preview.store') }}" class="mt-3 flex items-center gap-2">
                @csrf
                <label for="ui-role-preview" class="sr-only">Роль для предпросмотра</label>
                <select id="ui-role-preview" name="role" class="min-w-0 flex-1 rounded-xl border border-[#D8DEE8] bg-white px-3 py-2 text-[14px] text-[#213259]">
                    <option value="admin" @selected($rolePreview->effectiveUiRole() === 'admin')>Администратор</option>
                    <option value="supervisor" @selected($rolePreview->effectiveUiRole() === 'supervisor')>Супервайзер</option>
                    <option value="cleaner" @selected($rolePreview->effectiveUiRole() === 'cleaner')>Клинер</option>
                </select>
                <button type="submit" class="shrink-0 rounded-xl bg-[#213259] px-4 py-2 text-[14px] font-semibold text-white">Показать</button>
            </form>
        </section>
    @endif

    <div class="mb-[10px]">
        <span class="text-[16px] opacity-50">
            Рабочие инструменты
        </span>
    </div>

    <div class="overflow-hidden rounded-[35px] bg-[#F8F7F5]">
        <a
            href="{{ route('page-profile.checks') }}"
            class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
        >
            <div class="flex min-w-0 items-center gap-4">
                <x-heroicon-o-clipboard-document-check class="h-[24px] w-[24px]" />

                <span class="truncate text-[18px]">
                    Мои контроли
                </span>
            </div>

            <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
        </a>

        <div class="mx-[15px] h-px bg-[#ECECEC]"></div>

        <a
            href="{{ route('page-profile.applications') }}"
            class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
        >
            <div class="flex min-w-0 items-center gap-4">
                <x-heroicon-o-clipboard-document-list class="h-[24px] w-[24px]" />

                <span class="truncate text-[18px]">
                    Мои заявки
                </span>
            </div>

            <div class="ml-[15px] flex shrink-0 items-center gap-[8px]">
                @if($this->applicationsBadge)
                    <span class="flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-[#2D6494] px-[7px] text-[12px] font-bold leading-none text-white shadow-sm">
                        {{ $this->applicationsBadge }}
                    </span>
                @endif

                <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
            </div>
        </a>
    </div>



    <div class="mb-[10px] pt-[20px]">
        <span class="text-[16px] opacity-50">Объекты</span>
    </div>

    <div class="overflow-hidden rounded-[35px] bg-[#F8F7F5]">
        <a
            href="{{ route('page-apartments') }}"
            class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
        >
            <div class="flex min-w-0 items-center gap-4">
                <x-heroicon-o-home-modern class="h-[24px] w-[24px]" />
                <span class="truncate text-[18px]">Квартиры</span>
            </div>

            <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
        </a>
    </div>

@php
$canSeeAdminPanel = ! $rolePreview->isActive() && $user->isAdmin();
$canSeeFinancePanel = ! $rolePreview->isActive() && ($user->isAdmin() || $user->hasPanelAccess('finance'));
$canSeeEducationPanel = ! $rolePreview->isActive() && ($user->isAdmin() || $user->hasPanelAccess('education'));

$hasAnyAdminAccess =
    $canSeeAdminPanel ||
    $canSeeFinancePanel ||
    $canSeeEducationPanel;
@endphp

@if($hasAnyAdminAccess)
    <div class="mb-[10px] pt-[20px]">
        <span class="text-[16px] opacity-50">
            Администрирование
        </span>
    </div>

    <div class="overflow-hidden rounded-[35px] bg-[#F8F7F5]">

        @if($canSeeAdminPanel)
            <a
                href="{{ route('page-profile.all-checks') }}"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <x-heroicon-o-cog-6-tooth class="h-[24px] w-[24px]" />

                    <span class="truncate text-[18px]">
                        Все проверки
                    </span>
                </div>

                <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
            </a>

            <div class="mx-[15px] h-px bg-[#ECECEC]"></div>

            <a
                href="/admin"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <x-heroicon-o-cog-6-tooth class="h-[24px] w-[24px]" />

                    <span class="truncate text-[18px]">
                        Админ-панель
                    </span>
                </div>

                <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
            </a>
        @endif

        @if($canSeeFinancePanel)
            @if($canSeeAdminPanel)
                <div class="mx-[15px] h-px bg-[#ECECEC]"></div>
            @endif

            <a
                href="/admin/finance"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <x-heroicon-o-banknotes class="h-[24px] w-[24px]" />

                    <span class="truncate text-[18px]">
                        Финансы
                    </span>
                </div>

                <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
            </a>
        @endif

        @if($canSeeEducationPanel)
            @if($canSeeAdminPanel || $canSeeFinancePanel)
                <div class="mx-[15px] h-px bg-[#ECECEC]"></div>
            @endif

            <a
                href="/admin/education"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <x-heroicon-o-academic-cap class="h-[24px] w-[24px]" />

                    <span class="truncate text-[18px]">
                        Обучение
                    </span>
                </div>

                <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
            </a>
        @endif

    </div>
@endif

    <div class="mb-[10px] pt-[20px]">
        <span class="text-[16px] opacity-50">
            Поддержка
        </span>
    </div>

    <div class="overflow-hidden rounded-[35px] bg-[#F8F7F5]">
        <a
            target="_blank"
            href="https://t.me/cocenage"
            class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
        >
            <div class="flex min-w-0 items-center gap-4">
                <x-heroicon-o-chat-bubble-left-right class="h-[24px] w-[24px]" />

                <span class="truncate text-[18px]">
                    Чат с разработчиком
                </span>
            </div>

            <x-heroicon-o-chevron-right class="h-[18px] w-[18px] stroke-2 transition-transform duration-200 group-hover:translate-x-[2px]" />
        </a>
    </div>

<x-ui.bottom-sheet x-model="profileSheetOpen">
    <form wire:submit="saveProfile" class="p-[20px]">
        <div class="mb-[20px]">
            <h2 class="text-[24px]  leading-none tracking-[-0.03em]">
                Данные профиля
            </h2>

            <p class="mt-[8px] text-[14px] leading-[1.4] text-black/50">
                Измените имя, дату рождения и дату начала работы
            </p>
        </div>

        <div class="space-y-[12px]">
            <div>
                <label class="mb-[7px] block text-[14px] text-black/50">
                    Имя
                </label>

                <input
                    type="text"
                    wire:model="profileName"
                    class="h-[54px] w-full rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] text-[16px] outline-none focus:border-[#213259]"
                    placeholder="Ваше имя"
                >

                @error('profileName')
                    <div class="mt-[6px] text-[13px] text-red-500">
                        {{ $message }}
                    </div>
                @enderror
            </div>

            <div>
                <label class="mb-[7px] block text-[14px] text-black/50">
                    Дата рождения
                </label>

                <input
                    type="date"
                    wire:model="profileBirthday"
                    class="h-[54px] w-full rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] text-[16px] outline-none focus:border-[#213259]"
                >

                @error('profileBirthday')
                    <div class="mt-[6px] text-[13px] text-red-500">
                        {{ $message }}
                    </div>
                @enderror
            </div>

            <div>
                <label class="mb-[7px] block text-[14px] text-black/50">
                    Дата начала работы
                </label>

                <input
                    type="date"
                    wire:model="profileWorkStartedAt"
                    class="h-[54px] w-full rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] text-[16px] outline-none focus:border-[#213259]"
                >

                @error('profileWorkStartedAt')
                    <div class="mt-[6px] text-[13px] text-red-500">
                        {{ $message }}
                    </div>
                @enderror
            </div>
        </div>

        <div class="mt-[20px] grid {{ $profileRequired ? 'grid-cols-1' : 'grid-cols-2' }} gap-[10px]">
           @if(! $profileRequired)
    <x-ui.button
        type="button"
        variant="secondary"
        @click="profileSheetOpen = false"
    >
        Отмена
    </x-ui.button>
@endif

            <x-ui.button
                type="submit"
                variant="primary"
                wire:loading.attr="disabled"
                wire:target="saveProfile"
            >
                <span wire:loading.remove wire:target="saveProfile">
                    Сохранить
                </span>

                <span wire:loading wire:target="saveProfile">
                    Сохраняем...
                </span>
            </x-ui.button>
        </div>
    </form>
</x-ui.bottom-sheet>
</div>
