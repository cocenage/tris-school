<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Services\UserApplicationBadgeService;
use App\Models\CalendarEvent;
use App\Models\DayOffRequestDay;
use App\Models\VacationRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

new class extends Component
{
   

        public ?string $calendarBadge = null;

public ?string $applicationsBadge = null;

public function mount(UserApplicationBadgeService $badgeService): void
{
    $this->applicationsBadge = $badgeService->label(auth()->id());
    $this->calendarBadge = $this->buildCalendarBadge();
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
  <div class="flex items-center gap-[10px]">
    @php
        $user = auth()->user();
    @endphp

    <div class="w-[80px] h-[80px] shrink-0 overflow-hidden rounded-full bg-[#E1E1E1]">
        @if($user?->telegram_photo_url)
            <img
                src="{{ $user->telegram_photo_url }}"
                alt="{{ $user->name }}"
                class="w-full h-full object-cover"
            >
        @elseif($user?->telegram_avatar_path)
            <img
                src="{{ Storage::url($user->telegram_avatar_path) }}"
                alt="{{ $user->name }}"
                class="w-full h-full object-cover"
            >
        @else
            <div class="flex h-full w-full items-center justify-center text-[24px] font-semibold text-[#666666]">
                {{ mb_substr($user?->name ?? 'U', 0, 1) }}
            </div>
        @endif
    </div>

    <div class="flex flex-col gap-[5px]">
        <span class="text-[20px] font-medium">
            {{ $user->name }}
        </span>

        <span class="text-[18px] opacity-50">
            @switch($user->role)
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
                    {{ $user->role }}
            @endswitch
        </span>
    </div>
</div>
</x-slot:header>
<div class="bg-white w-full p-[15px]">

<!-- <div class="w-full h-[100px] bg-[#F8F7F5] rounded-[35px]"></div> -->
 <div>
        <div class="mb-[10px]">
            <span class="text-[16px] opacity-50">
                Тут будет много блоков, я пока не знаю, что войдет в этот
            </span>
        </div>

        <div class="overflow-hidden rounded-[23px] bg-[#F8F7F5]">
            <a
             href="{{ route('page-profile.checks') }}"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-clipboard-document-check class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Мои проверки
                    </span>
                </div>

                      <div class="ml-[15px] flex shrink-0 items-center">
                     <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2
                }}" />
                  
                </div>
            </a>

            <div class="mx-[15px] h-px bg-[#ECECEC]"></div>

              <a href="{{ route('page-profile.applications') }}"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
            
                <div class="flex min-w-0 items-center gap-4">
            

                               <x-heroicon-o-clipboard-document-list class="w-[24px] h-[24px] 
                }}" />


            
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

    <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2" />
</div>
            </a>

           
        </div>

                <div class="mb-[10px] pt-[20px]">
            <span class="text-[16px] opacity-50">
                Еще блок
            </span>
        </div>

        <div class="overflow-hidden rounded-[23px] bg-[#F8F7F5]">


   

            <a
               href="{{ route('page-profile.calendar') }}"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-calendar-days class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Календарь
                    </span>
                </div>

          <div class="ml-[15px] flex min-w-0 shrink-0 items-center gap-[8px]">
    @if($this->calendarBadge)
        <span class="max-w-[155px] truncate rounded-full bg-white px-[10px] py-[5px] text-[12px] font-medium text-[#555555]">
           {{ $this->calendarBadge }}
        </span>
    @endif

    <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2" />
</div>
            </a>



           
        </div>

                        <div class="mb-[10px] pt-[20px]">
            <span class="text-[16px] opacity-50">
                Блок только для админа 
            </span>
        </div>

        <div class="overflow-hidden rounded-[23px] bg-[#F8F7F5]">

  <a
 
               href="{{ route('page-profile.all-checks') }}"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-cog-6-tooth class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Все проверки
                    </span>
                </div>

                      <div class="ml-[15px] flex shrink-0 items-center">
                     <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2
                }}" />
                  
                </div>
            </a>
   

            <a
              href="/admin"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-cog-6-tooth class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Админ-панель
                    </span>
                </div>

                      <div class="ml-[15px] flex shrink-0 items-center">
                     <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2
                }}" />
                  
                </div>
            </a>

           
                       <a
              href="/admin/finance"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-cog-6-tooth class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Админ-панель финансы
                    </span>
                </div>

                      <div class="ml-[15px] flex shrink-0 items-center">
                     <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2
                }}" />
                  
                </div>
            </a>

                       <a
              href="/admin/education"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-cog-6-tooth class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Админ-панель обучения
                    </span>
                </div>

                      <div class="ml-[15px] flex shrink-0 items-center">
                     <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2
                }}" />
                  
                </div>
            </a>

          

           
        </div>


                            <div class="mb-[10px] pt-[20px]">
            <span class="text-[16px] opacity-50">
                Блок только для админа 
            </span>
        </div>

        <div class="overflow-hidden rounded-[23px] bg-[#F8F7F5]">


   

            <a
            target="_blank"
              href="https://t.me/cocenage"
                class="group flex items-center justify-between px-5 py-5 transition-colors duration-200 hover:bg-[#FAFAFA] active:bg-[#F3F3F3]"
            >
                <div class="flex min-w-0 items-center gap-4">
                      <x-heroicon-o-cog-6-tooth class="w-[24px] h-[24px] 
                }}" />
                
                   

                    <span class="truncate text-[18px]">
                        Связь с разрабом
                    </span>
                </div>

                      <div class="ml-[15px] flex shrink-0 items-center">
                     <x-heroicon-o-chevron-right class="w-[18px] h-[18px] transition-transform duration-200 group-hover:translate-x-[2px] stroke-2
                }}" />
                  
                </div>
            </a>

           

          

           
        </div>
    </div>

</div>