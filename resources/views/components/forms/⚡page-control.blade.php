<?php

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

new class extends Component {
    public ?Control $control = null;

    public array $rooms = [];
    public array $answers = [];

    public ?int $draftId = null;
    public string $draftState = 'idle';
    public ?string $draftSavedAt = null;
    public bool $autoSaveEnabled = false;
    public bool $hasUnsavedChanges = false;
    public ?string $lastDraftHash = null;

    public ?int $cleaner_id = null;
    public ?int $apartment_id = null;
    public ?string $cleaning_date = null;
    public ?string $inspection_date = null;
    public bool $is_assigned = false;
    public string $previous_cleaner = '';
    public string $comment = '';

    public bool $successSheetOpen = false;
    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->cleaning_date = now()->toDateString();
        $this->inspection_date = now()->toDateString();

        $this->control = Control::query()
            ->where('is_active', true)
            ->latest()
            ->first();

        abort_if(! $this->control, 404);

        $this->rooms = is_array($this->control->main)
            ? array_values($this->control->main)
            : [];

        $this->buildEmptyAnswers();
        $this->restoreDraft();

        $this->autoSaveEnabled = true;
    }

    protected function buildEmptyAnswers(): void
    {
        $this->answers = [];

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $this->answers[$roomIndex][$questionIndex] = [
                    'selected' => '',
                    'custom' => '',
                    'media' => [],
                ];
            }
        }
    }

    public function getPeopleProperty()
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'telegram_avatar_path']);
    }

    public function getApartmentsProperty()
    {
        return Apartment::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'image']);
    }

    public function updated(string $name): void
    {
        if (! $this->autoSaveEnabled) {
            return;
        }

        if (in_array($name, [
            'draftId',
            'draftState',
            'draftSavedAt',
            'autoSaveEnabled',
            'hasUnsavedChanges',
            'lastDraftHash',
            'successSheetOpen',
            'successMessage',
        ], true)) {
            return;
        }

        $this->touchAutosave();
    }

    protected function touchAutosave(): void
    {
        if (! $this->autoSaveEnabled) {
            return;
        }

        $this->hasUnsavedChanges = true;

        if ($this->draftState !== 'saving') {
            $this->draftState = 'dirty';
        }
    }

    protected function getDraftPayload(): array
    {
        return [
            'cleaner_id' => $this->cleaner_id,
            'apartment_id' => $this->apartment_id,
            'is_assigned' => $this->is_assigned,
            'previous_cleaner' => $this->previous_cleaner,
            'cleaning_date' => $this->cleaning_date,
            'inspection_date' => $this->inspection_date,
            'comment' => $this->comment,
            'responses' => $this->answers,
            'schema_snapshot' => $this->rooms,
        ];
    }

    protected function getDraftHash(): string
    {
        return md5(json_encode(
            $this->getDraftPayload(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    protected function hasMeaningfulDraftContent(): bool
    {
        if (
            filled($this->cleaner_id) ||
            filled($this->apartment_id) ||
            $this->is_assigned ||
            filled(trim($this->previous_cleaner)) ||
            filled(trim($this->comment))
        ) {
            return true;
        }

        foreach ($this->answers as $roomAnswers) {
            foreach (($roomAnswers ?? []) as $answer) {
                if (
                    filled(trim((string) ($answer['selected'] ?? ''))) ||
                    filled(trim((string) ($answer['custom'] ?? '')))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function persistDraft(bool $silent = false): void
    {
        if (! $this->control || ! Auth::check()) {
            return;
        }

        if (! $this->hasMeaningfulDraftContent()) {
            $this->draftState = 'idle';
            $this->hasUnsavedChanges = false;
            return;
        }

        if ($this->draftState === 'saving') {
            return;
        }

        $hash = $this->getDraftHash();

        if ($this->lastDraftHash !== null && $this->lastDraftHash === $hash) {
            $this->draftState = 'saved';
            $this->hasUnsavedChanges = false;
            return;
        }

        try {
            $this->draftState = 'saving';

            $draft = ControlResponseDraft::updateOrCreate(
                [
                    'control_id' => $this->control->id,
                    'supervisor_id' => Auth::id(),
                ],
                $this->getDraftPayload()
            );

            $this->draftId = $draft->id;
            $this->draftSavedAt = now()->format('H:i');
            $this->draftState = 'saved';
            $this->hasUnsavedChanges = false;
            $this->lastDraftHash = $hash;

            if (! $silent) {
                $this->dispatch('toast', type: 'success', message: 'Черновик сохранён');
            }
        } catch (\Throwable $e) {
            $this->draftState = 'error';

            if (! $silent) {
                $this->dispatch('toast', type: 'error', message: 'Не удалось сохранить черновик');
            }
        }
    }

    public function saveDraft(): void
    {
        $this->persistDraft(false);
    }

    public function saveDraftAuto(): void
    {
        $this->persistDraft(true);
    }

    protected function restoreDraft(): void
    {
        if (! $this->control || ! Auth::check()) {
            return;
        }

        $draft = ControlResponseDraft::query()
            ->where('control_id', $this->control->id)
            ->where('supervisor_id', Auth::id())
            ->first();

        if (! $draft) {
            return;
        }

        $this->draftId = $draft->id;
        $this->cleaner_id = $draft->cleaner_id;
        $this->apartment_id = $draft->apartment_id;
        $this->is_assigned = (bool) $draft->is_assigned;
        $this->previous_cleaner = (string) ($draft->previous_cleaner ?? '');
        $this->cleaning_date = optional($draft->cleaning_date)->toDateString() ?: now()->toDateString();
        $this->inspection_date = optional($draft->inspection_date)->toDateString() ?: now()->toDateString();
        $this->comment = (string) ($draft->comment ?? '');

        if (is_array($draft->responses)) {
            $this->answers = $draft->responses;
        }

        $this->draftSavedAt = optional($draft->updated_at)->format('H:i');
        $this->lastDraftHash = $this->getDraftHash();
        $this->hasUnsavedChanges = false;
        $this->draftState = 'saved';
    }

    protected function clearDraft(): void
    {
        if ($this->control && Auth::check()) {
            ControlResponseDraft::query()
                ->where('control_id', $this->control->id)
                ->where('supervisor_id', Auth::id())
                ->delete();
        }

        $this->draftId = null;
        $this->draftState = 'idle';
        $this->draftSavedAt = null;
        $this->hasUnsavedChanges = false;
        $this->lastDraftHash = null;
    }

    protected function resetControlForm(): void
    {
        $this->autoSaveEnabled = false;

        $this->cleaner_id = null;
        $this->apartment_id = null;
        $this->cleaning_date = now()->toDateString();
        $this->inspection_date = now()->toDateString();
        $this->is_assigned = false;
        $this->previous_cleaner = '';
        $this->comment = '';

        $this->buildEmptyAnswers();

        $this->resetErrorBag();
        $this->resetValidation();

        $this->autoSaveEnabled = true;
    }

    protected function questionIsOptional(array $room, array $question): bool
    {
        return (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false));
    }

    protected function isQuestionFilled(array $question, array $answer): bool
    {
        $type = (string) ($question['answer_type'] ?? 'options');

        $selected = trim((string) ($answer['selected'] ?? ''));
        $custom = trim((string) ($answer['custom'] ?? ''));

        return match ($type) {
            'text' => $custom !== '',
            'both' => $selected !== '' || $custom !== '',
            default => $selected !== '',
        };
    }

    public function getRoomStatus(int $roomIndex): string
    {
        $room = $this->rooms[$roomIndex] ?? null;

        if (! $room) {
            return 'empty';
        }

        $hasErrors = false;
        $hasSomething = false;
        $allRequiredFilled = true;
        $requiredCount = 0;

        foreach (($room['items'] ?? []) as $questionIndex => $question) {
            $answer = $this->answers[$roomIndex][$questionIndex] ?? [];
            $optional = $this->questionIsOptional($room, $question);

            if (! $optional) {
                $requiredCount++;
            }

            $filled = $this->isQuestionFilled($question, $answer);

            if ($filled) {
                $hasSomething = true;
            }

            if (! $optional && ! $filled) {
                $allRequiredFilled = false;
            }

            if ($this->getErrorBag()->has("answers.$roomIndex.$questionIndex")) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            return 'error';
        }

        if ($requiredCount > 0 && $allRequiredFilled) {
            return 'done';
        }

        if ($hasSomething) {
            return 'partial';
        }

        return 'empty';
    }

    protected function validateMeta(): void
    {
        if (! $this->cleaner_id || ! User::query()->whereKey($this->cleaner_id)->exists()) {
            $this->addError('cleaner_id', 'Выберите человека');
        }

        if (! $this->apartment_id || ! Apartment::query()->whereKey($this->apartment_id)->exists()) {
            $this->addError('apartment_id', 'Выберите квартиру');
        }

        if (! $this->cleaning_date) {
            $this->addError('cleaning_date', 'Укажите дату уборки');
        }

        if (! $this->inspection_date) {
            $this->addError('inspection_date', 'Укажите дату проверки');
        }

        if (mb_strlen($this->previous_cleaner) > 255) {
            $this->addError('previous_cleaner', 'Максимум 255 символов');
        }

        if (mb_strlen($this->comment) > 2000) {
            $this->addError('comment', 'Максимум 2000 символов');
        }
    }

    protected function validateRooms(): void
    {
        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                if ($this->questionIsOptional($room, $question)) {
                    continue;
                }

                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

                if (! $this->isQuestionFilled($question, $answer)) {
                    $this->addError("answers.$roomIndex.$questionIndex", 'Ответьте на вопрос');
                }
            }
        }
    }

    protected function scrollToFirstError(): void
    {
        $bag = $this->getErrorBag();

        if ($bag->isEmpty()) {
            return;
        }

        $firstKey = array_key_first($bag->toArray());

        if (! $firstKey) {
            return;
        }

        if (in_array($firstKey, [
            'cleaner_id',
            'apartment_id',
            'cleaning_date',
            'inspection_date',
            'previous_cleaner',
            'comment',
        ], true)) {
            $this->dispatch('control-scroll', type: 'meta', key: $firstKey);
            return;
        }

        if (preg_match('/^answers\.(\d+)\.(\d+)/', $firstKey, $m)) {
            $this->dispatch('control-scroll', type: 'question', room: (int) $m[1], q: (int) $m[2]);
            return;
        }

        $this->dispatch('control-scroll', type: 'top');
    }

    protected function calcPointsForAnswer(array $room, array $question, array $answer): int
    {
        $type = $question['answer_type'] ?? null;

        if ($type === 'text') {
            return 0;
        }

        $selected = trim((string) ($answer['selected'] ?? ''));

        if ($selected === '') {
            return 0;
        }

        foreach (($question['answer_options_scored'] ?? []) as $optIndex => $opt) {
            $value = trim((string) ($opt['value'] ?? ('option_' . $optIndex)));
            $label = trim((string) ($opt['label'] ?? ''));

            if ($selected === $value || $selected === $label) {
                return (int) ($opt['points'] ?? 0);
            }
        }

        return 0;
    }

    protected function calcMaxPointsForQuestion(array $room, array $question): int
    {
        if (($question['answer_type'] ?? null) === 'text') {
            return 0;
        }

        $max = 0;

        foreach (($question['answer_options_scored'] ?? []) as $opt) {
            $max = max($max, (int) ($opt['points'] ?? 0));
        }

        return $max;
    }

    protected function calculatePoints(): array
    {
        $totalPoints = 0;
        $maxPoints = 0;

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

                $totalPoints += $this->calcPointsForAnswer($room, $question, $answer);
                $maxPoints += $this->calcMaxPointsForQuestion($room, $question);
            }
        }

        return [$totalPoints, $maxPoints];
    }

    public function submit(): void
    {
        $this->resetErrorBag();

        $this->validateMeta();
        $this->validateRooms();

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'Вы заполнили не все поля');
            $this->scrollToFirstError();
            return;
        }

        [$totalPoints, $maxPoints] = $this->calculatePoints();

        ControlResponse::create([
            'control_id' => $this->control->id,
            'user_id' => $this->cleaner_id,
            'supervisor_id' => Auth::id(),
            'apartment' => (string) Apartment::query()->whereKey($this->apartment_id)->value('name'),
            'is_assigned' => $this->is_assigned,
            'previous_cleaner' => $this->previous_cleaner,
            'cleaning_date' => $this->cleaning_date,
            'inspection_date' => $this->inspection_date,
            'responses' => $this->answers,
            'schema_snapshot' => $this->rooms,
            'supervisor_comment' => $this->comment,
            'status' => 'sent',
            'sent_at' => now(),
            'total_points' => $totalPoints,
            'max_points' => $maxPoints,
        ]);

        $this->clearDraft();
        $this->resetControlForm();

        $this->successMessage = 'Всё круто, контроль отправился.';
        $this->successSheetOpen = true;
    }
};
?>

@push('meta')
    @if ($control)
        <title>{{ $control->name }} • Контроль</title>
        <meta name="description" content="Контроль качества: {{ $control->name }}.">
    @else
        <title>Контроль</title>
        <meta name="description" content="Чек-листы и контроль качества.">
    @endif
@endpush

<x-slot:header>
    <div class="w-full h-[70px] flex items-center justify-between px-[15px]">
        <button type="button" onclick="history.back()" class="group flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259]">
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2]" />
        </button>

        <span class="text-[18px] leading-none">
            Контроль качества
        </span>

        <div class="h-[36px] w-[36px]"></div>
    </div>
</x-slot:header>

<style>
    html { scroll-behavior: smooth; }
    [x-cloak] { display: none !important; }
</style>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <form
        wire:submit.prevent="submit"
        x-data="{
            timer: null,
            lastScrollTop: 0,
            buttonsHidden: false,

            init() {
                const el = this.$refs.scrollArea;
                if (!el) return;

                const onScroll = () => {
                    const current = el.scrollTop;
                    const maxScroll = el.scrollHeight - el.clientHeight;
                    const nearBottom = current >= (maxScroll - 140);

                    if (nearBottom || current <= 8) {
                        this.buttonsHidden = false;
                        this.lastScrollTop = current;
                        return;
                    }

                    if (current > this.lastScrollTop + 8) {
                        this.buttonsHidden = true;
                    } else if (current < this.lastScrollTop - 8) {
                        this.buttonsHidden = false;
                    }

                    this.lastScrollTop = current;
                };

                onScroll();
                el.addEventListener('scroll', onScroll, { passive: true });
            },

            save() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => $wire.saveDraftAuto(), 1200);
            }
        }"
        x-on:input="save()"
        x-on:change="save()"
        class="flex h-full min-h-0 flex-col"
    >
        <div x-ref="scrollArea" class="flex-1 min-h-0 overflow-y-auto">
            <div class="min-h-full rounded-t-[38px] bg-white">
                <div class="p-[20px] pb-[96px]">                    <div class="mb-[26px]" id="meta-block">
                        <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                            Основная информация
                        </h2>

                        <div class="space-y-[18px]">

                            {{-- Кого проверили --}}
                            <div id="field-cleaner_id">
                                <div class="mb-[10px] text-[15px] font-semibold text-[#111111]">
                                    Кого проверили
                                    <span class="text-[#2D6494]">*</span>
                                </div>

                                <select
                                    wire:model.live="cleaner_id"
                                    class="h-[44px] w-full rounded-full border-0 bg-[#E2E2E2] px-[18px] text-[15px] font-medium text-[#111111] focus:ring-0"
                                >
                                    <option value="">Выберите человека</option>

                                    @foreach($this->people as $person)
                                        <option value="{{ $person->id }}">
                                            {{ $person->name }}
                                        </option>
                                    @endforeach
                                </select>

                                @error('cleaner_id')
                                    <div class="mt-[8px] px-[4px] text-[14px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            {{-- Квартира --}}
                            <div id="field-apartment_id">
                                <div class="mb-[10px] text-[15px] font-semibold text-[#111111]">
                                    Квартира
                                    <span class="text-[#2D6494]">*</span>
                                </div>

                                <select
                                    wire:model.live="apartment_id"
                                    class="h-[44px] w-full rounded-full border-0 bg-[#E2E2E2] px-[18px] text-[15px] font-medium text-[#111111] focus:ring-0"
                                >
                                    <option value="">Выберите квартиру</option>

                                    @foreach($this->apartments as $apartment)
                                        <option value="{{ $apartment->id }}">
                                            {{ $apartment->name }}
                                        </option>
                                    @endforeach
                                </select>

                                @error('apartment_id')
                                    <div class="mt-[8px] px-[4px] text-[14px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            {{-- Дата уборки --}}
                            <div id="field-cleaning_date">
                                <div class="mb-[10px] text-[15px] font-semibold text-[#111111]">
                                    Дата уборки
                                    <span class="text-[#2D6494]">*</span>
                                </div>

                                <input
                                    type="date"
                                    wire:model.live="cleaning_date"
                                    class="h-[44px] w-full rounded-full border-0 bg-[#E2E2E2] px-[18px] text-[15px] font-medium text-[#111111] focus:ring-0"
                                >

                                @error('cleaning_date')
                                    <div class="mt-[8px] px-[4px] text-[14px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            {{-- Дата проверки --}}
                            <div id="field-inspection_date">
                                <div class="mb-[10px] text-[15px] font-semibold text-[#111111]">
                                    Дата проверки
                                    <span class="text-[#2D6494]">*</span>
                                </div>

                                <input
                                    type="date"
                                    wire:model.live="inspection_date"
                                    class="h-[44px] w-full rounded-full border-0 bg-[#E2E2E2] px-[18px] text-[15px] font-medium text-[#111111] focus:ring-0"
                                >

                                @error('inspection_date')
                                    <div class="mt-[8px] px-[4px] text-[14px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            {{-- Закреплён --}}
                            <label class="flex items-center gap-[10px] px-[4px]">
                                <input
                                    type="checkbox"
                                    wire:model.live="is_assigned"
                                    class="h-[18px] w-[18px] rounded border-[#D1D5DB] text-[#213259] focus:ring-[#213259]"
                                >

                                <span class="text-[15px] font-medium text-[#111111]">
                                    Человек закреплён за этой квартирой
                                </span>
                            </label>

                            {{-- Кто убирал до --}}
                            <div id="field-previous_cleaner">
                                <div class="mb-[10px] text-[15px] font-semibold text-[#111111]">
                                    Кто делал уборку до этого
                                </div>

                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="previous_cleaner"
                                    placeholder="Введите имя"
                                    class="h-[44px] w-full rounded-full border-0 bg-[#E2E2E2] px-[18px] text-[15px] font-medium text-[#111111] placeholder:text-black/40 focus:ring-0"
                                >

                                @error('previous_cleaner')
                                    <div class="mt-[8px] px-[4px] text-[14px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Якоря комнат --}}
                    @if(count($rooms))
                        <div class="sticky top-[10px] z-40 mb-[14px] bg-white/90 py-[4px] backdrop-blur">
                            <div class="flex gap-[8px] overflow-x-auto">
                                @foreach($rooms as $roomIndex => $roomTab)
                                    @php
                                        $status = $this->getRoomStatus($roomIndex);

                                        $tabClass = match($status) {
                                            'done' => 'bg-[#27AE60] text-white',
                                            'partial' => 'bg-[#2D6494] text-white',
                                            'error' => 'bg-[#D92D20] text-white',
                                            default => 'bg-[#7D7D7D] text-white',
                                        };
                                    @endphp

                                    <button
                                        type="button"
                                        onclick="document.getElementById('room-{{ $roomIndex }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                                        class="shrink-0 rounded-full px-[14px] py-[8px] text-[13px] font-semibold {{ $tabClass }}"
                                    >
                                        {{ $roomTab['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Комнаты --}}
                        <div class="space-y-[16px]">
                            @foreach($rooms as $roomIndex => $room)
                                @php
                                    $roomStatus = $this->getRoomStatus($roomIndex);

                                    $roomColor = match($roomStatus) {
                                        'done' => '#27AE60',
                                        'partial' => '#2D6494',
                                        'error' => '#D92D20',
                                        default => '#7D7D7D',
                                    };
                                @endphp

                                <div
                                    id="room-{{ $roomIndex }}"
                                    class="overflow-hidden border-[2px] bg-white scroll-mt-[90px]"
                                    style="border-color: {{ $roomColor }}; border-radius: 48px 28px 28px 28px;"
                                >
                                    <div
                                        class="px-[28px] pb-[54px] pt-[22px] text-white"
                                        style="background: {{ $roomColor }};"
                                    >
                                        <div class="text-[18px] font-semibold">
                                            {{ $room['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                        </div>

                                        @if(!empty($room['description']))
                                            <div class="mt-[6px] text-[13px] opacity-80">
                                                {{ $room['description'] }}
                                            </div>
                                        @endif
                                    </div>

                                    <div
                                        class="-mt-[34px] space-y-[14px] bg-white p-[14px]"
                                        style="border-radius: 38px 24px 24px 24px;"
                                    >
                                        @foreach(($room['items'] ?? []) as $questionIndex => $question)
                                            @php
                                                $opts = $question['answer_options_scored'] ?? [];
                                                $type = $question['answer_type'] ?? 'options';
                                                $optional = $this->questionIsOptional($room, $question);
                                            @endphp

                                            <div id="question-{{ $roomIndex }}-{{ $questionIndex }}">
                                                <div class="mb-[10px] px-[4px] text-[14px] font-semibold text-[#111111]">
                                                    {{ $question['question'] ?? 'Вопрос' }}

                                                    @if(!$optional)
                                                        <span class="text-[#2D6494]">*</span>
                                                    @endif
                                                </div>

                                                @error("answers.$roomIndex.$questionIndex")
                                                    <div class="mb-[8px] px-[4px] text-[14px] text-[#D92D20]">
                                                        {{ $message }}
                                                    </div>
                                                @enderror

                                                @if($type === 'options')
                                                    <div class="space-y-[8px]">
                                                        @foreach($opts as $optIndex => $opt)
                                                            <label class="flex h-[42px] items-center gap-[10px] rounded-full bg-[#E2E2E2] px-[16px]">
                                                                <input
                                                                    type="radio"
                                                                    wire:model.live="answers.{{ $roomIndex }}.{{ $questionIndex }}.selected"
                                                                    value="{{ $opt['value'] ?? ('option_' . $optIndex) }}"
                                                                    class="h-[16px] w-[16px]"
                                                                >

                                                                <span class="text-[14px] font-medium text-black/50">
                                                                    {{ $opt['label'] ?? 'Вариант' }}
                                                                </span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                @if($type === 'text')
                                                    <input
                                                        type="text"
                                                        wire:model.live.debounce.300ms="answers.{{ $roomIndex }}.{{ $questionIndex }}.custom"
                                                        placeholder="Введите ответ"
                                                        class="h-[42px] w-full rounded-full border-0 bg-[#E2E2E2] px-[16px] text-[14px] focus:ring-0"
                                                    >
                                                @endif

                                                @if($type === 'both')
                                                    <div class="space-y-[8px]">
                                                        @foreach($opts as $optIndex => $opt)
                                                            <label class="flex h-[42px] items-center gap-[10px] rounded-full bg-[#E2E2E2] px-[16px]">
                                                                <input
                                                                    type="radio"
                                                                    wire:model.live="answers.{{ $roomIndex }}.{{ $questionIndex }}.selected"
                                                                    value="{{ $opt['value'] ?? ('option_' . $optIndex) }}"
                                                                    class="h-[16px] w-[16px]"
                                                                >

                                                                <span class="text-[14px] font-medium text-black/50">
                                                                    {{ $opt['label'] ?? 'Вариант' }}
                                                                </span>
                                                            </label>
                                                        @endforeach

                                                        <input
                                                            type="text"
                                                            wire:model.live.debounce.300ms="answers.{{ $roomIndex }}.{{ $questionIndex }}.custom"
                                                            placeholder="Другое"
                                                            class="h-[42px] w-full rounded-full border-0 bg-[#E2E2E2] px-[16px] text-[14px] focus:ring-0"
                                                        >
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Комментарий --}}
                    <div class="mt-[24px]" id="field-comment">
                        <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                            Комментарий
                        </h2>

                        <textarea
                            wire:model.live.debounce.300ms="comment"
                            rows="4"
                            placeholder="Комментарий супервайзера"
                            class="w-full rounded-[24px] border border-[#E7E7E7] bg-[#F8F8F8] px-[20px] py-[15px] text-[15px] focus:ring-0"
                        ></textarea>
                    </div>
                </div>
            </div>
        </div>

                <div
            class="shrink-0 overflow-hidden bg-transparent"
            :class="buttonsHidden ? 'max-h-0' : 'max-h-[104px]'"
            style="transition: max-height 300ms ease;"
        >
            <div class="border-t border-[#E3EAF0] bg-white/95 px-5 pb-5 pt-4 backdrop-blur supports-[backdrop-filter]:bg-white/80">
                <div class="grid grid-cols-3 gap-[10px]">
                    <div class="col-span-1">
                        <x-ui.button
                            type="button"
                            variant="secondary"
                            wire:click="saveDraft"
                            wire:loading.attr="disabled"
                            wire:target="saveDraft,saveDraftAuto,submit"
                        >
                            <span wire:loading.remove wire:target="saveDraft">
                                Сохранить
                            </span>

                            <span
                                wire:loading
                                wire:target="saveDraft"
                                class="inline-flex items-center"
                            >
                                <span>Сохраняем</span>

                                <span class="inline-flex items-center relative top-[-1px] leading-none">
                                    <span class="inline-block animate-bounce [animation-delay:0ms]">.</span>
                                    <span class="inline-block animate-bounce [animation-delay:150ms]">.</span>
                                    <span class="inline-block animate-bounce [animation-delay:300ms]">.</span>
                                </span>
                            </span>
                        </x-ui.button>
                    </div>

                    <div class="col-span-2">
                        <x-ui.button
                            type="submit"
                            variant="primary"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                        >
                            <span wire:loading.remove wire:target="submit">
                                Отправить
                            </span>

                            <span
                                wire:loading
                                wire:target="submit"
                                class="inline-flex items-center"
                            >
                                <span>Отправляем</span>

                                <span class="inline-flex items-center relative top-[-1px] leading-none">
                                    <span class="inline-block animate-bounce [animation-delay:0ms]">.</span>
                                    <span class="inline-block animate-bounce [animation-delay:150ms]">.</span>
                                    <span class="inline-block animate-bounce [animation-delay:300ms]">.</span>
                                </span>
                            </span>
                        </x-ui.button>
                    </div>
                </div>

                <div class="mt-[8px] min-h-[17px] text-center text-[12px] font-medium text-black/40">
                    @if($draftState === 'saving')
                        Сохраняем черновик...
                    @elseif($draftState === 'dirty')
                        Есть несохранённые изменения
                    @elseif($draftState === 'saved' && $draftSavedAt)
                        Черновик сохранён в {{ $draftSavedAt }}
                    @elseif($draftState === 'error')
                        Не удалось сохранить черновик
                    @else
                        Черновик ещё не сохранялся
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div x-data="{ sheetOpen: @entangle('successSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5 text-center">
                <img
                    class="mt-[28px] h-[135px] w-full object-contain"
                    src="{{ asset('images/success.webp') }}"
                    alt="success"
                >

                <h1 class="mt-[28px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Контроль отправлен!
                </h1>

                <p class="pt-[18px] text-[15px] leading-[1.5] text-black/55">
                    {{ $successMessage }}
                </p>

                <div class="pt-[32px]">
                    <x-ui.button
                        variant="primary"
                        @click="sheetOpen = false"
                    >
                        Отлично
                    </x-ui.button>
                </div>
            </div>
        </x-ui.bottom-sheet>
    </div>
</div>

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('control-scroll', (event) => {
            const payload = Array.isArray(event) ? event[0] : event;
            let target = null;

            if (payload?.type === 'meta') {
                target = document.getElementById(`field-${payload.key}`);
            }

            if (payload?.type === 'question') {
                target = document.getElementById(`question-${payload.room}-${payload.q}`);
            }

            if (!target) {
                target = document.querySelector('[x-ref="scrollArea"]');
            }

            requestAnimationFrame(() => {
                target?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                });
            });
        });
    });
</script>