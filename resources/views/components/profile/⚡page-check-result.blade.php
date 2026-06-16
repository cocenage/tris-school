<?php

use App\Models\ControlResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public ControlResponse $controlResponse;

    public function mount(ControlResponse $controlResponse): void
    {
        $user = Auth::user();

        abort_unless(
            $controlResponse->cleaner_id === Auth::id()
            || $controlResponse->supervisor_id === Auth::id()
            || in_array($user?->role, ['admin', 'supervisor'], true),
            403
        );

        $this->controlResponse = $controlResponse->load([
            'control',
            'cleaner',
            'supervisor',
            'apartment',
        ]);
    }

    protected function color(): string
    {
        return match ($this->controlResponse->result_zone) {
            'green' => '#27AE60',
            'yellow' => '#F59E0B',
            'red' => '#D92D20',
            default => '#7D7D7D',
        };
    }

    protected function zoneLabel(): string
    {
        return match ($this->controlResponse->result_zone) {
            'green' => 'Зелёная зона',
            'yellow' => 'Жёлтая зона',
            'red' => 'Красная зона',
            default => 'Без зоны',
        };
    }

    protected function errors(): array
    {
        $schema = is_array($this->controlResponse->schema_snapshot)
            ? $this->controlResponse->schema_snapshot
            : [];

        $responses = is_array($this->controlResponse->responses)
            ? $this->controlResponse->responses
            : [];

        return ControlResponse::analyzeAnswers($schema, $responses)['errors'] ?? [];
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
            Результат контроля
        </span>

        <div class="h-[36px] w-[36px]"></div>
    </div>
</x-slot:header>

@php
    $record = $controlResponse;
    $color = $this->color();
    $zoneLabel = $this->zoneLabel();
    $errors = $this->errors();

    $apartmentName = $record->apartment?->name ?? 'Квартира не указана';
    $comment = trim((string) ($record->comment ?? ''));
@endphp

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="min-h-full rounded-t-[38px] bg-white">
            <div class="p-[20px] pb-[40px]">

                <div
                    class="mb-[18px] overflow-hidden border-[2px] bg-white"
                    style="border-color: {{ $color }}; border-radius: 34px;"
                >
                    <div class="px-[18px] pb-[42px] pt-[18px] text-white" style="background: {{ $color }};">
                        <div class="flex items-start justify-between gap-[12px]">
                            <div class="min-w-0">
                                <div class="truncate text-[20px] font-semibold">
                                    {{ $apartmentName }}
                                </div>

                                <div class="mt-[6px] text-[13px] text-white/75">
                                    {{ optional($record->inspection_date)->format('d.m.Y') ?? '—' }}
                                </div>
                            </div>

                            <div class="shrink-0 rounded-full bg-white/15 px-[12px] py-[7px] text-[13px] font-semibold">
                                {{ $zoneLabel }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="-mt-[24px] bg-white px-[16px] pb-[16px] pt-[18px]"
                        style="border-radius: 28px 28px 30px 30px;"
                    >
                        <div class="grid grid-cols-2 gap-[10px] text-[13px]">
                            <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Кого проверили</div>
                                <div class="mt-[3px] truncate font-semibold text-[#111111]">
                                    {{ $record->cleaner?->name ?? '—' }}
                                </div>
                            </div>

                            <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Кто проверил</div>
                                <div class="mt-[3px] truncate font-semibold text-[#111111]">
                                    {{ $record->supervisor?->name ?? '—' }}
                                </div>
                            </div>

                            <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Ошибок</div>
                                <div class="mt-[3px] font-semibold text-[#111111]">
                                    {{ (int) $record->errors_count }}
                                </div>
                            </div>

                            <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Штраф</div>
                                <div class="mt-[3px] font-semibold text-[#111111]">
                                    {{ (int) $record->penalty_points }}
                                </div>
                            </div>

                            <div class="col-span-2 rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Причина зоны</div>
                                <div class="mt-[3px] font-semibold text-[#111111]">
                                    {{ $record->result_zone_reason ?: '—' }}
                                </div>
                            </div>

                            @if($record->has_critical_failure)
                                <div class="col-span-2 rounded-[18px] bg-[#FFF1F0] px-[13px] py-[11px]">
                                    <div class="text-[#D92D20]/70">Критическая ошибка</div>
                                    <div class="mt-[3px] font-semibold text-[#D92D20]">
                                        Первые два вопроса блока “Спальные места”
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if($comment !== '')
                            <div class="mt-[10px] rounded-[18px] bg-[#F8F8F8] px-[13px] py-[12px]">
                                <div class="text-[13px] text-black/40">Комментарий</div>
                                <div class="mt-[5px] text-[14px] font-medium leading-[1.4] text-[#111111]">
                                    {{ $comment }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                @if(empty($errors))
                    <div class="rounded-[28px] bg-[#F0FDF4] px-[18px] py-[20px] text-center text-[15px] font-semibold text-[#166534]">
                        Ошибок нет. Контроль в зелёной зоне.
                    </div>
                @else
                    <div class="mb-[10px] text-[16px] font-semibold text-[#111111]">
                        Ошибки контроля
                    </div>

                    <div class="space-y-[12px]">
                        @foreach($errors as $error)
                            @php
                                $media = is_array($error['media'] ?? null) ? $error['media'] : [];
                            @endphp

                            <div class="overflow-hidden rounded-[26px] border border-[#FECACA] bg-white">
                                <div class="flex items-center justify-between gap-[12px] bg-[#FEE2E2] px-[16px] py-[12px]">
                                    <div class="min-w-0 truncate text-[15px] font-bold text-[#991B1B]">
                                        {{ $error['room_title'] ?? 'Комната' }}
                                    </div>

                                    <div class="shrink-0 rounded-full bg-white px-[10px] py-[5px] text-[12px] font-bold text-[#991B1B]">
                                        −{{ (int) ($error['penalty_points'] ?? 0) }}
                                    </div>
                                </div>

                                <div class="p-[15px]">
                                    @if(! empty($error['is_critical']))
                                        <div class="mb-[10px] inline-flex rounded-full bg-[#D92D20] px-[10px] py-[5px] text-[12px] font-bold text-white">
                                            Критическая ошибка
                                        </div>
                                    @endif

                                    <div class="text-[13px] font-medium leading-[1.35] text-black/45">
                                        {{ $error['question'] ?? 'Вопрос' }}
                                    </div>

                                    <div class="mt-[8px] text-[15px] font-semibold leading-[1.35] text-[#111111]">
                                        {{ $error['selected_label'] ?? '—' }}
                                    </div>

                                    @if(! empty($media))
                                        <div class="mt-[12px] flex gap-[8px] overflow-x-auto pb-[2px]">
                                            @foreach($media as $photo)
                                                @php
                                                    $url = (string) ($photo['url'] ?? '');
                                                @endphp

                                                @if($url !== '')
                                                    <a href="{{ $url }}" target="_blank" class="shrink-0">
                                                        <img
                                                            src="{{ $url }}"
                                                            alt=""
                                                            class="h-[92px] w-[92px] rounded-[18px] border border-[#FECACA] object-cover"
                                                        >
                                                    </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

            </div>
        </div>
    </div>
</div>