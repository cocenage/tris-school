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

    protected function percent(): ?int
    {
        if ($this->controlResponse->score_percent !== null) {
            return max(0, min(100, (int) $this->controlResponse->score_percent));
        }

        if (! $this->controlResponse->max_points) {
            return null;
        }

        return (int) round(
            ($this->controlResponse->total_points / $this->controlResponse->max_points) * 100
        );
    }

    protected function color(): string
    {
        $zone = (string) ($this->controlResponse->result_zone ?? '');

        if ($zone === 'green') {
            return '#27AE60';
        }

        if ($zone === 'yellow') {
            return '#2D6494';
        }

        if ($zone === 'red') {
            return '#D92D20';
        }

        $percent = $this->percent();

        return match (true) {
            $percent === null => '#7D7D7D',
            $percent >= 80 => '#27AE60',
            $percent >= 50 => '#2D6494',
            default => '#D92D20',
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

    protected function answerText(array $question, array $answer): string
    {
        $selected = trim((string) ($answer['selected'] ?? ''));
        $custom = trim((string) ($answer['custom'] ?? ''));

        $label = $selected;

        foreach (($question['answer_options_scored'] ?? []) as $optIndex => $opt) {
            $value = trim((string) ($opt['value'] ?? ('option_' . $optIndex)));
            $optionLabel = trim((string) ($opt['label'] ?? ''));

            if ($selected !== '' && ($selected === $value || $selected === $optionLabel)) {
                $label = $optionLabel;
                break;
            }
        }

        if ($label !== '' && $custom !== '') {
            return $label . ' / ' . $custom;
        }

        return $label ?: ($custom ?: '—');
    }

    protected function answerColor(array $question, array $answer): string
    {
        $selected = trim((string) ($answer['selected'] ?? ''));

        if ($selected === '') {
            return '#7D7D7D';
        }

        foreach (($question['answer_options_scored'] ?? []) as $optIndex => $opt) {
            $value = trim((string) ($opt['value'] ?? ('option_' . $optIndex)));
            $optionLabel = trim((string) ($opt['label'] ?? ''));

            if ($selected === $value || $selected === $optionLabel) {
                return (bool) ($opt['is_positive'] ?? false)
                    ? '#27AE60'
                    : '#D92D20';
            }
        }

        return '#7D7D7D';
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
    $schema = is_array($record->schema_snapshot) ? $record->schema_snapshot : [];
    $responses = is_array($record->responses) ? $record->responses : [];

    $color = $this->color();
    $percent = $this->percent();
    $zoneLabel = $this->zoneLabel();

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
                                <div class="text-black/40">Дата уборки</div>
                                <div class="mt-[3px] font-semibold text-[#111111]">
                                    {{ optional($record->cleaning_date)->format('d.m.Y') ?? '—' }}
                                </div>
                            </div>

                            <div class="rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Баллы</div>
                                <div class="mt-[3px] font-semibold text-[#111111]">
                                    {{ (int) $record->total_points }}/{{ (int) $record->max_points }}
                                </div>
                            </div>

                            <div class="col-span-2 rounded-[18px] bg-[#F8F8F8] px-[13px] py-[11px]">
                                <div class="text-black/40">Итог</div>
                                <div class="mt-[3px] font-semibold text-[#111111]">
                                    {{ $zoneLabel }}
                                </div>
                            </div>

                            @if($record->has_critical_failure)
                                <div class="col-span-2 rounded-[18px] bg-[#FFF1F0] px-[13px] py-[11px]">
                                    <div class="text-[#D92D20]/70">Критическая ошибка</div>
                                    <div class="mt-[3px] font-semibold text-[#D92D20]">
                                        Есть отрицательный ответ в критическом вопросе
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

                @if(empty($schema))
                    <div class="rounded-[28px] bg-[#F8F8F8] px-[18px] py-[20px] text-center text-[15px] text-black/45">
                        Ответы не найдены
                    </div>
                @else
                    <div class="space-y-[16px]">
                        @foreach($schema as $roomIndex => $room)
                            <div class="overflow-hidden rounded-[30px] border border-[#E7E7E7] bg-white">
                                <div class="bg-[#F8F8F8] px-[18px] py-[14px]">
                                    <div class="text-[17px] font-semibold text-[#111111]">
                                        {{ $room['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                    </div>

                                    @if(!empty($room['description']))
                                        <div class="mt-[5px] text-[13px] leading-[1.4] text-black/45">
                                            {{ $room['description'] }}
                                        </div>
                                    @endif
                                </div>

                                <div class="space-y-[12px] p-[14px]">
                                    @foreach(($room['items'] ?? []) as $questionIndex => $question)
                                        @php
                                            $answer = $responses[$roomIndex][$questionIndex] ?? [];
                                            $answerColor = $this->answerColor($question, $answer);
                                        @endphp

                                        <div class="rounded-[22px] bg-[#F8F8F8] px-[14px] py-[13px]">
                                            <div class="text-[13px] font-medium leading-[1.35] text-black/45">
                                                {{ $question['question'] ?? 'Вопрос' }}
                                            </div>

                                            <div class="mt-[8px] flex items-start gap-[9px]">
                                                <span
                                                    class="mt-[3px] h-[10px] w-[10px] shrink-0 rounded-full"
                                                    style="background: {{ $answerColor }};"
                                                ></span>

                                                <div class="text-[15px] font-semibold leading-[1.35] text-[#111111]">
                                                    {{ $this->answerText($question, $answer) }}
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

            </div>
        </div>
    </div>
</div>