@props(['active'])

<nav class="flex flex-wrap items-center gap-2" aria-label="Разделы контролей">
    <a href="{{ route('page-profile.checks') }}" @if($active === 'mine') aria-current="page" @endif
        class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold {{ $active === 'mine' ? 'bg-[#285B86] text-white' : 'bg-[#EDEFF2] text-[#344054]' }}">
        Мои контроли
    </a>
    @if(auth()->user()?->canViewAllStaffActivity())
        <a href="{{ route('page-profile.all-checks') }}" @if($active === 'all') aria-current="page" @endif
            class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-semibold {{ $active === 'all' ? 'bg-[#285B86] text-white' : 'bg-[#EDEFF2] text-[#344054]' }}">
            Все контроли
        </a>
    @endif
</nav>
