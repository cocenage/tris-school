@props(['record', 'direction' => 'all'])

@php
    [$panel, $accent] = match ($record->result_zone) {
        'green' => ['border-[#C8E3D2] bg-[#EDF8F1]', 'bg-[#D8F0E1] text-[#205F3A]'],
        'yellow' => ['border-[#EADCAA] bg-[#FFFAE9]', 'bg-[#F6E9B9] text-[#704E10]'],
        'red' => ['border-[#F1CCCA] bg-[#FFF0EF]', 'bg-[#FADBD8] text-[#9F302B]'],
        default => ['border-[#E3E5E8] bg-white', 'bg-[#ECEFF2] text-[#475467]'],
    };
    $person = $direction === 'received' ? $record->supervisor : $record->cleaner;
    $date = $record->inspection_date ?? $record->cleaning_date ?? $record->sent_at ?? $record->created_at;
    $percent = $record->score_percent ?? ($record->max_points > 0 ? round($record->total_points / $record->max_points * 100) : null);
@endphp

<a href="{{ route('page-profile.checks.result', $direction === 'all' ? [$record, 'from' => 'all'] : $record) }}"
    {{ $attributes->class(['block rounded-[26px] border px-4 py-3.5 text-[#182230] transition hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#285B86] active:scale-[0.99]', $panel]) }}>
    <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3">
        <span aria-hidden="true" class="flex size-10 items-center justify-center rounded-full {{ $accent }}">
            <x-heroicon-o-clipboard-document-check class="size-5" />
        </span>
        <div class="min-w-0">
            <h2 class="break-words text-base font-semibold leading-snug">{{ $record->apartment?->name ?? 'Квартира не указана' }}</h2>
            <p class="mt-0.5 break-words text-sm text-[#475467]">
                {{ $direction === 'received' ? 'Проверил' : 'Проверили' }} · {{ $person?->name ?? '—' }}
            </p>
            @if(filled($record->comment))
                <p class="mt-1.5 line-clamp-2 break-words text-sm text-[#475467]">{{ $record->comment }}</p>
            @endif
        </div>
        @if($percent !== null)
            <span class="pt-0.5 text-base font-bold tabular-nums">{{ max(0, min(100, (int) $percent)) }}%</span>
        @endif
    </div>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-black/10 pt-2.5">
        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $accent }}">{{ $record->result_zone_label }}</span>
        @if($date)
            <time datetime="{{ $date->toDateString() }}" class="text-xs font-medium tabular-nums text-[#475467]">{{ $date->format('d.m.Y') }}</time>
        @endif
    </div>
</a>
