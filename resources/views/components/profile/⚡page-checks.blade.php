<?php

use App\Models\ControlResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $tab = 'received';

    public function getReceivedProperty()
    {
        return ControlResponse::query()
            ->with(['control', 'cleaner', 'supervisor', 'apartment'])
            ->where('cleaner_id', Auth::id())
            ->latest('sent_at')
            ->get();
    }

    public function getConductedProperty()
    {
        return ControlResponse::query()
            ->with(['control', 'cleaner', 'supervisor', 'apartment'])
            ->where('supervisor_id', Auth::id())
            ->latest('sent_at')
            ->get();
    }

    protected function percent(ControlResponse $record): ?int
    {
        if ($record->score_percent !== null) {
            return max(0, min(100, (int) $record->score_percent));
        }

        if (! $record->max_points) {
            return null;
        }

        return (int) round(($record->total_points / $record->max_points) * 100);
    }

    protected function color(ControlResponse $record): string
    {
        $zone = (string) ($record->result_zone ?? '');

        if ($zone === 'green') {
            return '#27AE60';
        }

        if ($zone === 'yellow') {
            return '#2D6494';
        }

        if ($zone === 'red') {
            return '#D92D20';
        }

        $percent = $this->percent($record);

        return match (true) {
            $percent === null => '#7D7D7D',
            $percent >= 80 => '#27AE60',
            $percent >= 50 => '#2D6494',
            default => '#D92D20',
        };
    }

    protected function zoneLabel(ControlResponse $record): string
    {
        return match ($record->result_zone) {
            'green' => 'Зелёная зона',
            'yellow' => 'Жёлтая зона',
            'red' => 'Красная зона',
            default => 'Без зоны',
        };
    }
};
?>

<x-slot:header>
    <div class="w-full h-[70px] flex items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2]" />
        </button>

        <span class="text-[18px] leading-none">
            Мои проверки
        </span>

        <div class="h-[36px] w-[36px]"></div>
    </div>
</x-slot:header>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="min-h-full rounded-t-[38px] bg-white">
            <div class="p-[20px] pb-[40px]">

                <div class="mb-[20px] grid grid-cols-2 gap-[10px] rounded-full bg-[#E2E2E2] p-[4px]">
                    <button
                        type="button"
                        wire:click="$set('tab', 'received')"
                        class="h-[38px] rounded-full text-[14px] font-semibold transition {{ $tab === 'received' ? 'bg-white text-[#111111] shadow-sm' : 'text-black/45' }}"
                    >
                        Меня проверили
                    </button>

                    <button
                        type="button"
                        wire:click="$set('tab', 'conducted')"
                        class="h-[38px] rounded-full text-[14px] font-semibold transition {{ $tab === 'conducted' ? 'bg-white text-[#111111] shadow-sm' : 'text-black/45' }}"
                    >
                        Я проверил
                    </button>
                </div>

                @php
                    $items = $tab === 'received' ? $this->received : $this->conducted;
                @endphp

                <div class="space-y-[14px]">
                    @forelse($items as $item)
                        @php
                            $percent = $this->percent($item);
                            $color = $this->color($item);
                            $zoneLabel = $this->zoneLabel($item);

                            $title = $tab === 'received'
                                ? 'Контроль прошёл'
                                : 'Вы провели контроль';

                            $person = $tab === 'received'
                                ? ($item->supervisor?->name ?? '—')
                                : ($item->cleaner?->name ?? '—');

                            $apartmentName = $item->apartment?->name ?? 'Квартира не указана';
                            $comment = trim((string) ($item->comment ?? ''));
                        @endphp

                        <a
                            href="{{ route('page-profile.checks.result', $item) }}"
                            class="block overflow-hidden border-[2px] bg-white active:scale-[0.99] transition"
                            style="border-color: {{ $color }}; border-radius: 34px;"
                        >
                            <div
                                class="px-[18px] pb-[38px] pt-[16px] text-white"
                                style="background: {{ $color }};"
                            >
                                <div class="flex items-start justify-between gap-[12px]">
                                    <div class="min-w-0">
                                        <div class="text-[17px] font-semibold">
                                            {{ $title }}
                                        </div>

                                        <div class="mt-[5px] text-[13px] text-white/75">
                                            {{ $apartmentName }}
                                        </div>
                                    </div>

                                    <div class="shrink-0 rounded-full bg-white/15 px-[10px] py-[6px] text-[12px] font-semibold">
                                        {{ $percent !== null ? $percent . '%' : '—' }}
                                    </div>
                                </div>
                            </div>

                            <div
                                class="-mt-[24px] bg-white px-[16px] pb-[16px] pt-[18px]"
                                style="border-radius: 28px 28px 30px 30px;"
                            >
                                <div class="grid grid-cols-2 gap-[10px] text-[13px]">
                                    <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                        <div class="text-black/40">
                                            {{ $tab === 'received' ? 'Проверил' : 'Кого проверили' }}
                                        </div>

                                        <div class="mt-[3px] font-semibold text-[#111111] truncate">
                                            {{ $person }}
                                        </div>
                                    </div>

                                    <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                        <div class="text-black/40">
                                            Дата проверки
                                        </div>

                                        <div class="mt-[3px] font-semibold text-[#111111]">
                                            {{ optional($item->inspection_date)->format('d.m.Y') ?? '—' }}
                                        </div>
                                    </div>

                                    <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                        <div class="text-black/40">
                                            Баллы
                                        </div>

                                        <div class="mt-[3px] font-semibold text-[#111111]">
                                            {{ (int) $item->total_points }}/{{ (int) $item->max_points }}
                                        </div>
                                    </div>

                                    <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                        <div class="text-black/40">
                                            Дата уборки
                                        </div>

                                        <div class="mt-[3px] font-semibold text-[#111111]">
                                            {{ optional($item->cleaning_date)->format('d.m.Y') ?? '—' }}
                                        </div>
                                    </div>

                                    <div class="col-span-2 rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                        <div class="text-black/40">
                                            Итог
                                        </div>

                                        <div class="mt-[3px] font-semibold text-[#111111]">
                                            {{ $zoneLabel }}
                                        </div>
                                    </div>

                                    @if($comment !== '')
                                        <div class="col-span-2 rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                            <div class="text-black/40">
                                                Комментарий
                                            </div>

                                            <div class="mt-[3px] text-[13px] leading-[1.4] font-medium text-[#111111]">
                                                {{ $comment }}
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="rounded-[28px] bg-[#F8F8F8] px-[18px] py-[20px] text-center text-[15px] text-black/45">
                            Тут пока пусто
                        </div>
                    @endforelse
                </div>

            </div>
        </div>
    </div>
</div>