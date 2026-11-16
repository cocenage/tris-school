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
            || $user?->canViewAllStaffActivity(),
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

        $errors = ControlResponse::analyzeAnswers($schema, $responses)['errors'] ?? [];

        return array_map(function (array $error) use ($responses): array {
            $answer = data_get($responses, "{$error['room_index']}.{$error['question_index']}", []);
            $answer = is_array($answer) ? $answer : [];
            $error['corrective'] = is_array($answer['corrective'] ?? null)
                ? $answer['corrective']
                : ['repeats' => null, 'action' => '', 'recheck' => null];
            return $error;
        }, collect($errors)->sortByDesc('is_critical')->values()->all());
    }

    protected function roomSummary(array $errors): array
    {
        $schema = is_array($this->controlResponse->schema_snapshot)
            ? $this->controlResponse->schema_snapshot
            : [];
        return collect($schema)
            ->map(function (mixed $room, int $roomIndex) use ($errors): array {
                if (! is_array($room)) {
                    return [
                        'title' => 'Комната',
                        'total' => 0,
                        'errors' => 0,
                    ];
                }

                $title = (string) ($room['title'] ?? 'Комната');
                $roomErrors = collect($errors)->where('room_index', $roomIndex)->values();

                return [
                    'title' => $title,
                    'total' => collect($room['items'] ?? [])->count(),
                    'errors' => $roomErrors->count(),
                ];
            })
            ->values()
            ->all();
    }
};
?>

@php
    $statsBack = request()->query('stats_view') === 'quality'
        && (int) $controlResponse->cleaner_id === (int) auth()->id()
        ? route('page-profile', request()->only(['stats_month', 'stats_view', 'stats_day'])).'#profile-statistics-title'
        : null;
    $canReturnToAll = request()->query('from') === 'all' && Auth::user()?->canViewAllStaffActivity();
    $backUrl = $statsBack ?? ($canReturnToAll
        ? route('page-profile.all-checks')
        : route('page-profile.checks', $controlResponse->supervisor_id === Auth::id() ? ['tab' => 'conducted'] : []));
@endphp

<x-slot:header>
    <div class="w-full h-[70px] flex items-center justify-between px-[15px]">
        <a href="{{ $backUrl }}"
            aria-label="{{ $statsBack ? 'Назад к статистике' : ($canReturnToAll ? 'Все контроли' : 'Мои контроли') }}"
            class="flex size-11 items-center justify-center rounded-full bg-[#EFF1F3] text-[#213259]">
            <x-heroicon-o-arrow-left class="size-5 stroke-2" />
        </a>

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
    $mediaItems = $record->mediaItems();
    $mediaByQuestion = collect($mediaItems)->keyBy(fn (array $item) => $item['room_index'].'-'.$item['question_index']);
    $roomSummary = $this->roomSummary($errors);

    $apartmentName = $record->apartment?->name ?? 'Квартира не указана';
    $comment = trim((string) ($record->comment ?? ''));
@endphp

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="min-h-full rounded-t-[38px] bg-white">
            <div x-data="controlMediaGallery" class="p-[20px] pb-[40px]">

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
                                        {{ $record->result_zone_reason ?: 'Требует внимания' }}
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

                @if($errors !== [] || $record->result_zone === 'green')
                    <section aria-labelledby="control-findings-title" class="mb-[18px]">
                        <h2 id="control-findings-title" class="mb-[10px] text-[16px] font-semibold text-[#111111]">
                            Замечания <span class="text-sm font-normal text-[#596476]">· {{ count($errors) }}</span>
                        </h2>

                        @if(empty($errors))
                            <p class="rounded-[28px] bg-[#F0FDF4] px-[18px] py-[20px] text-center text-[15px] font-semibold text-[#166534]">
                                Ошибок нет
                            </p>
                        @else
                            <div class="space-y-[12px]">
                                @foreach($errors as $error)
                                    @php
                                        $questionKey = $error['room_index'].'-'.$error['question_index'];
                                        $evidenceCount = count($mediaByQuestion->get($questionKey)['media'] ?? []);
                                        $corrective = is_array($error['corrective'] ?? null) ? $error['corrective'] : [];
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

                                            @if(filled($corrective['action'] ?? null) || ($corrective['repeats'] ?? null) !== null || ($corrective['recheck'] ?? null) !== null)
                                                <div class="mt-[12px] rounded-[18px] bg-[#FFFAEB] px-[12px] py-[10px] text-[13px] text-[#6B4F12]">
                                                    <div class="font-semibold">Корректирующие действия</div>
                                                    @if(filled($corrective['action'] ?? null))
                                                        <div class="mt-[4px]"><span class="font-semibold">Что сделать:</span> {{ $corrective['action'] }}</div>
                                                    @endif
                                                    @if(($corrective['repeats'] ?? null) !== null)
                                                        <div class="mt-[3px]">Ошибка повторяется: {{ $corrective['repeats'] ? 'да' : 'нет' }}</div>
                                                    @endif
                                                    @if(($corrective['recheck'] ?? null) !== null)
                                                        <div class="mt-[3px]">Повторный контроль: {{ $corrective['recheck'] ? 'нужен' : 'не нужен' }}</div>
                                                    @endif
                                                </div>
                                            @endif

                                            @if($evidenceCount > 0)
                                                <button type="button" data-finding-media="{{ $questionKey }}" x-on:click="openFinding($el)"
                                                    aria-haspopup="dialog" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-full bg-[#EDF4FF] px-4 text-sm font-semibold text-[#204579]">
                                                    <x-heroicon-o-paper-clip class="size-4" />
                                                    Фото и видео · {{ $evidenceCount }}
                                                    <span class="sr-only">— {{ $error['room_title'] }}, {{ $error['question'] }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                @if(! empty($roomSummary))
                    <div class="mb-[18px]">
                        <div class="mb-[10px] text-[16px] font-semibold text-[#111111]">
                            Комнаты
                        </div>

                        <div class="space-y-[8px]">
                            @foreach($roomSummary as $room)
                                <div class="flex items-center justify-between gap-[12px] rounded-[18px] bg-[#F8F8F8] px-[14px] py-[11px]">
                                    <span class="min-w-0 truncate text-[14px] font-semibold text-[#111111]">
                                        {{ $room['title'] }}
                                    </span>

                                    @if($room['errors'] > 0)
                                        <span class="shrink-0 rounded-full bg-[#FEE2E2] px-[9px] py-[5px] text-[12px] font-bold text-[#991B1B]">
                                            {{ $room['errors'] }} {{ $room['errors'] === 1 ? 'проблема' : 'проблем' }}
                                        </span>
                                    @else
                                        <span class="shrink-0 rounded-full bg-[#E7F8EF] px-[9px] py-[5px] text-[12px] font-bold text-[#16834B]">
                                            без ошибок
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($mediaItems !== [])
                    <section
                        aria-labelledby="control-media-title"
                        class="mb-[18px]"
                    >
                        <h2 id="control-media-title" class="mb-[10px] text-[16px] font-semibold text-[#111111]">
                            Фото и видео <span class="text-sm font-normal text-[#596476]">· {{ array_sum(array_map(fn ($item) => count($item['media']), $mediaItems)) }}</span>
                        </h2>
                        <div class="space-y-2">
                            @foreach($mediaItems as $item)
                                <details data-media-group="{{ $item['room_index'] }}-{{ $item['question_index'] }}"
                                    data-media-title="{{ $item['room'] }} · {{ $item['question'] }}"
                                    class="rounded-[18px] bg-[#F8F8F8]">
                                    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">
                                        {{ $item['room'] }} · {{ $item['question'] }} <span class="font-normal text-[#596476]">({{ count($item['media']) }})</span>
                                    </summary>
                                    <div class="grid grid-cols-2 gap-2 px-3 pb-3 min-[420px]:grid-cols-3">
                                        @foreach($item['media'] as $file)
                                            @php
                                                $fileComment = trim((string) ($file['comment'] ?? ''));
                                                $isVideo = str_starts_with((string) ($file['mime'] ?? ''), 'video/');
                                            @endphp
                                            <figure class="min-w-0 overflow-hidden rounded-[14px] bg-white">
                                                <button
                                                    type="button"
                                                    data-control-media
                                                    data-url="{{ $file['url'] }}"
                                                    data-kind="{{ $isVideo ? 'video' : 'image' }}"
                                                    data-caption="{{ $fileComment }}"
                                                    x-on:click="open($el)"
                                                    aria-label="{{ $isVideo ? 'Открыть видео' : 'Открыть фото' }} {{ $loop->iteration }}: {{ $item['question'] }}"
                                                    class="block w-full overflow-hidden rounded-[14px] bg-[#EDF1F5] text-[#344054] focus-visible:outline-2 focus-visible:outline-[#285B86]"
                                                >
                                                    @if($isVideo)
                                                        <span class="grid aspect-[4/3] place-content-center justify-items-center gap-1 text-xs">
                                                            <x-heroicon-o-play-circle class="size-8" />Видео
                                                        </span>
                                                    @else
                                                        <img src="{{ $file['thumbnail_url'] }}" alt="" loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover">
                                                    @endif
                                                </button>
                                                @if($fileComment !== '')
                                                    <figcaption class="line-clamp-2 whitespace-pre-wrap break-words px-2 py-2 text-[13px] leading-[1.4] text-[#263238]">{{ $fileComment }}</figcaption>
                                                @endif
                                            </figure>
                                        @endforeach
                                    </div>
                                </details>
                            @endforeach
                        </div>

                        <dialog x-ref="viewer" role="dialog" aria-modal="true" aria-label="Фото и видео контроля"
                            x-on:close="reset()" x-on:click.self="close()"
                            x-on:keydown="handleKey($event)"
                            class="m-auto max-h-[96dvh] w-[min(98vw,900px)] max-w-full overflow-y-auto rounded-2xl border-0 bg-[#101823] p-3 text-white backdrop:bg-black/80 sm:p-5">
                            <template x-if="current">
                                <div>
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <div class="min-w-0 text-sm">
                                            <p class="break-words font-semibold" x-text="title"></p>
                                            <span class="text-white/75" aria-live="polite" x-text="`${index + 1} / ${items.length}`"></span>
                                        </div>
                                        <button type="button" x-on:click="close()" autofocus aria-label="Закрыть просмотр" class="min-h-11 min-w-11 rounded-full bg-white/10 text-2xl">×</button>
                                    </div>
                                    <div class="grid min-h-[40dvh] touch-pan-y touch-pinch-zoom place-items-center overflow-hidden rounded-xl bg-black"
                                        x-on:pointerdown="beginSwipe($event)" x-on:pointerup="endSwipe($event)" x-on:pointercancel="cancelSwipe()">
                                        <template x-if="current.kind === 'video' && !failed">
                                            <video :src="current.url" controls playsinline preload="none" x-on:error="failed = true" class="max-h-[62dvh] w-full"></video>
                                        </template>
                                        <template x-if="current.kind === 'image' && !failed">
                                            <img :src="current.url" alt="Фото к вопросу" draggable="false" x-on:error="failed = true" class="mx-auto max-h-[62dvh] max-w-full object-contain">
                                        </template>
                                        <p x-show="failed" x-cloak class="p-4 text-center text-sm">Этот файл не открывается в браузере. Можно скачать оригинал.</p>
                                    </div>
                                    <p x-show="current.caption" x-text="current.caption" class="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed"></p>
                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                        <button type="button" x-on:click="step(-1)" :disabled="index === 0" aria-label="Предыдущее вложение" class="min-h-11 min-w-11 rounded-full bg-white/10 disabled:opacity-30">←</button>
                                        <a :href="current.url + '?download=1'" class="inline-flex min-h-11 items-center rounded-full bg-white/10 px-4 text-sm font-semibold">Скачать оригинал</a>
                                        <button type="button" x-on:click="step(1)" :disabled="index === items.length - 1" aria-label="Следующее вложение" class="min-h-11 min-w-11 rounded-full bg-white/10 disabled:opacity-30">→</button>
                                    </div>
                                </div>
                            </template>
                        </dialog>
                    </section>
                @endif
            </div>
        </div>
    </div>
</div>
