<?php

use Livewire\Component;

new class extends Component {};
?>

@if (auth()->user()?->isApproved())
    @php
        $home = request()->routeIs('page-home');
        $activeTab = match (true) {
            request()->routeIs('page-applications*', 'page-profile.applications') => 'applications',
            request()->routeIs('page-checks*', 'page-coachings*', 'page-profile.checks*', 'page-profile.all-checks', 'page-profile.employee-history') => 'checks',
            request()->routeIs('page-tasks*', 'page-profile.calendar', 'page-profile.employee-calendars', 'employees.calendar') => 'calendar',
            request()->routeIs('page-profile') => 'profile',
            default => 'home',
        };
        $notificationBadge = app(\App\Services\RolePreviewContext::class)->isActive()
            ? null
            : app(\App\Services\UserApplicationBadgeService::class)->label(auth()->id());
        $tabs = [
            'checks' => ['page-checks', 'Контроли', 'heroicon-o-clipboard-document-check'],
            'calendar' => ['page-tasks.calendar', 'Календарь', 'heroicon-o-calendar-days'],
            'home' => ['page-home', 'Главная', null],
            'applications' => ['page-applications', 'Заявки', 'heroicon-o-clipboard-document-list'],
            'profile' => ['page-profile', 'Профиль', 'heroicon-o-user'],
        ];
    @endphp

    <nav aria-label="Основная навигация" class="grid grid-cols-5 items-center gap-1 px-2 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
        @foreach ($tabs as $key => [$routeName, $label, $icon])
            @php($active = $activeTab === $key)
            <a href="{{ route($routeName) }}" aria-label="{{ $label }}" @if ($active) aria-current="{{ $home ? 'page' : 'location' }}" @endif
                class="relative flex min-h-12 min-w-0 flex-col items-center justify-center gap-1 rounded-2xl px-0.5 text-[11px] font-medium leading-tight {{ $active ? 'bg-[#E7EBF2] text-[#213259]' : 'text-[#515967] hover:bg-white' }}">
                @if ($icon)
                    <x-dynamic-component :component="$icon" class="size-5" />
                @else
                    <span aria-hidden="true" class="rounded-full bg-[#213259] px-2.5 py-1 text-[11px] font-bold tracking-wide text-white">TRIS</span>
                @endif
                @if ($home || $active)
                    <span>{{ $label }}</span>
                @endif
                @if ($key === 'profile' && $notificationBadge)
                    <span aria-hidden="true" class="absolute right-1 top-1 rounded-full bg-[#213259] px-1.5 py-0.5 text-[9px] leading-none text-white">{{ $notificationBadge }}</span>
                @endif
            </a>
        @endforeach
    </nav>
@endif
