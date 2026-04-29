<?php

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public ?Control $control = null;

    public array $rooms = [];
    public array $answers = [];

    public ?int $openRoomIndex = 0;
    public bool $attemptedSubmit = false;

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
            'openRoomIndex',
            'attemptedSubmit',
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
            report($e);

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
            $this->answers = array_replace_recursive($this->answers, $draft->responses);
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
        $this->openRoomIndex = 0;
        $this->attemptedSubmit = false;

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

    public function getRequiredQuestionsTotalProperty(): int
    {
        $total = 0;

        foreach ($this->rooms as $room) {
            foreach (($room['items'] ?? []) as $question) {
                if (! $this->questionIsOptional($room, $question)) {
                    $total++;
                }
            }
        }

        return $total;
    }

    public function getRequiredQuestionsDoneProperty(): int
    {
        $done = 0;

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                if ($this->questionIsOptional($room, $question)) {
                    continue;
                }

                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

                if ($this->isQuestionFilled($question, $answer)) {
                    $done++;
                }
            }
        }

        return $done;
    }

    public function getMetaReadyProperty(): bool
    {
        return filled($this->cleaner_id)
            && filled($this->apartment_id)
            && filled($this->cleaning_date)
            && filled($this->inspection_date);
    }

    public function getFormProgressProperty(): int
    {
        $total = 4 + $this->requiredQuestionsTotal;
        $done = 0;

        foreach ([
            $this->cleaner_id,
            $this->apartment_id,
            $this->cleaning_date,
            $this->inspection_date,
        ] as $field) {
            if (filled($field)) {
                $done++;
            }
        }

        $done += $this->requiredQuestionsDone;

        return $total > 0 ? (int) round(($done / $total) * 100) : 0;
    }

    public function getFormReadyProperty(): bool
    {
        return $this->metaReady && $this->requiredQuestionsDone >= $this->requiredQuestionsTotal;
    }

    public function getFormButtonTextProperty(): string
    {
        return $this->formReady ? 'Отправить контроль' : 'Продолжить';
    }

    public function getRoomProgress(int $roomIndex): array
    {
        $room = $this->rooms[$roomIndex] ?? null;

        if (! $room) {
            return ['done' => 0, 'total' => 0, 'percent' => 0];
        }

        $total = 0;
        $done = 0;

        foreach (($room['items'] ?? []) as $questionIndex => $question) {
            if ($this->questionIsOptional($room, $question)) {
                continue;
            }

            $total++;

            $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

            if ($this->isQuestionFilled($question, $answer)) {
                $done++;
            }
        }

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 100,
        ];
    }

    public function getRoomStatus(int $roomIndex): string
    {
        $progress = $this->getRoomProgress($roomIndex);

        if ($this->attemptedSubmit && $progress['done'] < $progress['total']) {
            return 'error';
        }

        if ($progress['total'] > 0 && $progress['done'] >= $progress['total']) {
            return 'done';
        }

        if ($progress['done'] > 0) {
            return 'partial';
        }

        return 'empty';
    }

    public function toggleRoom(int $roomIndex): void
    {
        $this->openRoomIndex = $this->openRoomIndex === $roomIndex
            ? null
            : $roomIndex;
    }

    public function openRoom(int $roomIndex): void
    {
        $this->openRoomIndex = $roomIndex;
    }

    public function setAnswer(int $roomIndex, int $questionIndex, string $value): void
    {
        $this->answers[$roomIndex][$questionIndex]['selected'] = $value;

        $this->resetErrorBag("answers.$roomIndex.$questionIndex");
        $this->touchAutosave();
    }

    public function continueForm(): void
    {
        $this->resetErrorBag();

        $this->validateMeta();

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'Заполните основную информацию');
            $this->scrollToFirstError();
            return;
        }

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                if ($this->questionIsOptional($room, $question)) {
                    continue;
                }

                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

                if (! $this->isQuestionFilled($question, $answer)) {
                    $this->openRoomIndex = $roomIndex;

                    $this->dispatch(
                        'control-scroll',
                        type: 'question',
                        room: $roomIndex,
                        q: $questionIndex
                    );

                    return;
                }
            }
        }

        $this->submit();
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
            $this->openRoomIndex = (int) $m[1];

            $this->dispatch(
                'control-scroll',
                type: 'question',
                room: (int) $m[1],
                q: (int) $m[2]
            );

            return;
        }

        $this->dispatch('control-scroll', type: 'top');
    }

    public function submit(): void
    {
        $this->attemptedSubmit = true;
        $this->resetErrorBag();

        $this->validateMeta();
        $this->validateRooms();

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->dispatch('toast', type: 'error', message: 'Вы заполнили не все обязательные поля');
            $this->scrollToFirstError();
            return;
        }

        $score = ControlResponse::calculateScores($this->rooms, $this->answers);

        $response = ControlResponse::create([
            'control_id' => $this->control->id,
            'cleaner_id' => $this->cleaner_id,
            'supervisor_id' => Auth::id(),
            'apartment_id' => $this->apartment_id,

            'is_assigned' => $this->is_assigned,
            'previous_cleaner' => $this->previous_cleaner,
            'cleaning_date' => $this->cleaning_date,
            'inspection_date' => $this->inspection_date,

            'comment' => $this->comment,
            'responses' => $this->answers,
            'schema_snapshot' => $this->rooms,

            'total_points' => $score['total_points'],
            'max_points' => $score['max_points'],
            'score_percent' => $score['score_percent'],
            'has_critical_failure' => $score['has_critical_failure'],
            'result_zone' => $score['result_zone'],

            'status' => 'sent',
            'sent_at' => now(),
        ]);

        activity()
            ->causedBy(Auth::user())
            ->performedOn($response)
            ->event('control_completed')
            ->withProperties([
                'control_id' => $this->control->id,
                'control_name' => $this->control->name,
                'cleaner_id' => $this->cleaner_id,
                'apartment_id' => $this->apartment_id,
                'cleaning_date' => $this->cleaning_date,
                'inspection_date' => $this->inspection_date,
                'total_points' => $score['total_points'],
                'max_points' => $score['max_points'],
                'score_percent' => $score['score_percent'],
                'result_zone' => $score['result_zone'],
            ])
            ->log('Супервайзер отправил контроль качества');

        $this->clearDraft();
        $this->resetControlForm();

        $this->successMessage = "Контроль отправлен. Оценка: {$score['total_points']} / {$score['max_points']} ({$score['score_percent']}%).";
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
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2]" />
        </button>

        <span class="text-[18px] leading-none">
            Контроль качества
        </span>

        <div class="h-[36px] w-[36px]"></div>
    </div>
</x-slot:header>

<style>
    html {
        scroll-behavior: smooth;
    }

    [x-cloak] {
        display: none !important;
    }

    .no-scrollbar::-webkit-scrollbar {
        display: none;
    }

    .no-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
</style>

<div class="flex h-full min-h-0 flex-col bg-[#EEF3F8]">
    <form
        wire:submit.prevent="submit"
        x-data="{
            timer: null,
            hasUnsavedChanges: @entangle('hasUnsavedChanges').live,

            save() {
                clearTimeout(this.timer);

                this.timer = setTimeout(() => {
                    $wire.saveDraftAuto();
                }, 1200);
            },

            init() {
                window.addEventListener('beforeunload', (event) => {
                    if (!this.hasUnsavedChanges) {
                        return;
                    }

                    event.preventDefault();
                    event.returnValue = '';
                });
            }
        }"
        x-on:input="save()"
        x-on:change="save()"
        class="flex h-full min-h-0 flex-col"
    >
        <div id="control-scroll-area" class="flex-1 min-h-0 overflow-y-auto">
            <div class="min-h-full rounded-t-[34px] bg-white">
                <div class="p-[16px] pb-[120px]">

                    <div class="mb-[14px] rounded-[30px] bg-[#F6F8FB] p-[16px]">
                        <div class="flex items-start justify-between gap-[14px]">
                            <div class="min-w-0">
                                <h1 class="text-[24px] font-semibold tracking-[-0.04em] text-[#111827]">
                                    {{ $control?->name ?? 'Контроль качества' }}
                                </h1>

                                <p class="mt-[7px] text-[14px] leading-[1.45] text-[#64748B]">
                                    Заполните данные, пройдите комнаты и отправьте результат проверки.
                                </p>
                            </div>

                            <div class="shrink-0 rounded-[22px] bg-white px-[12px] py-[10px] text-center shadow-[0_10px_28px_rgba(33,50,89,0.06)]">
                                <div class="text-[20px] font-semibold leading-none text-[#213259]">
                                    {{ $this->formProgress }}%
                                </div>
                                <div class="mt-[4px] text-[11px] font-semibold text-[#94A3B8]">
                                    прогресс
                                </div>
                            </div>
                        </div>

                        <div class="mt-[14px] h-[8px] overflow-hidden rounded-full bg-[#E2E8F0]">
                            <div
                                class="h-full rounded-full bg-[#213259] transition-all duration-300"
                                style="width: {{ $this->formProgress }}%"
                            ></div>
                        </div>

                        <div class="mt-[10px] text-[12px] font-medium text-[#64748B]">
                            Обязательные вопросы: {{ $this->requiredQuestionsDone }} / {{ $this->requiredQuestionsTotal }}
                        </div>
                    </div>

                    <div class="mb-[20px]" id="meta-block">
                        <div class="mb-[12px] flex items-center justify-between">
                            <h2 class="text-[17px] font-semibold tracking-[-0.02em] text-[#111827]">
                                Основная информация
                            </h2>

                            @if($this->metaReady)
                                <div class="rounded-full bg-[#E7F8EF] px-[10px] py-[6px] text-[12px] font-semibold text-[#16834B]">
                                    заполнено
                                </div>
                            @else
                                <div class="rounded-full bg-[#EEF3F8] px-[10px] py-[6px] text-[12px] font-semibold text-[#64748B]">
                                    обязательно
                                </div>
                            @endif
                        </div>

                        <div class="rounded-[30px] border border-[#E6ECF2] bg-white p-[14px] shadow-[0_14px_40px_rgba(33,50,89,0.05)]">
                            <div class="space-y-[14px]">

                                <div id="field-cleaner_id">
                                    <div class="mb-[8px] px-[4px] text-[14px] font-semibold text-[#111827]">
                                        Кого проверили <span class="text-[#2D6494]">*</span>
                                    </div>

                                    <select
                                        wire:model.change="cleaner_id"
                                        class="h-[50px] w-full rounded-[20px] border-0 bg-[#F1F5F9] px-[16px] text-[15px] font-medium text-[#111827] focus:ring-2 focus:ring-[#213259]/15"
                                    >
                                        <option value="">Выберите человека</option>

                                        @foreach($this->people as $person)
                                            <option value="{{ $person->id }}">
                                                {{ $person->name }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('cleaner_id')
                                        <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div id="field-apartment_id">
                                    <div class="mb-[8px] px-[4px] text-[14px] font-semibold text-[#111827]">
                                        Квартира <span class="text-[#2D6494]">*</span>
                                    </div>

                                    <select
                                        wire:model.change="apartment_id"
                                        class="h-[50px] w-full rounded-[20px] border-0 bg-[#F1F5F9] px-[16px] text-[15px] font-medium text-[#111827] focus:ring-2 focus:ring-[#213259]/15"
                                    >
                                        <option value="">Выберите квартиру</option>

                                        @foreach($this->apartments as $apartment)
                                            <option value="{{ $apartment->id }}">
                                                {{ $apartment->name }}
                                            </option>
                                        @endforeach
                                    </select>

                                    @error('apartment_id')
                                        <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="grid grid-cols-2 gap-[10px]">
                                    <div id="field-cleaning_date">
                                        <div class="mb-[8px] px-[4px] text-[14px] font-semibold text-[#111827]">
                                            Дата уборки <span class="text-[#2D6494]">*</span>
                                        </div>

                                        <input
                                            type="date"
                                            wire:model.change="cleaning_date"
                                            class="h-[50px] w-full rounded-[20px] border-0 bg-[#F1F5F9] px-[12px] text-[14px] font-medium text-[#111827] focus:ring-2 focus:ring-[#213259]/15"
                                        >

                                        @error('cleaning_date')
                                            <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                                {{ $message }}
                                            </div>
                                        @enderror
                                    </div>

                                    <div id="field-inspection_date">
                                        <div class="mb-[8px] px-[4px] text-[14px] font-semibold text-[#111827]">
                                            Дата проверки <span class="text-[#2D6494]">*</span>
                                        </div>

                                        <input
                                            type="date"
                                            wire:model.change="inspection_date"
                                            class="h-[50px] w-full rounded-[20px] border-0 bg-[#F1F5F9] px-[12px] text-[14px] font-medium text-[#111827] focus:ring-2 focus:ring-[#213259]/15"
                                        >

                                        @error('inspection_date')
                                            <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                                {{ $message }}
                                            </div>
                                        @enderror
                                    </div>
                                </div>

                                <label class="flex items-center gap-[10px] rounded-[22px] bg-[#F8FAFC] p-[14px]">
                                    <input
                                        type="checkbox"
                                        wire:model.change="is_assigned"
                                        class="h-[18px] w-[18px] rounded border-[#CBD5E1] text-[#213259] focus:ring-[#213259]"
                                    >

                                    <span class="text-[14px] font-semibold text-[#111827]">
                                        Человек закреплён за этой квартирой
                                    </span>
                                </label>

                                <div id="field-previous_cleaner">
                                    <div class="mb-[8px] px-[4px] text-[14px] font-semibold text-[#111827]">
                                        Кто делал уборку до этого
                                    </div>

                                    <input
                                        type="text"
                                        wire:model.blur="previous_cleaner"
                                        placeholder="Введите имя"
                                        class="h-[50px] w-full rounded-[20px] border-0 bg-[#F1F5F9] px-[16px] text-[15px] font-medium text-[#111827] placeholder:text-[#94A3B8] focus:ring-2 focus:ring-[#213259]/15"
                                    >

                                    @error('previous_cleaner')
                                        <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    @if(count($rooms))
                        <div class="sticky top-[10px] z-40 mb-[16px]">
                            <div class="rounded-[26px] border border-[#E6ECF2] bg-white/95 p-[8px] shadow-[0_12px_34px_rgba(33,50,89,0.08)] backdrop-blur">
                                <div class="flex gap-[8px] overflow-x-auto no-scrollbar">
                                    @foreach($rooms as $roomIndex => $roomTab)
                                        @php
                                            $status = $this->getRoomStatus($roomIndex);
                                            $progress = $this->getRoomProgress($roomIndex);
                                            $isActive = $openRoomIndex === $roomIndex;

                                            $dotClass = match($status) {
                                                'done' => 'bg-[#2DBE72]',
                                                'partial' => 'bg-[#3B82F6]',
                                                'error' => 'bg-[#EF4444]',
                                                default => 'bg-[#CBD5E1]',
                                            };
                                        @endphp

                                        <button
                                            type="button"
                                            wire:click="openRoom({{ $roomIndex }})"
                                            onclick="setTimeout(() => {
                                                document.getElementById('room-{{ $roomIndex }}')?.scrollIntoView({
                                                    behavior: 'smooth',
                                                    block: 'start'
                                                })
                                            }, 120)"
                                            class="shrink-0 rounded-[20px] px-[13px] py-[10px] text-left transition
                                                {{ $isActive ? 'bg-[#213259] text-white shadow-[0_10px_24px_rgba(33,50,89,0.22)]' : 'bg-[#F1F5F9] text-[#213259]' }}"
                                        >
                                            <div class="flex items-center gap-[8px]">
                                                <span class="h-[7px] w-[7px] shrink-0 rounded-full {{ $dotClass }}"></span>

                                                <span class="max-w-[118px] truncate text-[13px] font-semibold">
                                                    {{ $roomTab['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                                </span>
                                            </div>

                                            <div class="mt-[4px] text-[11px] font-semibold {{ $isActive ? 'text-white/55' : 'text-[#94A3B8]' }}">
                                                {{ $progress['done'] }} / {{ $progress['total'] }}
                                            </div>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="space-y-[12px]">
                            @foreach($rooms as $roomIndex => $room)
                                @php
                                    $roomStatus = $this->getRoomStatus($roomIndex);
                                    $roomProgress = $this->getRoomProgress($roomIndex);
                                    $isOpen = $openRoomIndex === $roomIndex;

                                    $statusText = match($roomStatus) {
                                        'done' => 'готово',
                                        'partial' => 'в процессе',
                                        'error' => 'нужно заполнить',
                                        default => 'не начато',
                                    };

                                    $statusClass = match($roomStatus) {
                                        'done' => 'bg-[#E7F8EF] text-[#16834B]',
                                        'partial' => 'bg-[#EAF2FF] text-[#2563EB]',
                                        'error' => 'bg-[#FEECEC] text-[#DC2626]',
                                        default => 'bg-[#F1F5F9] text-[#64748B]',
                                    };
                                @endphp

                                <div
                                    id="room-{{ $roomIndex }}"
                                    class="overflow-hidden rounded-[30px] border border-[#E6ECF2] bg-white shadow-[0_14px_38px_rgba(33,50,89,0.05)]"
                                >
                                    <button
                                        type="button"
                                        wire:click="toggleRoom({{ $roomIndex }})"
                                        class="flex w-full items-center justify-between gap-[14px] px-[18px] py-[17px] text-left"
                                    >
                                        <div class="min-w-0">
                                            <div class="truncate text-[18px] font-semibold tracking-[-0.025em] text-[#111827]">
                                                {{ $room['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                            </div>

                                            <div class="mt-[5px] text-[13px] font-medium text-[#64748B]">
                                                {{ $roomProgress['done'] }} из {{ $roomProgress['total'] }} обязательных
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-[8px]">
                                            <span class="rounded-full px-[10px] py-[6px] text-[12px] font-semibold {{ $statusClass }}">
                                                {{ $statusText }}
                                            </span>

                                            <span class="flex h-[34px] w-[34px] items-center justify-center rounded-full bg-[#F1F5F9] text-[20px] font-medium text-[#64748B]">
                                                {{ $isOpen ? '−' : '+' }}
                                            </span>
                                        </div>
                                    </button>

                                    @if($isOpen)
                                        <div class="border-t border-[#E6ECF2] bg-[#F8FAFC] p-[12px]">
                                            @if(!empty($room['description']))
                                                <div class="mb-[12px] rounded-[22px] bg-white px-[14px] py-[12px] text-[13px] leading-[1.45] text-[#64748B]">
                                                    {{ $room['description'] }}
                                                </div>
                                            @endif

                                            <div class="space-y-[12px]">
                                                @foreach(($room['items'] ?? []) as $questionIndex => $question)
                                                    @php
                                                        $opts = $question['answer_options_scored'] ?? [];
                                                        $type = $question['answer_type'] ?? 'options';
                                                        $optional = $this->questionIsOptional($room, $question);
                                                        $answer = $answers[$roomIndex][$questionIndex] ?? [];
                                                        $selected = (string) ($answer['selected'] ?? '');
                                                    @endphp

                                                    <div
                                                        id="question-{{ $roomIndex }}-{{ $questionIndex }}"
                                                        @class([
                                                            'rounded-[26px] border bg-white p-[14px] shadow-[0_8px_24px_rgba(15,23,42,0.03)]',
                                                            'border-[#F04438]' => $errors->has("answers.$roomIndex.$questionIndex"),
                                                            'border-[#E6ECF2]' => ! $errors->has("answers.$roomIndex.$questionIndex"),
                                                        ])
                                                    >
                                                        <div class="mb-[12px] text-[15px] font-semibold leading-[1.35] tracking-[-0.01em] text-[#111827]">
                                                            {{ $question['question'] ?? 'Вопрос' }}

                                                            @if(!$optional)
                                                                <span class="text-[#2D6494]">*</span>
                                                            @else
                                                                <span class="ml-[4px] text-[12px] font-medium text-[#94A3B8]">
                                                                    необязательно
                                                                </span>
                                                            @endif
                                                        </div>

                                                        @error("answers.$roomIndex.$questionIndex")
                                                            <div class="mb-[10px] rounded-[18px] bg-[#FEE4E2] px-[12px] py-[9px] text-[13px] font-semibold text-[#B42318]">
                                                                {{ $message }}
                                                            </div>
                                                        @enderror

                                                        @if($type === 'options' || $type === 'both')
                                                            <div class="grid gap-[8px]">
                                                                @foreach($opts as $optIndex => $opt)
                                                                    @php
                                                                        $value = (string) ($opt['value'] ?? ('option_' . $optIndex));
                                                                        $active = $selected === $value;
                                                                    @endphp

                                                                    <button
                                                                        type="button"
                                                                        wire:click="setAnswer({{ $roomIndex }}, {{ $questionIndex }}, @js($value))"
                                                                        class="flex min-h-[50px] w-full items-center justify-between rounded-[20px] px-[15px] text-left text-[14px] font-semibold transition
                                                                            {{ $active ? 'bg-[#213259] text-white shadow-[0_10px_24px_rgba(33,50,89,0.18)]' : 'bg-[#F1F5F9] text-[#111827]' }}"
                                                                    >
                                                                        <span>{{ $opt['label'] ?? 'Вариант' }}</span>

                                                                        @if($active)
                                                                            <span class="rounded-full bg-white/15 px-[8px] py-[4px] text-[11px] text-white/75">
                                                                                выбрано
                                                                            </span>
                                                                        @endif
                                                                    </button>
                                                                @endforeach
                                                            </div>
                                                        @endif

                                                        @if($type === 'text' || $type === 'both')
                                                            <input
                                                                type="text"
                                                                wire:model.blur="answers.{{ $roomIndex }}.{{ $questionIndex }}.custom"
                                                                placeholder="{{ $type === 'both' ? 'Комментарий / другое' : 'Введите ответ' }}"
                                                                class="mt-[10px] h-[48px] w-full rounded-[20px] border-0 bg-[#F1F5F9] px-[15px] text-[14px] font-medium text-[#111827] placeholder:text-[#94A3B8] focus:ring-2 focus:ring-[#213259]/15"
                                                            >
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-[24px]" id="field-comment">
                        <div class="mb-[12px] flex items-center justify-between">
                            <h2 class="text-[17px] font-semibold tracking-[-0.02em] text-[#111827]">
                                Комментарий
                            </h2>

                            <div class="rounded-full bg-[#EEF3F8] px-[10px] py-[6px] text-[12px] font-semibold text-[#64748B]">
                                необязательно
                            </div>
                        </div>

                        <textarea
                            wire:model.blur="comment"
                            rows="4"
                            placeholder="Комментарий супервайзера"
                            class="w-full rounded-[26px] border border-[#E6ECF2] bg-white px-[18px] py-[15px] text-[15px] font-medium text-[#111827] placeholder:text-[#94A3B8] shadow-[0_14px_38px_rgba(33,50,89,0.05)] focus:ring-2 focus:ring-[#213259]/15"
                        ></textarea>

                        @error('comment')
                            <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="shrink-0 border-t border-[#E6ECF2] bg-white/95 px-[16px] pb-[16px] pt-[12px] backdrop-blur">
            <div class="grid grid-cols-3 gap-[10px]">
                <div class="col-span-1">
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        wire:click="saveDraft"
                        wire:loading.attr="disabled"
                        wire:target="saveDraft,saveDraftAuto,continueForm,submit"
                    >
                        <span wire:loading.remove wire:target="saveDraft">
                            Сохранить
                        </span>

                        <span wire:loading wire:target="saveDraft">
                            ...
                        </span>
                    </x-ui.button>
                </div>

                <div class="col-span-2">
                    <x-ui.button
                        type="button"
                        variant="primary"
                        :progress="$this->formProgress"
                        wire:click="continueForm"
                        wire:loading.attr="disabled"
                        wire:target="continueForm,submit"
                    >
                        <span wire:loading.remove wire:target="continueForm,submit">
                            {{ $this->formButtonText }}
                        </span>

                        <span wire:loading wire:target="continueForm,submit">
                            Сохраняем...
                        </span>
                    </x-ui.button>
                </div>
            </div>

            <div class="mt-[8px] min-h-[17px] text-center text-[12px] font-medium text-[#94A3B8]">
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
                    Контроль успешно отправлен
                </h1>

                <p class="pt-[18px] text-[15px] leading-[1.5] text-black/55">
                    {{ $successMessage }}
                </p>

                <div class="pt-[32px]">
                    <x-ui.button
                        variant="primary"
                        @click="sheetOpen = false"
                    >
                        Понятно
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
                target = document.getElementById('control-scroll-area');
            }

            requestAnimationFrame(() => {
                setTimeout(() => {
                    target?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                }, 120);
            });
        });
    });
</script>