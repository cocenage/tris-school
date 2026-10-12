<?php

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public ?Control $control = null;

    public array $rooms = [];
    public array $answers = [];
    public array $peopleOptions = [];
    public array $apartmentOptions = [];
    public array $photoUploads = [];
    public array $queuedPhotos = [];
    public int $photoLimit = 12;
    public int $answerTextLimit = 2000;


    public ?int $openRoomIndex = 0;
    public bool $attemptedSubmit = false;
    public bool $reviewSheetOpen = false;
    public bool $isSubmitting = false;

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
        abort_unless(Auth::check(), 403);
        $this->ensureCanConductControl();

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
        $this->loadSelectOptions();

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
                    'corrective' => $this->correctiveDefaults(),
                ];
            }
        }
    }

    protected function loadSelectOptions(): void
    {
        $this->peopleOptions = User::query()
            ->activeStaff()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'telegram_avatar_path'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'telegram_avatar_path' => $user->telegram_avatar_path,
                'avatar_url' => filled($user->telegram_avatar_path)
                    ? Storage::disk('public')->url($user->telegram_avatar_path)
                    : null,
            ])
            ->all();

        $this->apartmentOptions = Apartment::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'image'])
            ->map(fn (Apartment $apartment): array => [
                'id' => $apartment->id,
                'name' => $apartment->name,
                'image' => $apartment->image,
                'image_url' => filled($apartment->image)
                    ? Storage::disk('public')->url($apartment->image)
                    : null,
            ])
            ->all();
    }

    protected function controlImageUrl(mixed $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function updated(string $name): void
    {
        if (preg_match('/^answers\.(\d+)\.(\d+)\.selected$/', $name, $matches)) {
            $roomIndex = (int) $matches[1];
            $questionIndex = (int) $matches[2];
            $question = $this->rooms[$roomIndex]['items'][$questionIndex] ?? [];
            $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

            $this->resetErrorBag("answers.$roomIndex.$questionIndex");
            if (! $this->answerIsNegative($question, $answer)) {
                $this->answers[$roomIndex][$questionIndex]['corrective'] = $this->correctiveDefaults();
            }
        }

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
            'reviewSheetOpen',
            'isSubmitting',
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

protected function draftMedia(): array
{
    if (! $this->control || ! Auth::check()) {
        return [];
    }

    $draft = ControlResponseDraft::query()
        ->where('control_id', $this->control->id)
        ->where('supervisor_id', Auth::id())
        ->first();

    $media = [];
    foreach (($draft?->responses ?? []) as $roomIndex => $questions) {
        foreach (($questions ?? []) as $questionIndex => $answer) {
            if (! isset($this->rooms[$roomIndex]['items'][$questionIndex])) {
                continue;
            }

            foreach (($answer['media'] ?? []) as $item) {
                $path = is_array($item) ? (string) ($item['path'] ?? '') : '';
                $disk = $item['disk'] ?? null;
                $privatePrefix = 'control-drafts/'.$this->control->id.'/'.Auth::id().'/';
                $publicPrefix = 'controls/'.$this->control->id.'/';

                if (! str_contains($path, '..') && ! str_contains($path, '\\')
                    && (($disk === 'local' && str_starts_with($path, $privatePrefix))
                        || ($disk === 'public' && str_starts_with($path, $publicPrefix)))) {
                    $media[$roomIndex][$questionIndex][] = $item;
                }
            }
        }
    }

    return $media;
}

protected function getDraftPayload(?array $media = null): array
{
    $responses = $this->normalizedAnswers($this->answers);
    $media ??= $this->draftMedia();
    foreach ($responses as $roomIndex => &$questions) {
        foreach ($questions as $questionIndex => &$answer) {
            $answer['media'] = array_values($media[$roomIndex][$questionIndex] ?? []);
        }
        unset($answer);
    }
    unset($questions);

    return [
        'cleaner_id' => $this->cleaner_id,
        'apartment_id' => $this->apartment_id,
        'is_assigned' => $this->is_assigned,
        'previous_cleaner' => $this->previous_cleaner,
        'cleaning_date' => $this->cleaning_date,
        'inspection_date' => $this->inspection_date,
        'comment' => $this->comment,
        'responses' => $responses,
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
                if (! is_array($answer)) {
                    continue;
                }

                if (
                    filled(trim((string) ($answer['selected'] ?? ''))) ||
                    filled(trim((string) ($answer['custom'] ?? ''))) ||
                    ! empty($answer['media'])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function persistDraft(bool $silent = false): void
    {
        $this->ensureCanConductControl();

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

    public function saveDraft(): string
    {
        $this->persistDraft(false);

        return $this->draftState;
    }

    public function saveDraftAuto(): string
    {
        $this->persistDraft(true);

        return $this->draftState;
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
            $trustedMedia = $this->draftMedia();
            foreach ($this->answers as $roomIndex => &$questions) {
                foreach ($questions as $questionIndex => &$answer) {
                    $answer['media'] = array_values($trustedMedia[$roomIndex][$questionIndex] ?? []);
                }
                unset($answer);
            }
            unset($questions);
        }

        $this->draftSavedAt = optional($draft->updated_at)->format('H:i');
        $this->lastDraftHash = $this->getDraftHash();
        $this->hasUnsavedChanges = false;
        $this->draftState = 'saved';
    }

    protected function clearDraft(): void
    {
        if ($this->control && Auth::check()) {
            $media = $this->draftMedia();
            ControlResponseDraft::query()
                ->where('control_id', $this->control->id)
                ->where('supervisor_id', Auth::id())
                ->delete();

            foreach ($media as $questions) {
                foreach ($questions as $files) {
                    foreach ($files as $file) {
                        if (($file['disk'] ?? null) === 'local') {
                            Storage::disk('local')->delete($file['path']);
                        }
                    }
                }
            }
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
        $this->reviewSheetOpen = false;
        $this->isSubmitting = false;
        $this->successSheetOpen = false;
        $this->successMessage = null;

        $this->buildEmptyAnswers();
        $this->photoUploads = [];
        $this->queuedPhotos = [];

        $this->resetErrorBag();
        $this->resetValidation();

        $this->autoSaveEnabled = true;
    }

    public function resetControl(): void
    {
        $this->ensureCanConductControl();

        $this->clearDraft();
        $this->resetControlForm();

        $this->dispatch('control-scroll', type: 'top');
        $this->dispatch('toast', type: 'success', message: 'Контроль сброшен');
    }

    protected function questionIsOptional(array $room, array $question): bool
    {
        return (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false));
    }

    protected function isQuestionFilled(array $question, mixed $answer): bool
    {
        if (! is_array($answer)) {
            return false;
        }

        $selected = trim((string) ($answer['selected'] ?? ''));
        $custom = trim((string) ($answer['custom'] ?? ''));

        return $selected !== '' || $custom !== '';
    }

    protected function correctiveDefaults(): array
    {
        return [
            'repeats' => null,
            'action' => '',
            'recheck' => null,
        ];
    }

    protected function answerIsNegative(array $question, mixed $answer): bool
    {
        if (! is_array($answer)) {
            return false;
        }

        return ControlResponse::isNegativeAnswer(
            $question,
            trim((string) ($answer['selected'] ?? ''))
        );
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
        return $this->incompleteQuestionsCount > 0 ? 'Дальше' : 'Проверить контроль';
    }

    public function getIncompleteQuestionsProperty(): array
    {
        $items = [];

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                if ($this->questionIsOptional($room, $question)) {
                    continue;
                }

                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

                if (! $this->isQuestionFilled($question, $answer)) {
                    $items[] = [
                        'room' => $roomIndex,
                        'question' => $questionIndex,
                        'room_title' => $room['title'] ?? ('Комната ' . ($roomIndex + 1)),
                        'question_title' => $question['question'] ?? 'Вопрос',
                    ];
                }
            }
        }

        return $items;
    }

    public function getIncompleteQuestionsCountProperty(): int
    {
        return count($this->incompleteQuestions);
    }

    public function getQueuedPhotosTotalProperty(): int
    {
        return $this->countQueuedPhotos();
    }

    public function getReviewSummaryProperty(): array
    {
        $cleaner = $this->cleaner_id
            ? User::query()->whereKey($this->cleaner_id)->value('name')
            : null;

        $apartment = $this->apartment_id
            ? Apartment::query()->whereKey($this->apartment_id)->value('name')
            : null;

        return [
            'cleaner' => $cleaner ?: 'Не выбран',
            'apartment' => $apartment ?: 'Не выбрана',
            'cleaning_date' => $this->cleaning_date ?: 'Не указана',
            'inspection_date' => $this->inspection_date ?: 'Не указана',
            'required_done' => $this->requiredQuestionsDone,
            'required_total' => $this->requiredQuestionsTotal,
            'incomplete_count' => $this->incompleteQuestionsCount,
            'photos_total' => $this->queuedPhotosTotal,
            'progress' => $this->formProgress,
        ];
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

    public function toggleRoom(int $roomIndex): string
    {
        $this->ensureCanConductControl();

        $this->openRoomIndex = $this->openRoomIndex === $roomIndex
            ? null
            : $roomIndex;

        $this->persistDraft(true);

        return $this->draftState;
    }

    public function openRoom(int $roomIndex): void
    {
        $this->ensureCanConductControl();

        $this->openRoomIndex = $roomIndex;

        $this->dispatch('control-scroll', type: 'room', room: $roomIndex);
    }

    public function goToQuestion(int $roomIndex, int $questionIndex): void
    {
        $this->ensureCanConductControl();

        $this->reviewSheetOpen = false;
        $this->openRoomIndex = $roomIndex;

        $this->dispatch(
            'control-scroll',
            type: 'question',
            room: $roomIndex,
            q: $questionIndex
        );
    }

    public function setAnswer(int $roomIndex, int $questionIndex, string $value): void
    {
        $this->ensureCanConductControl();

        if (! is_array($this->answers[$roomIndex][$questionIndex] ?? null)) {
            $this->answers[$roomIndex][$questionIndex] = [
                'selected' => '',
                'custom' => '',
                'media' => [],
                'corrective' => $this->correctiveDefaults(),
            ];
        }

        $this->answers[$roomIndex][$questionIndex]['selected'] = $value;

        $question = $this->rooms[$roomIndex]['items'][$questionIndex] ?? [];

        if (! $this->answerIsNegative($question, $this->answers[$roomIndex][$questionIndex])) {
            $this->answers[$roomIndex][$questionIndex]['corrective'] = $this->correctiveDefaults();
        }

        $this->resetErrorBag("answers.$roomIndex.$questionIndex");
        $this->touchAutosave();
    }

    public function setCorrectiveBoolean(int $roomIndex, int $questionIndex, string $field, bool $value): void
    {
        $this->ensureCanConductControl();

        if (! in_array($field, ['repeats', 'recheck'], true)) {
            return;
        }

        $question = $this->rooms[$roomIndex]['items'][$questionIndex] ?? [];
        $answer = $this->answers[$roomIndex][$questionIndex] ?? [];

        if (! $this->answerIsNegative($question, $answer)) {
            $this->answers[$roomIndex][$questionIndex]['corrective'] = $this->correctiveDefaults();
            return;
        }

        if (! is_array($this->answers[$roomIndex][$questionIndex] ?? null)) {
            $this->answers[$roomIndex][$questionIndex] = [
                'selected' => '',
                'custom' => '',
                'media' => [],
                'corrective' => $this->correctiveDefaults(),
            ];
        }

        $corrective = is_array($this->answers[$roomIndex][$questionIndex]['corrective'] ?? null)
            ? $this->answers[$roomIndex][$questionIndex]['corrective']
            : $this->correctiveDefaults();
        $corrective[$field] = $value;
        $this->answers[$roomIndex][$questionIndex]['corrective'] = $corrective;
        $this->resetErrorBag("answers.$roomIndex.$questionIndex");
        $this->touchAutosave();
    }


    protected function countQueuedPhotos(): int
    {
        $total = 0;

        foreach ($this->answers as $questions) {
            foreach (($questions ?? []) as $answer) {
                $total += count($answer['media'] ?? []);
            }
        }

        return $total;
    }

    protected function validateQueuedPhotosLimits(): bool
    {
        $valid = true;
        $total = 0;

        foreach ($this->draftMedia() as $roomIndex => $questions) {
            foreach (($questions ?? []) as $questionIndex => $files) {
                $files = is_array($files) ? $files : [];
                $total += count($files);

                if (count($files) > $this->photoLimit) {
                    $this->addError("queuedPhotos.$roomIndex.$questionIndex", "Максимум {$this->photoLimit} файлов на один вопрос");
                    $valid = false;
                }

                foreach ($files as $file) {
                    if (! Storage::disk($file['disk'])->exists($file['path'])) {
                        $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Файл черновика недоступен. Загрузите его повторно.');
                        $valid = false;
                    }

                    if (! $this->isSupportedMime((string) ($file['mime'] ?? ''))) {
                        $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Можно загружать только фото и видео');
                        $valid = false;
                    }

                    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
                        $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Один файл не должен быть больше 10 МБ');
                        $valid = false;
                    }
                }
            }
        }

        return $valid;
    }

    protected function queueUploadedPhotos(string $name): void
    {
        $parts = explode('.', $name);

        if (count($parts) < 3) {
            return;
        }

        $roomIndex = (int) $parts[1];
        $questionIndex = (int) $parts[2];

        $incoming = $this->photoUploads[$roomIndex][$questionIndex] ?? [];

        if ($incoming instanceof TemporaryUploadedFile) {
            $incoming = [$incoming];
        }

        if (! is_array($incoming)) {
            $incoming = [];
        }

        if (! isset($this->rooms[$roomIndex]['items'][$questionIndex])) {
            return;
        }

        $media = $this->draftMedia();
        $existing = $media[$roomIndex][$questionIndex] ?? [];
        $storedPaths = [];

        foreach ($incoming as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            if (! $this->isSupportedMedia($file)) {
                $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Можно загружать только фото и видео');
                continue;
            }

            if ($file->getSize() > 10 * 1024 * 1024) {
                $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Один файл не должен быть больше 10 МБ');
                continue;
            }

            if (count($existing) >= $this->photoLimit) {
                $this->addError("queuedPhotos.$roomIndex.$questionIndex", "Максимум {$this->photoLimit} файлов на один вопрос");
                break;
            }

            try {
                $originalName = mb_substr($file->getClientOriginalName(), 0, 180);
                $mime = $file->getMimeType();
                $size = $file->getSize();
                $path = $file->store('control-drafts/'.$this->control->id.'/'.Auth::id(), 'local');
            } catch (\Throwable $e) {
                report($e);
                $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Не удалось сохранить файл. Повторите загрузку.');
                break;
            }
            if (! $path) {
                $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Не удалось сохранить файл. Повторите загрузку.');
                break;
            }

            $storedPaths[] = $path;
            $existing[] = [
                'disk' => 'local',
                'path' => $path,
                'original_name' => $originalName,
                'mime' => $mime,
                'size' => $size,
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        if ($storedPaths === []) {
            $this->photoUploads[$roomIndex][$questionIndex] = [];
            return;
        }

        $media[$roomIndex][$questionIndex] = $existing;
        try {
            ControlResponseDraft::updateOrCreate(
                ['control_id' => $this->control->id, 'supervisor_id' => Auth::id()],
                $this->getDraftPayload($media)
            );
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storedPaths);
            report($e);
            $this->addError("queuedPhotos.$roomIndex.$questionIndex", 'Не удалось сохранить файл. Повторите загрузку.');
            $this->draftState = 'error';
            $this->photoUploads[$roomIndex][$questionIndex] = [];
            return;
        }

        $this->answers[$roomIndex][$questionIndex]['media'] = $existing;
        $this->photoUploads[$roomIndex][$questionIndex] = [];
        $this->draftSavedAt = now()->format('H:i');
        $this->draftState = 'saved';
        $this->lastDraftHash = $this->getDraftHash();
        $this->hasUnsavedChanges = false;
    }

    protected function isSupportedMedia(TemporaryUploadedFile $file): bool
    {
        return $this->isSupportedMime((string) $file->getMimeType());
    }

    protected function isSupportedMime(string $mime): bool
    {
        return str_starts_with($mime, 'image/')
            || in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm'], true);
    }

    public function finishPhotoUpload(int $roomIndex, int $questionIndex): void
{
    $this->ensureCanConductControl();

    $this->queueUploadedPhotos("photoUploads.$roomIndex.$questionIndex");
}

    public function removeQueuedPhoto(int $roomIndex, int $questionIndex, int $photoIndex): void
    {
        $this->ensureCanConductControl();

        $media = $this->draftMedia();
        $removed = $media[$roomIndex][$questionIndex][$photoIndex] ?? null;
        if (! is_array($removed)) {
            return;
        }

        unset($media[$roomIndex][$questionIndex][$photoIndex]);
        $media[$roomIndex][$questionIndex] = array_values($media[$roomIndex][$questionIndex]);
        ControlResponseDraft::updateOrCreate(
            ['control_id' => $this->control->id, 'supervisor_id' => Auth::id()],
            $this->getDraftPayload($media)
        );

        $this->answers[$roomIndex][$questionIndex]['media'] = $media[$roomIndex][$questionIndex];
        Storage::disk($removed['disk'])->delete($removed['path']);

        $this->resetErrorBag("queuedPhotos.$roomIndex.$questionIndex");
        $this->lastDraftHash = $this->getDraftHash();
    }

    protected function storeQueuedPhotos(array &$storedPaths): array
    {
        $answers = $this->normalizedAnswers($this->answers);

        foreach ($answers as &$questions) {
            foreach ($questions as &$answer) {
                $answer['media'] = [];
            }
            unset($answer);
        }
        unset($questions);

        foreach ($this->draftMedia() as $roomIndex => $questions) {
            foreach (($questions ?? []) as $questionIndex => $files) {
                foreach (($files ?? []) as $file) {
                    if ($file['disk'] === 'local') {
                        $stream = Storage::disk('local')->readStream($file['path']);
                        if (! is_resource($stream)) {
                            throw new \RuntimeException('Draft media is unavailable.');
                        }

                        $path = 'controls/'.$this->control->id.'/'.now()->format('Y/m').'/'.basename($file['path']);
                        try {
                            if (! Storage::disk('local')->put($path, $stream)) {
                                throw new \RuntimeException('Control media could not be stored.');
                            }
                        } finally {
                            fclose($stream);
                        }

                        $storedPaths[] = $path;
                        $file['disk'] = 'local';
                        $file['path'] = $path;
                    }

                    $answers[$roomIndex][$questionIndex]['media'][] = $file;
                }
            }
        }

        return $answers;
    }

    protected function normalizedAnswers(array $source): array
    {
        $answers = [];

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $answer = $source[$roomIndex][$questionIndex] ?? [];
                $answer = is_array($answer) ? $answer : [];

                $answers[$roomIndex][$questionIndex] = [
                    'selected' => mb_substr(trim((string) ($answer['selected'] ?? '')), 0, 255),
                    'custom' => mb_substr(trim((string) ($answer['custom'] ?? '')), 0, $this->answerTextLimit),
                    'media' => is_array($answer['media'] ?? null) ? array_values($answer['media']) : [],
                    'corrective' => $this->normalizedCorrective($question, $answer),
                ];
            }
        }

        return $answers;
    }

    protected function normalizedCorrective(array $question, array $answer): array
    {
        if (! $this->answerIsNegative($question, $answer)) {
            return $this->correctiveDefaults();
        }

        $corrective = is_array($answer['corrective'] ?? null) ? $answer['corrective'] : [];

        return [
            'repeats' => array_key_exists('repeats', $corrective) && is_bool($corrective['repeats'])
                ? $corrective['repeats']
                : null,
            'action' => mb_substr(trim((string) ($corrective['action'] ?? '')), 0, 2000),
            'recheck' => array_key_exists('recheck', $corrective) && is_bool($corrective['recheck'])
                ? $corrective['recheck']
                : null,
        ];
    }

    public function getReviewProblemsProperty(): array
    {
        $normalized = $this->normalizedAnswers($this->answers);
        $analysis = ControlResponse::analyzeAnswers($this->rooms, $normalized);

        return array_map(function (array $error) use ($normalized): array {
            $answer = $normalized[$error['room_index']][$error['question_index']] ?? [];
            $error['corrective'] = is_array($answer['corrective'] ?? null)
                ? $answer['corrective']
                : $this->correctiveDefaults();

            return $error;
        }, $analysis['errors'] ?? []);
    }

    public function getReviewIssuesProperty(): array
    {
        $issues = [];

        foreach ($this->getErrorBag()->toArray() as $field => $messages) {
            if (preg_match('/^(?:answers|queuedPhotos)\.(\d+)\.(\d+)/', $field, $matches)) {
                $roomIndex = (int) $matches[1];
                $questionIndex = (int) $matches[2];
                $room = $this->rooms[$roomIndex] ?? [];
                $question = $room['items'][$questionIndex] ?? [];
                $key = $roomIndex.':'.$questionIndex;

                if (! isset($issues[$key])) {
                    $issues[$key] = [
                        'room' => $roomIndex,
                        'question' => $questionIndex,
                        'title' => $room['title'] ?? ('Комната '.($roomIndex + 1)),
                        'question_title' => $question['question'] ?? 'Вопрос',
                        'messages' => [],
                    ];
                }

                $issues[$key]['messages'] = array_values(array_unique([
                    ...$issues[$key]['messages'],
                    ...$messages,
                ]));

                continue;
            }

            if ($field === 'photoUploads') {
                $issues['photoUploads'] = [
                    'room' => null,
                    'question' => null,
                    'title' => 'Фото и видео',
                    'question_title' => null,
                    'messages' => array_values(array_unique($messages)),
                ];
            }
        }

        return array_values($issues);
    }

    public function continueForm(): string
    {
        if ($this->incompleteQuestions !== []) {
            $nextQuestion = $this->incompleteQuestions[0];

            $this->goToQuestion($nextQuestion['room'], $nextQuestion['question']);

            $this->persistDraft(true);

            return $this->draftState;
        }

        $this->openReview();

        $this->persistDraft(true);

        return $this->draftState;
    }

    protected function validateMeta(): void
    {
        if (! $this->cleaner_id || ! User::query()->whereKey($this->cleaner_id)->where('is_active', true)->whereIn('role', ['cleaner', 'supervisor'])->exists()) {
            $this->addError('cleaner_id', 'Выберите человека');
        }

        if (! $this->apartment_id || ! Apartment::query()->whereKey($this->apartment_id)->where('is_active', true)->exists()) {
            $this->addError('apartment_id', 'Выберите квартиру');
        }

        if (! $this->cleaning_date || ! strtotime($this->cleaning_date)) {
            $this->addError('cleaning_date', 'Укажите корректную дату уборки');
        }

        if (! $this->inspection_date || ! strtotime($this->inspection_date)) {
            $this->addError('inspection_date', 'Укажите корректную дату проверки');
        }

        if ($this->cleaning_date && $this->inspection_date && strtotime($this->inspection_date) < strtotime($this->cleaning_date)) {
            $this->addError('inspection_date', 'Дата проверки не может быть раньше даты уборки');
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
                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];
                $answer = is_array($answer) ? $answer : [];
                $optional = $this->questionIsOptional($room, $question);

                if (! $optional && ! $this->isQuestionFilled($question, $answer)) {
                    $this->addError("answers.$roomIndex.$questionIndex", 'Ответьте на вопрос');
                }

                if ($optional && ! $this->isQuestionFilled($question, $answer)) {
                    continue;
                }

                if (mb_strlen((string) ($answer['custom'] ?? '')) > $this->answerTextLimit) {
                    $this->addError("answers.$roomIndex.$questionIndex", "Текстовый ответ: максимум {$this->answerTextLimit} символов");
                }

                // TODO: Restore corrective-detail validation together with its hidden UI.
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

    public function openReview(): void
    {
        $this->ensureCanConductControl();

        $this->attemptedSubmit = true;
        $this->resetErrorBag();

        $this->validateMeta();

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->isSubmitting = false;
            $this->dispatch('toast', type: 'error', message: 'Заполните основную информацию');
            $this->scrollToFirstError();
            return;
        }

        $this->validateRooms();
        $this->validateQueuedPhotosLimits();

        $this->reviewSheetOpen = true;
    }

    public function confirmSubmit(): void
    {
        $this->ensureCanConductControl();

        $this->submit();
    }

    public function submit(): void
    {
        $this->ensureCanConductControl();

        if ($this->isSubmitting) {
            return;
        }

        $this->isSubmitting = true;
        $this->attemptedSubmit = true;
        $this->resetErrorBag();

        $this->validateMeta();
        $this->validateRooms();

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->isSubmitting = false;
            $this->dispatch('toast', type: 'error', message: 'Вы заполнили не все обязательные поля');
            $this->scrollToFirstError();
            return;
        }

        if (! $this->validateQueuedPhotosLimits()) {
            $this->isSubmitting = false;
            $this->dispatch('toast', type: 'error', message: 'Проверьте загруженные файлы');
            $this->scrollToFirstError();
            return;
        }

        $storedPaths = [];
        $submitLockKey = $this->submitLockKey();

        if (Cache::has($submitLockKey . ':completed')) {
            $this->isSubmitting = false;
            $this->dispatch('toast', type: 'error', message: 'Этот контроль уже отправлен.');

            return;
        }

        $submitLock = Cache::lock($submitLockKey, 30);

        if (! $submitLock->get()) {
            $this->isSubmitting = false;
            $this->dispatch('toast', type: 'error', message: 'Контроль уже отправляется. Подождите завершения.');

            return;
        }

        try {
            $responseData = DB::transaction(function () use (&$storedPaths) {
                $answersForSave = $this->storeQueuedPhotos($storedPaths);
                $score = ControlResponse::calculateScores($this->rooms, $answersForSave);

                $response = ControlResponse::create([
                    'control_id' => $this->control->id,
                    'cleaner_id' => $this->cleaner_id,
                    'supervisor_id' => Auth::id(),
                    'apartment_id' => $this->apartment_id,

                    'is_assigned' => $this->is_assigned,
                    'previous_cleaner' => trim($this->previous_cleaner),
                    'cleaning_date' => $this->cleaning_date,
                    'inspection_date' => $this->inspection_date,

                    'comment' => trim($this->comment),
                    'responses' => $answersForSave,
                    'schema_snapshot' => $this->rooms,

                    'total_points' => $score['total_points'],
                    'max_points' => $score['max_points'],
                    'score_percent' => $score['score_percent'],
                    'penalty_points' => $score['penalty_points'],
                    'errors_count' => $score['errors_count'],
                    'has_critical_failure' => $score['has_critical_failure'],
                    'result_zone' => $score['result_zone'],
                    'result_zone_reason' => $score['result_zone_reason'],

                    'status' => ControlResponse::STATUS_SENT,
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
                        'penalty_points' => $score['penalty_points'],
                        'errors_count' => $score['errors_count'],
                        'result_zone_reason' => $score['result_zone_reason'],
                    ])
                    ->log('Супервайзер отправил контроль качества');

                return [
                    'score' => $score,
                ];
            });

            Cache::put($submitLockKey . ':completed', true, now()->addMinutes(10));
        } catch (\Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            report($e);

            $this->isSubmitting = false;
            $this->dispatch('toast', type: 'error', message: 'Не удалось отправить контроль. Попробуйте ещё раз.');
            return;
        } finally {
            $submitLock->release();
        }

        $score = $responseData['score'];

        $this->clearDraft();
        $this->resetControlForm();

        $this->isSubmitting = false;
        $this->successMessage = "Контроль отправлен. Оценка: {$score['total_points']} / {$score['max_points']} ({$score['score_percent']}%).";
        $this->successSheetOpen = true;
    }

    protected function ensureCanConductControl(): void
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'supervisor'], true), 403);
    }

    protected function submitLockKey(): string
    {
        return implode(':', [
            'control-submit',
            Auth::id(),
            $this->control?->id ?? 'unknown',
            $this->cleaner_id ?? 'unknown',
            $this->apartment_id ?? 'unknown',
            $this->cleaning_date ?? 'unknown',
            $this->inspection_date ?? 'unknown',
        ]);
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
    <div
        class="relative grid h-[70px] w-full grid-cols-[40px_minmax(0,1fr)_40px] items-center gap-[8px] px-[15px]"
        x-data="{ menuOpen: false, resetOpen: false }"
        x-on:keydown.escape.window="menuOpen = false; resetOpen = false"
    >
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2]" />
        </button>

        <span class="truncate text-center text-[17px] font-semibold leading-none text-[#111827]">
            {{ $control?->name ?? 'Контроль качества' }}
        </span>

        <button
            type="button"
            class="flex h-[40px] w-[40px] items-center justify-center rounded-full text-[#213259] transition active:scale-[0.96]"
            aria-label="Открыть меню контроля"
            aria-haspopup="menu"
            :aria-expanded="menuOpen.toString()"
            x-on:click="menuOpen = !menuOpen"
        >
            <x-heroicon-o-ellipsis-horizontal class="h-[22px] w-[22px] stroke-[2.3]" />
        </button>

        <div
            x-show="menuOpen"
            x-cloak
            x-on:click.outside="menuOpen = false"
            class="absolute right-[15px] top-[60px] z-[150] w-[230px] overflow-hidden rounded-[22px] border border-[#E6ECF2] bg-white p-[6px] shadow-[0_18px_45px_rgba(33,50,89,0.18)]"
            role="menu"
        >
            <button
                type="button"
                class="flex min-h-[44px] w-full items-center gap-[10px] rounded-[16px] px-[12px] text-left text-[14px] font-semibold text-[#213259] hover:bg-[#F1F5F9]"
                role="menuitem"
                x-on:click="menuOpen = false; window.dispatchEvent(new CustomEvent('open-guide', { detail: { reset: true } }))"
            >
                <x-heroicon-o-question-mark-circle class="h-[19px] w-[19px]" />
                Открыть инструкцию
            </button>

            <button
                type="button"
                class="flex min-h-[44px] w-full items-center gap-[10px] rounded-[16px] px-[12px] text-left text-[14px] font-semibold text-[#B42318] hover:bg-[#FEF3F2]"
                role="menuitem"
                x-on:click="menuOpen = false; resetOpen = true"
            >
                <x-heroicon-o-arrow-path class="h-[19px] w-[19px]" />
                Сбросить контроль
            </button>
        </div>

        <div
            x-show="resetOpen"
            x-cloak
            class="fixed inset-0 z-[160] flex items-end justify-center bg-black/40 p-[15px] sm:items-center"
            role="dialog"
            aria-modal="true"
            aria-labelledby="control-reset-title"
        >
            <div class="w-full max-w-[420px] rounded-[26px] bg-white p-[18px] shadow-[0_20px_60px_rgba(0,0,0,0.22)]" x-on:click.stop>
                <h2 id="control-reset-title" class="text-[19px] font-semibold text-[#111827]">Сбросить контроль?</h2>
                <p class="mt-[8px] text-[14px] leading-[1.45] text-[#64748B]">
                    Все ответы и текущий черновик этого контроля будут очищены.
                </p>

                <div class="mt-[18px] grid grid-cols-2 gap-[8px]">
                    <button
                        type="button"
                        class="min-h-[44px] rounded-full border border-[#D9E3EE] px-[14px] text-[14px] font-semibold text-[#213259]"
                        x-on:click="resetOpen = false"
                    >
                        Отмена
                    </button>
                    <button
                        type="button"
                        class="min-h-[44px] rounded-full bg-[#B42318] px-[14px] text-[14px] font-semibold text-white"
                        x-on:click="resetOpen = false; window.dispatchEvent(new CustomEvent('control-reset-request'))"
                    >
                        Сбросить
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-slot:header>

<style>
    #control-scroll-area {
        --control-sticky-offset: 16px;
        scroll-padding-top: var(--control-sticky-offset);
        scroll-padding-bottom: 16px;
        scroll-behavior: smooth;
        overscroll-behavior-y: contain;
    }

    [data-control-anchor] {
        scroll-margin-top: var(--control-sticky-offset, 92px);
    }

    .control-scroll-highlight {
        animation: control-scroll-highlight 1.8s ease-out;
    }

    @keyframes control-scroll-highlight {
        0% { box-shadow: 0 0 0 3px rgba(28, 125, 79, 0.18); }
        100% { box-shadow: 0 0 0 0 rgba(28, 125, 79, 0); }
    }

    @media (prefers-reduced-motion: reduce) {
        #control-scroll-area { scroll-behavior: auto; }
        .control-scroll-highlight { animation: none; }
    }

    [x-cloak] {
        display: none !important;
    }

</style>

<div class="flex h-full min-h-0 flex-col bg-[#F7F8F7]">
    <form
        wire:submit.prevent="submit"
        x-data="{
            timer: null,
            pendingUploads: 0,
            mediaUploads: {},
            hasUnsavedChanges: @entangle('hasUnsavedChanges').live,
            clientDraftState: @js($draftState),
            saveRevision: 0,
            attemptedSaveRevision: 0,
            persistedSaveRevision: 0,
            saveInFlight: false,
            savePromise: null,
            manualSaveInFlight: false,
            controlActionInFlight: false,
            submissionInFlight: false,

            notifySaveError() {
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: 'Не удалось сохранить черновик. Повторите попытку.' } }));
            },

            scheduleAutosave(delay = 1500) {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.flushAutosave(), delay);
            },

            applySaveResult(result, revision) {
                const saved = result === 'saved' || result === 'idle';
                if (saved) this.persistedSaveRevision = Math.max(this.persistedSaveRevision, revision);
                this.clientDraftState = this.saveRevision === revision
                    ? (saved ? result : 'error')
                    : 'dirty';
                return saved;
            },

            applyActionSaveResult(result, revision) {
                this.attemptedSaveRevision = Math.max(this.attemptedSaveRevision, revision);
                const saved = this.applySaveResult(result, revision);
                if (this.saveRevision > this.attemptedSaveRevision) this.scheduleAutosave(0);
                return saved;
            },

            async waitForActiveSave() {
                clearTimeout(this.timer);
                if (this.saveInFlight) await this.savePromise;
                clearTimeout(this.timer);
            },

            async flushAutosave() {
                clearTimeout(this.timer);
                if (this.pendingUploads > 0) {
                    this.scheduleAutosave(500);
                    return false;
                }
                if (this.saveInFlight) return this.savePromise;
                if (this.saveRevision <= this.attemptedSaveRevision) {
                    return this.persistedSaveRevision >= this.saveRevision;
                }

                const revision = this.saveRevision;
                this.attemptedSaveRevision = revision;
                this.saveInFlight = true;
                this.clientDraftState = 'saving';
                this.savePromise = (async () => {
                    let saved = false;
                    try {
                        const result = await $wire.saveDraftAuto();
                        saved = this.applySaveResult(result, revision);
                    } catch (error) {
                        this.clientDraftState = this.saveRevision === revision ? 'error' : 'dirty';
                    } finally {
                        this.saveInFlight = false;
                        this.savePromise = null;
                        if (this.saveRevision > this.attemptedSaveRevision) this.scheduleAutosave(0);
                    }
                    return saved;
                })();

                return this.savePromise;
            },

            async flushPendingChanges() {
                clearTimeout(this.timer);
                if (this.pendingUploads > 0) return false;

                let attempts = 0;
                while (attempts++ < 20) {
                    if (this.saveInFlight) {
                        await this.savePromise;
                        continue;
                    }

                    if (this.saveRevision > this.attemptedSaveRevision || this.clientDraftState === 'error') {
                        if (this.clientDraftState === 'error') {
                            this.attemptedSaveRevision = Math.min(this.attemptedSaveRevision, this.saveRevision - 1);
                        }
                        if (! await this.flushAutosave()) return false;
                        continue;
                    }

                    return this.saveRevision === 0 || this.persistedSaveRevision >= this.saveRevision;
                }

                return false;
            },

            async saveDraftManually() {
                if (this.manualSaveInFlight) return;
                this.manualSaveInFlight = true;
                try {
                    if (this.pendingUploads > 0 || ! await this.flushPendingChanges()) {
                        this.notifySaveError();
                        return;
                    }

                    const revision = this.saveRevision;
                    this.clientDraftState = 'saving';
                    if (! this.applySaveResult(await $wire.saveDraft(), revision)) this.notifySaveError();
                } catch (error) {
                    this.clientDraftState = 'error';
                    this.notifySaveError();
                } finally {
                    this.manualSaveInFlight = false;
                }
            },

            async toggleRoomAfterSave(roomIndex) {
                if (this.controlActionInFlight) return;
                this.controlActionInFlight = true;
                try {
                    await this.waitForActiveSave();
                    const revision = this.saveRevision;
                    this.clientDraftState = 'saving';
                    const result = await $wire.toggleRoom(roomIndex);
                    if (! this.applyActionSaveResult(result, revision)) this.notifySaveError();
                } catch (error) {
                    this.attemptedSaveRevision = this.saveRevision;
                    this.clientDraftState = 'error';
                    this.notifySaveError();
                } finally {
                    this.controlActionInFlight = false;
                }
            },

            async continueAfterSave() {
                if (this.controlActionInFlight) return;
                this.controlActionInFlight = true;
                try {
                    await this.waitForActiveSave();
                    const revision = this.saveRevision;
                    this.clientDraftState = 'saving';
                    const result = await $wire.continueForm();
                    if (! this.applyActionSaveResult(result, revision)) this.notifySaveError();
                } catch (error) {
                    this.attemptedSaveRevision = this.saveRevision;
                    this.clientDraftState = 'error';
                    this.notifySaveError();
                } finally {
                    this.controlActionInFlight = false;
                }
            },

            async confirmSubmitAfterSave() {
                if (this.controlActionInFlight) return;
                this.controlActionInFlight = true;
                this.submissionInFlight = true;
                try {
                    await this.waitForActiveSave();
                    const revision = this.saveRevision;
                    await $wire.confirmSubmit();
                    if ($wire.successSheetOpen) {
                        this.attemptedSaveRevision = Math.max(this.attemptedSaveRevision, revision);
                        this.persistedSaveRevision = Math.max(this.persistedSaveRevision, revision);
                        this.clientDraftState = 'submitted';
                    } else if (this.saveRevision > this.persistedSaveRevision) {
                        this.submissionInFlight = false;
                        this.clientDraftState = 'dirty';
                        this.scheduleAutosave(0);
                    } else {
                        this.submissionInFlight = false;
                    }
                } catch (error) {
                    this.attemptedSaveRevision = this.saveRevision;
                    this.clientDraftState = 'error';
                    this.submissionInFlight = false;
                    this.notifySaveError();
                } finally {
                    this.controlActionInFlight = false;
                }
            },

            resetFormFromMenu() {
                if (this.pendingUploads > 0) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: 'Дождитесь завершения загрузки файлов' } }));
                    return;
                }

                this.flushPendingChanges().then(async saved => {
                    if (!saved) {
                        this.notifySaveError();
                        return;
                    }
                    clearTimeout(this.timer);
                    await $wire.resetControl();
                    this.mediaUploads = {};
                    this.pendingUploads = 0;
                    this.saveRevision = 0;
                    this.attemptedSaveRevision = 0;
                    this.persistedSaveRevision = 0;
                    this.clientDraftState = 'idle';
                    this.submissionInFlight = false;
                    window.dispatchEvent(new CustomEvent('control-form-reset'));
                }).catch(() => {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: 'Не удалось сбросить контроль. Попробуйте ещё раз.' } }));
                });
            },

            startMediaUpload(key, input, room, question, files) {
                const selectedFiles = Array.from(files || []);
                if (!selectedFiles.length || this.mediaUploads[key]?.state === 'uploading') return;

                this.mediaUploads[key] = { state: 'uploading', progress: 0, files: selectedFiles };
                this.pendingUploads++;

                window.controlUploadCompressedPhotos(
                    input,
                    $wire,
                    `photoUploads.${room}.${question}`,
                    room,
                    question,
                    selectedFiles,
                    progress => this.mediaUploads[key].progress = progress
                ).then(() => {
                    this.mediaUploads[key] = { state: 'idle', progress: 0, files: [] };
                }).catch(() => {
                    this.mediaUploads[key].state = 'error';
                }).finally(() => {
                    this.pendingUploads = Math.max(0, this.pendingUploads - 1);
                });
            },

            save() {
                this.saveRevision++;
                this.clientDraftState = 'dirty';
                this.scheduleAutosave();
            },

            init() {
                window.addEventListener('beforeunload', (event) => {
                    const unsaved = this.saveRevision > this.persistedSaveRevision
                        || this.saveInFlight
                        || (this.submissionInFlight && this.clientDraftState !== 'submitted')
                        || this.clientDraftState === 'error'
                        || this.hasUnsavedChanges;
                    if (!unsaved && this.pendingUploads === 0) {
                        return;
                    }

                    event.preventDefault();
                    event.returnValue = '';
                });
            }
        }"
        x-on:control-reset-request.window="resetFormFromMenu()"
        x-on:input="save()"
        x-on:change="save()"
        class="flex h-full min-h-0 flex-col"
    >
        <div id="control-scroll-area" data-control-scroll-area class="flex-1 min-h-0 overflow-y-auto">
            <div class="min-h-full rounded-t-[34px] bg-white">
                <div class="pb-[16px]">

                    <div class="mb-[16px] bg-white px-[16px] pb-[4px] pt-[8px]">
                        <div class="mb-[7px] flex items-center justify-between gap-[12px] text-[12px] font-medium text-[#64748B]">
                            <span>Обязательные ответы</span>
                            <span class="shrink-0 font-semibold text-[#344054]">{{ $this->requiredQuestionsDone }} из {{ $this->requiredQuestionsTotal }}</span>
                        </div>

                        <div class="h-[6px] overflow-hidden rounded-full bg-[#EEF1EF]">
                            <div
                                class="h-full rounded-full bg-[#25845A] transition-all duration-300"
                                style="width: {{ $this->formProgress }}%"
                            ></div>
                        </div>
                    </div>

                    <div class="control-section mx-[16px] mb-[20px]" id="meta-block" data-control-anchor="meta">


                        <div class="bg-white p-0">
                            <div class="space-y-[14px]">

                                <div id="field-cleaner_id">
                                    <div class="mb-[8px] px-[4px] text-[14px] font-semibold text-[#111827]">
                                        Кого проверили <span class="text-[#2D6494]">*</span>
                                    </div>

                                    @php
    $selectedPerson = collect($peopleOptions)
        ->firstWhere('id', (int) $cleaner_id);
@endphp

                                    <div
                                        class="relative"
                                        x-data="{
                                            open: false,
                                            query: '',
                                            selected: @entangle('cleaner_id').live,
                                            options: @js($peopleOptions),
                                            get filtered() {
                                                const value = this.query.trim().toLowerCase();
                                                return value
                                                    ? this.options.filter(person => person.name.toLowerCase().includes(value))
                                                    : this.options;
                                            }
                                        }"
                                        x-on:click.outside="open = false"
                                        x-on:keydown.escape.window="open = false"
                                        x-on:control-form-reset.window="open = false; query = ''"
                                    >
                                        <button
                                            type="button"
                                            class="flex min-h-[54px] w-full items-center gap-[11px] rounded-[18px] bg-[#F1F5F9] px-[12px] text-left text-[15px] font-medium text-[#111827] focus:ring-2 focus:ring-[#213259]/15"
                                            x-on:click="open = true; $nextTick(() => $refs.personSearch.focus())"
                                            :aria-expanded="open.toString()"
                                            aria-haspopup="listbox"
                                        >
                                            @if($selectedPerson)
                                                @if($selectedPerson['avatar_url'])
                                                    <img src="{{ $selectedPerson['avatar_url'] }}" alt="" class="h-[34px] w-[34px] shrink-0 rounded-full object-cover">
                                                @else
                                                    <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-[#D9E3EE] text-[12px] font-semibold text-[#213259]">
                                                        {{ collect(explode(' ', trim($selectedPerson['name'])))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}
                                                    </span>
                                                @endif
                                                <span class="min-w-0 flex-1 truncate">{{ $selectedPerson['name'] }}</span>
                                            @else
                                                <span class="min-w-0 flex-1 truncate text-[#64748B]">Выберите человека</span>
                                            @endif
                                            <x-heroicon-o-chevron-down class="h-[18px] w-[18px] shrink-0 text-[#64748B]" />
                                        </button>

                                        <div x-show="open" x-cloak class="fixed inset-0 z-[140] bg-black/20 sm:hidden" x-on:click="open = false"></div>
                                        <div
                                            x-show="open"
                                            x-cloak
                                            class="fixed inset-x-0 bottom-0 z-[150] max-h-[76dvh] overflow-hidden rounded-t-[26px] bg-white p-[12px] shadow-[0_-18px_50px_rgba(33,50,89,0.18)] sm:absolute sm:inset-x-0 sm:bottom-auto sm:top-[calc(100%+8px)] sm:max-h-[360px] sm:rounded-[22px] sm:border sm:border-[#E6ECF2] sm:p-[8px] sm:shadow-[0_18px_45px_rgba(33,50,89,0.18)]"
                                            role="listbox"
                                        >
                                            <input
                                                x-ref="personSearch"
                                                x-model="query"
                                                type="search"
                                                placeholder="Поиск по имени"
                                                class="mb-[8px] h-[46px] w-full rounded-[16px] bg-[#F1F5F9] px-[13px] text-[15px] text-[#111827] outline-none focus:ring-2 focus:ring-[#213259]/15"
                                            >

                                            <div class="max-h-[58dvh] space-y-[4px] overflow-y-auto sm:max-h-[290px]">
                                                <template x-for="person in filtered" :key="person.id">
                                                    <button
                                                        type="button"
                                                        class="flex min-h-[52px] w-full items-center gap-[11px] rounded-[16px] px-[9px] text-left hover:bg-[#F1F5F9]"
                                                        role="option"
                                                        x-on:click="selected = person.id; open = false; query = ''"
                                                    >
                                                        <template x-if="person.avatar_url">
                                                            <img :src="person.avatar_url" :alt="person.name" class="h-[34px] w-[34px] shrink-0 rounded-full object-cover">
                                                        </template>
                                                        <template x-if="!person.avatar_url">
                                                            <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-[#D9E3EE] text-[12px] font-semibold text-[#213259]" x-text="person.name.trim().charAt(0).toUpperCase()"></span>
                                                        </template>
                                                        <span class="min-w-0 flex-1 truncate text-[14px] font-semibold text-[#111827]" x-text="person.name"></span>
                                                    </button>
                                                </template>

                                                <div x-show="filtered.length === 0" class="px-[10px] py-[18px] text-center text-[13px] font-medium text-[#64748B]">
                                                    Человек не найден
                                                </div>
                                            </div>
                                        </div>
                                    </div>

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

                                   @php
    $selectedApartment = collect($apartmentOptions)
        ->firstWhere('id', (int) $apartment_id);
@endphp

                                    <div
                                        class="relative"
                                        x-data="{
                                            open: false,
                                            query: '',
                                            selected: @entangle('apartment_id').live,
                                            options: @js($apartmentOptions),
                                            get filtered() {
                                                const value = this.query.trim().toLowerCase();
                                                return value
                                                    ? this.options.filter(apartment => apartment.name.toLowerCase().includes(value))
                                                    : this.options;
                                            }
                                        }"
                                        x-on:click.outside="open = false"
                                        x-on:keydown.escape.window="open = false"
                                        x-on:control-form-reset.window="open = false; query = ''"
                                    >
                                        <button
                                            type="button"
                                            class="flex min-h-[54px] w-full items-center gap-[11px] rounded-[18px] bg-[#F1F5F9] px-[12px] text-left text-[15px] font-medium text-[#111827] focus:ring-2 focus:ring-[#213259]/15"
                                            x-on:click="open = true; $nextTick(() => $refs.apartmentSearch.focus())"
                                            :aria-expanded="open.toString()"
                                            aria-haspopup="listbox"
                                        >
                                            @if($selectedApartment)
                                                @if($selectedApartment['image_url'])
                                                    <img src="{{ $selectedApartment['image_url'] }}" alt="" class="h-[34px] w-[34px] shrink-0 rounded-[10px] object-cover">
                                                @else
                                                    <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] bg-[#D9E3EE] text-[#213259]">
                                                        <x-heroicon-o-home-modern class="h-[18px] w-[18px]" />
                                                    </span>
                                                @endif
                                                <span class="min-w-0 flex-1 truncate">{{ $selectedApartment['name'] }}</span>
                                            @else
                                                <span class="min-w-0 flex-1 truncate text-[#64748B]">Выберите квартиру</span>
                                            @endif
                                            <x-heroicon-o-chevron-down class="h-[18px] w-[18px] shrink-0 text-[#64748B]" />
                                        </button>

                                        <div x-show="open" x-cloak class="fixed inset-0 z-[140] bg-black/20 sm:hidden" x-on:click="open = false"></div>
                                        <div
                                            x-show="open"
                                            x-cloak
                                            class="fixed inset-x-0 bottom-0 z-[150] max-h-[76dvh] overflow-hidden rounded-t-[26px] bg-white p-[12px] shadow-[0_-18px_50px_rgba(33,50,89,0.18)] sm:absolute sm:inset-x-0 sm:bottom-auto sm:top-[calc(100%+8px)] sm:max-h-[360px] sm:rounded-[22px] sm:border sm:border-[#E6ECF2] sm:p-[8px] sm:shadow-[0_18px_45px_rgba(33,50,89,0.18)]"
                                            role="listbox"
                                        >
                                            <input
                                                x-ref="apartmentSearch"
                                                x-model="query"
                                                type="search"
                                                placeholder="Поиск по квартире"
                                                class="mb-[8px] h-[46px] w-full rounded-[16px] bg-[#F1F5F9] px-[13px] text-[15px] text-[#111827] outline-none focus:ring-2 focus:ring-[#213259]/15"
                                            >

                                            <div class="max-h-[58dvh] space-y-[4px] overflow-y-auto sm:max-h-[290px]">
                                                <template x-for="apartment in filtered" :key="apartment.id">
                                                    <button
                                                        type="button"
                                                        class="flex min-h-[52px] w-full items-center gap-[11px] rounded-[16px] px-[9px] text-left hover:bg-[#F1F5F9]"
                                                        role="option"
                                                        x-on:click="selected = apartment.id; open = false; query = ''"
                                                    >
                                                        <template x-if="apartment.image_url">
                                                            <img :src="apartment.image_url" :alt="apartment.name" class="h-[34px] w-[34px] shrink-0 rounded-[10px] object-cover">
                                                        </template>
                                                        <template x-if="!apartment.image_url">
                                                            <span class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] bg-[#D9E3EE] text-[#213259]"><x-heroicon-o-home-modern class="h-[18px] w-[18px]" /></span>
                                                        </template>
                                                        <span class="min-w-0 flex-1 truncate text-[14px] font-semibold text-[#111827]" x-text="apartment.name"></span>
                                                    </button>
                                                </template>

                                                <div x-show="filtered.length === 0" class="px-[10px] py-[18px] text-center text-[13px] font-medium text-[#64748B]">
                                                    Квартира не найдена
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    @error('apartment_id')
                                        <div class="mt-[8px] px-[4px] text-[13px] font-medium text-[#D92D20]">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="grid grid-cols-1 gap-[10px] min-[390px]:grid-cols-2">
                                    <div id="field-cleaning_date" class="min-w-0">
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

                                    <div id="field-inspection_date" class="min-w-0">
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
                        <div class="space-y-[12px] px-[16px]">
                            @foreach($rooms as $roomIndex => $room)
                                @php
                                    $roomStatus = $this->getRoomStatus($roomIndex);
                                    $roomProgress = $this->getRoomProgress($roomIndex);
                                    $isOpen = $openRoomIndex === $roomIndex;

                                    $statusClass = match($roomStatus) {
                                        'done' => 'bg-[#E7F8EF] text-[#16834B]',
                                        'partial' => 'bg-[#F0F3F1] text-[#536159]',
                                        'error' => 'bg-[#FFF0ED] text-[#A33B32]',
                                        default => 'bg-[#F3F5F3] text-[#68736C]',
                                    };
                                @endphp

                                <div
                                    id="room-{{ $roomIndex }}"
                                    data-control-anchor="{{ $roomIndex }}"
                                    data-control-room-section="{{ $roomIndex }}"
                                    wire:key="control-room-{{ $roomIndex }}"
                                    @class([
                                        'control-section overflow-hidden rounded-[22px] bg-white',
                                        'ring-1 ring-inset ring-[#D9EDE0]' => $roomStatus === 'done',
                                        'ring-1 ring-inset ring-[#F1D4D1]' => $roomStatus === 'error',
                                    ])
                                >
                                    <button
                                        type="button"
                                        x-on:click="toggleRoomAfterSave({{ $roomIndex }})"
                                        aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                        @class([
                                            'flex min-h-[58px] w-full items-center justify-between gap-[12px] px-[14px] py-[12px] text-left transition active:bg-[#F6F8F6]',
                                            'bg-[#F6FBF7]' => $roomStatus === 'done',
                                            'bg-[#FFF9F8]' => $roomStatus === 'error',
                                            'bg-white' => ! in_array($roomStatus, ['done', 'error'], true),
                                        ])
                                    >
                                        <div class="min-w-0">
                                            <div class="truncate text-[17px] font-semibold tracking-[-0.02em] text-[#111827]">
                                                {{ $room['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                            </div>

                                            <div class="mt-[3px] text-[12px] font-medium text-[#64748B]">
                                                {{ $roomProgress['done'] }} из {{ $roomProgress['total'] }} вопросов
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-[8px]">
                                            <span class="rounded-full px-[9px] py-[5px] text-[11px] font-semibold {{ $statusClass }}">
                                                {{ $roomStatus === 'done' ? 'Готово' : ($roomStatus === 'error' ? 'Проверить' : ($roomStatus === 'partial' ? 'В работе' : 'Не начато')) }}
                                            </span>

                                            <span aria-hidden="true" class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full bg-[#F1F4F2] text-[18px] font-medium leading-none text-[#68736C]">
                                                {{ $isOpen ? '−' : '+' }}
                                            </span>
                                        </div>
                                    </button>

                                    @if($isOpen)
                                        <div class="px-[12px] pb-[14px] pt-[2px]">
                                            @if(!empty($room['description']))
                                                <div class="mb-[12px] border-l-2 border-[#D9E3EE] px-[12px] py-[4px] text-[13px] leading-[1.45] text-[#64748B]">
                                                    {{ $room['description'] }}
                                                </div>
                                            @endif

                                            @if($roomImage = $this->controlImageUrl($room['room_image'] ?? null))
                                                <img
                                                    src="{{ $roomImage }}"
                                                    alt="{{ $room['title'] ?? 'Комната' }}"
                                                    class="mb-[12px] h-[120px] w-full rounded-[18px] object-cover"
                                                >
                                            @endif

                                            <div class="space-y-[12px]">
                                                @foreach(($room['items'] ?? []) as $questionIndex => $question)
                                                    @php
                                                        $opts = $question['answer_options_scored'] ?? [];
                                                        $type = $question['answer_type'] ?? 'options';
                                                        $optional = $this->questionIsOptional($room, $question);
                                                        $answer = $answers[$roomIndex][$questionIndex] ?? [];
                                                        $selected = (string) ($answer['selected'] ?? '');
                                                        $isFilled = $this->isQuestionFilled($question, $answer);
                                                        $questionPhotos = $answer['media'] ?? [];
                                                    @endphp

                                                    <div
                                                        id="question-{{ $roomIndex }}-{{ $questionIndex }}"
                                                        data-control-anchor="question-{{ $roomIndex }}-{{ $questionIndex }}"
                                                        wire:key="control-question-{{ $roomIndex }}-{{ $questionIndex }}"
                                                        @class([
                                                            'control-section rounded-[18px] bg-[#F8FAF9] p-[12px]',
                                                            'ring-1 ring-inset ring-[#E9A6A0] bg-[#FFF9F8]' => $errors->has("answers.$roomIndex.$questionIndex"),
                                                        ])
                                                    >
                                                        <div class="mb-[11px] flex items-start gap-[6px]">
                                                            <div class="min-w-0 flex-1 whitespace-normal break-words text-[16px] font-semibold leading-[1.4] tracking-[-0.01em] text-[#17201A]">
                                                                <span class="mb-[3px] block truncate text-[11px] font-medium tracking-normal text-[#66736B]">{{ $room['title'] ?? ('Комната '.($roomIndex + 1)) }} · вопрос {{ $questionIndex + 1 }} из {{ count($room['items'] ?? []) }}</span>
                                                                {{ $question['question'] ?? 'Вопрос' }}

                                                                @if(!$optional)
                                                                    <span class="text-[#2D6494]">*</span>
                                                                @else
                                                                    <span class="ml-[5px] text-[11px] font-medium text-[#8A948E]">
                                                                        · необязательно
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </div>

                                                        @if($questionImage = $this->controlImageUrl($question['question_image'] ?? null))
                                                            <img
                                                                src="{{ $questionImage }}"
                                                                alt=""
                                                                class="mb-[12px] max-h-[180px] w-full rounded-[16px] object-contain bg-[#F8FAFC]"
                                                            >
                                                        @endif

                                                        @error("answers.$roomIndex.$questionIndex")
                                                            <div class="mb-[10px] rounded-[18px] bg-[#FEE4E2] px-[12px] py-[9px] text-[13px] font-semibold text-[#B42318]">
                                                                {{ $message }}
                                                            </div>
                                                        @enderror

                                                        @if($type === 'options' || $type === 'both')
                                                            <div class="grid gap-[8px]">
                                                       @foreach($opts as $optIndex => $opt)
    @php
        $value = (string) ($opt['value'] ?? $opt['label'] ?? ('option_' . $optIndex));
        $legacyValue = 'option_' . $optIndex;
    @endphp

    <div
        x-data="{
            selected: @entangle('answers.' . $roomIndex . '.' . $questionIndex . '.selected'),
            value: @js($value),
            legacyValue: @js($legacyValue),

            get active() {
                return this.selected === this.value || this.selected === this.legacyValue;
            },

            choose() {
                if (this.submissionInFlight) return;
                this.selected = this.value;
            }
        }"
    >
        <button
            type="button"
            @click="choose(); save()"
            :disabled="submissionInFlight"
            :aria-pressed="active.toString()"
            :class="active
                ? 'bg-[#EAF6EE] text-[#176B43] ring-1 ring-inset ring-[#B8DEC5]'
                : 'bg-[#F0F3F1] text-[#34423A]'"
            class="flex min-h-[56px] w-full items-center gap-[12px] rounded-[16px] px-[14px] py-[12px] text-left text-[15px] font-semibold transition active:scale-[0.995] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#25845A]/30"
        >
            <span class="flex h-[20px] w-[20px] shrink-0 items-center justify-center rounded-full bg-white/90 ring-1 ring-inset ring-[#C8D1CB]">
                <span x-show="active" x-cloak class="h-[10px] w-[10px] rounded-full bg-[#25845A]"></span>
            </span>
            <span class="min-w-0 whitespace-normal break-words leading-[1.35]">{{ $opt['label'] ?? 'Вариант' }}</span>
        </button>
    </div>
@endforeach
                                                            </div>
                                                        @endif

                                                        @if($type === 'text' || $type === 'both')
                                                            <textarea
                                                                wire:model.blur="answers.{{ $roomIndex }}.{{ $questionIndex }}.custom"
                                                                rows="3"
                                                                maxlength="{{ $answerTextLimit }}"
                                                                placeholder="{{ $question['custom_answer_label'] ?? 'Текстовый ответ / комментарий' }}"
                                                                class="mt-[10px] min-h-[88px] w-full resize-y rounded-[16px] border-0 bg-white px-[14px] py-[12px] text-[15px] leading-[1.4] text-[#17201A] placeholder:text-[#8A948E] focus:ring-2 focus:ring-[#25845A]/20"
                                                            ></textarea>
                                                        @endif

                                                        {{-- TODO: Keep corrective details available for a future UI; hidden in the current Classic form. --}}
                                                        @if(false && $this->answerIsNegative($question, $answer))
                                                            @php
                                                                $corrective = is_array($answer['corrective'] ?? null) ? $answer['corrective'] : [];
                                                            @endphp
                                                            <div
                                                                class="mt-[10px] rounded-[15px] bg-[#F8F6EE] p-[10px]"
                                                                x-data="{ correctiveOpen: @js($errors->has('answers.'.$roomIndex.'.'.$questionIndex)) }"
                                                                x-on:control-scroll.window="if ($event.detail.type === 'question' && $event.detail.room === {{ $roomIndex }} && $event.detail.q === {{ $questionIndex }}) correctiveOpen = true"
                                                            >
                                                                <button
                                                                    type="button"
                                                                    class="flex min-h-[42px] w-full items-center justify-between gap-[10px] rounded-[11px] px-[2px] text-left"
                                                                    x-on:click="correctiveOpen = ! correctiveOpen"
                                                                    :aria-expanded="correctiveOpen.toString()"
                                                                >
                                                                    <span class="min-w-0">
                                                                        <span class="block text-[13px] font-semibold text-[#604D1B]">Уточнить исправление</span>
                                                                        <span class="mt-[2px] block text-[11px] text-[#7C6A39]">Обязательно для ответа «Нет»</span>
                                                                    </span>
                                                                    <span aria-hidden="true" class="shrink-0 text-[18px] leading-none text-[#7C6A39]" x-text="correctiveOpen ? '−' : '+'"></span>
                                                                </button>
                                                                <div x-show="correctiveOpen" x-cloak class="mt-[9px] space-y-[10px]">
                                                                    <div class="text-[12px] font-semibold text-[#604D1B]">Что исправить?</div>
                                                                <div class="space-y-[8px]">
                                                                    <div>
                                                                        <div class="flex flex-col gap-[5px] sm:flex-row sm:items-center sm:justify-between">
                                                                            <div class="text-[12px] font-medium text-[#6B7280]">Ошибка уже повторялась?</div>
                                                                            <div class="grid grid-cols-2 gap-[6px] sm:w-[150px]">
                                                                            @foreach([['value' => true, 'label' => 'Да'], ['value' => false, 'label' => 'Нет']] as $choice)
                                                                                @php
                                                                                    $bool = $choice['value'];
                                                                                @endphp
                                                                                <button
                                                                                    type="button"
                                                                                    wire:click="setCorrectiveBoolean({{ $roomIndex }}, {{ $questionIndex }}, 'repeats', {{ $bool ? 'true' : 'false' }})"
                                                                                    @class([
                                                                                        'min-h-[44px] rounded-full px-[10px] text-[13px] font-semibold transition',
                                                                                        'bg-[#F4D98F] text-[#6B4F12]' => (($corrective['repeats'] ?? null) === $bool),
                                                                                        'bg-white/80 text-[#6B7280] ring-1 ring-inset ring-[#EAD9A8]' => (($corrective['repeats'] ?? null) !== $bool),
                                                                                    ])
                                                                                >{{ $choice['label'] }}</button>
                                                                            @endforeach
                                                                            </div>
                                                                        </div>
                                                                    </div>

                                                                    <label class="block">
                                                                        <span class="mb-[4px] block text-[12px] font-medium text-[#6B7280]">Что нужно делать иначе?</span>
                                                                        <textarea
                                                                            wire:model.blur="answers.{{ $roomIndex }}.{{ $questionIndex }}.corrective.action"
                                                                            rows="2"
                                                                            maxlength="2000"
                                                                            placeholder="Например: протирать стекло сухой микрофиброй"
                                                                            class="w-full rounded-[13px] border-0 bg-white/90 px-[11px] py-[8px] text-[13px] text-[#111827] placeholder:text-[#A08C5A] focus:ring-2 focus:ring-[#D7B95B]/25"
                                                                        ></textarea>
                                                                    </label>

                                                                    <div>
                                                                        <div class="flex flex-col gap-[5px] sm:flex-row sm:items-center sm:justify-between">
                                                                            <div class="text-[12px] font-medium text-[#6B7280]">Проверить ещё раз?</div>
                                                                            <div class="grid grid-cols-2 gap-[6px] sm:w-[150px]">
                                                                            @foreach([['value' => true, 'label' => 'Да'], ['value' => false, 'label' => 'Нет']] as $choice)
                                                                                @php
                                                                                    $bool = $choice['value'];
                                                                                @endphp
                                                                                <button
                                                                                    type="button"
                                                                                    wire:click="setCorrectiveBoolean({{ $roomIndex }}, {{ $questionIndex }}, 'recheck', {{ $bool ? 'true' : 'false' }})"
                                                                                    @class([
                                                                                        'min-h-[44px] rounded-full px-[10px] text-[13px] font-semibold transition',
                                                                                        'bg-[#F4D98F] text-[#6B4F12]' => (($corrective['recheck'] ?? null) === $bool),
                                                                                        'bg-white/80 text-[#6B7280] ring-1 ring-inset ring-[#EAD9A8]' => (($corrective['recheck'] ?? null) !== $bool),
                                                                                    ])
                                                                                >{{ $choice['label'] }}</button>
                                                                            @endforeach
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                </div>
                                                            </div>
                                                        @endif

                        <div class="mt-[10px] rounded-[16px] bg-[#F4F6F4] p-[10px]">
                                                            <div class="flex items-center justify-between gap-[10px]">
                                                                <div class="min-w-0">
                                                                    <div class="text-[13px] font-semibold text-[#111827]">
                                                                        Фото и видео
                                                                    </div>
                                                                    <div class="mt-[2px] text-[12px] font-medium text-[#64748B]">
                                                                        {{ count($questionPhotos) }} / {{ $photoLimit }} файлов
                                                                    </div>
                                                                </div>

                                                                <label class="relative inline-flex min-h-[44px] shrink-0 cursor-pointer items-center overflow-hidden rounded-full bg-white px-[13px] py-[10px] text-[12px] font-semibold text-[#236B49] ring-1 ring-inset ring-[#D4E3D9] focus-within:ring-2 focus-within:ring-[#25845A] active:bg-[#EFF7F1]">
                                                                    Добавить
<input
    x-ref="mediaInput_{{ $roomIndex }}_{{ $questionIndex }}"
    type="file"
    multiple
    accept="image/*,video/mp4,video/quicktime,video/webm"
    aria-label="Добавить фото или видео"
    x-bind:disabled="mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}']?.state === 'uploading'"
    x-on:change="startMediaUpload('{{ $roomIndex }}.{{ $questionIndex }}', $event.target, {{ $roomIndex }}, {{ $questionIndex }}, $event.target.files)"
    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
>
                                                                </label>
                                                            </div>

                                                            <div x-show="mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}']?.state === 'uploading'" x-cloak class="mt-[10px]" role="status" aria-live="polite">
                                                                <div class="text-[12px] font-semibold text-[#526157]" x-text="mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}']?.progress > 0 ? `Загружаем: ${mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}'].progress}%` : 'Подготавливаем файл…'"></div>
                                                                <div class="mt-[6px] h-[4px] overflow-hidden rounded-full bg-[#DDE8E0]">
                                                                    <div class="h-full rounded-full bg-[#25845A] transition-[width] duration-150" x-bind:style="`width: ${mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}']?.progress ?? 0}%`"></div>
                                                                </div>
                                                            </div>

                                                            <div x-show="mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}']?.state === 'error'" x-cloak class="mt-[10px] flex items-center justify-between gap-[10px] rounded-[13px] bg-[#FEE4E2] px-[12px] py-[9px] text-[12px] font-semibold text-[#B42318]" role="alert">
                                                                <span>Не удалось загрузить файл.</span>
                                                                <button type="button" class="min-h-[40px] shrink-0 underline underline-offset-2" x-on:click="startMediaUpload('{{ $roomIndex }}.{{ $questionIndex }}', $refs.mediaInput_{{ $roomIndex }}_{{ $questionIndex }}, {{ $roomIndex }}, {{ $questionIndex }}, mediaUploads['{{ $roomIndex }}.{{ $questionIndex }}'].files)">Повторить</button>
                                                            </div>

                                                            @error("queuedPhotos.$roomIndex.$questionIndex")
                                                                <div class="mt-[10px] rounded-[16px] bg-[#FEE4E2] px-[12px] py-[9px] text-[13px] font-semibold text-[#B42318]">
                                                                    {{ $message }}
                                                                </div>
                                                            @enderror

                                                            @error('photoUploads')
                                                                <div class="mt-[10px] rounded-[16px] bg-[#FEE4E2] px-[12px] py-[9px] text-[13px] font-semibold text-[#B42318]">
                                                                    {{ $message }}
                                                                </div>
                                                            @enderror

                                                            @if(!empty($questionPhotos))
                                                                <div class="mt-[10px] grid grid-cols-3 gap-[8px]">
                                                                    @foreach($questionPhotos as $photoIndex => $photo)
                                                                        @php
                                                                            $mediaUrl = route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $photoIndex]);
                                                                        @endphp
                                                                        <div class="relative overflow-hidden rounded-[16px] bg-white">
                                                                            @if(str_starts_with((string) ($photo['mime'] ?? ''), 'video/'))
                                                                                <video src="{{ $mediaUrl }}" muted preload="metadata" aria-label="Видео контроля" class="h-[88px] w-full object-cover"></video>
                                                                            @else
                                                                                <img src="{{ $mediaUrl }}" alt="Фото контроля" class="h-[88px] w-full object-cover">
                                                                            @endif

                                                                            <span class="absolute bottom-[5px] left-[5px] rounded-full bg-black/65 px-[6px] py-[2px] text-[10px] font-semibold text-white">Готово</span>

                                                                            <button
                                                                                type="button"
                                                                                wire:click="removeQueuedPhoto({{ $roomIndex }}, {{ $questionIndex }}, {{ $photoIndex }})"
                                                                                wire:loading.attr="disabled"
                                                                                wire:target="removeQueuedPhoto"
                                                                                aria-label="Удалить вложение"
                                                                                class="absolute right-[4px] top-[4px] flex h-[40px] w-[40px] items-center justify-center rounded-full bg-black/70 text-[20px] font-medium text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                                                            >
                                                                                ×
                                                                            </button>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div
                        class="control-section mx-[16px] mt-[24px]"
                        id="field-comment"
                        data-control-anchor="comment"
                        x-data="{ commentOpen: @js(filled(trim($comment))) }"
                        x-on:control-open-comment.window="commentOpen = true"
                        x-on:control-form-reset.window="commentOpen = false"
                    >
                        <div class="mb-[12px] flex items-center justify-between">
                            <div>
                                <h2 class="text-[17px] font-semibold tracking-[-0.02em] text-[#111827]">
                                    Комментарий
                                </h2>
                                <p class="mt-[4px] text-[12px] font-medium text-[#94A3B8]">
                                    Необязательно
                                </p>
                            </div>

                            <button
                                type="button"
                                class="min-h-[40px] rounded-full bg-[#F1F5F9] px-[13px] text-[12px] font-semibold text-[#213259]"
                                x-on:click="commentOpen = !commentOpen"
                            >
                                <span x-show="!commentOpen">Добавить</span>
                                <span x-show="commentOpen" x-cloak>Скрыть</span>
                            </button>
                        </div>

                        <div x-show="commentOpen" x-cloak>
                            <textarea
                                wire:model.blur="comment"
                                rows="4"
                                placeholder="Комментарий супервайзера"
                                class="w-full rounded-[20px] border border-[#E6ECF2] bg-white px-[16px] py-[13px] text-[15px] font-medium text-[#111827] placeholder:text-[#94A3B8] focus:ring-2 focus:ring-[#213259]/15"
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
        </div>

        <div class="shrink-0 border-t border-[#E7ECE8] bg-white/95 px-[16px] pt-[10px] backdrop-blur" style="padding-bottom: calc(10px + env(safe-area-inset-bottom))">
            <div class="grid grid-cols-3 gap-[10px]">
                <div class="col-span-1">
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        x-on:click="saveDraftManually()"
                        x-bind:disabled="pendingUploads > 0 || manualSaveInFlight"
                    >
                        <span wire:loading.remove wire:target="saveDraft,saveDraftAuto">
                            Сохранить
                        </span>

                        <span wire:loading wire:target="saveDraft,saveDraftAuto">
                            Сохраняем…
                        </span>

                        <span wire:loading wire:target="photoUploads,finishPhotoUpload">
                            Файл…
                        </span>
                    </x-ui.button>
                </div>

                <div class="col-span-2">
                    <x-ui.button
                        type="button"
                        variant="primary"
                        :progress="$this->formProgress"
                        x-on:click="continueAfterSave()"
                        wire:loading.attr="disabled"
                        wire:target="continueForm,openReview,confirmSubmit,submit,photoUploads,finishPhotoUpload"
                        x-bind:disabled="pendingUploads > 0 || @js($isSubmitting) || controlActionInFlight"
                    >
                        <span wire:loading.remove wire:target="continueForm,openReview,confirmSubmit,submit,photoUploads,finishPhotoUpload">
                            {{ $this->formButtonText }}
                        </span>

                        <span wire:loading wire:target="continueForm,openReview,confirmSubmit,submit">
                            Проверяем…
                        </span>

                        <span wire:loading wire:target="photoUploads,finishPhotoUpload">
                            Загружается файл…
                        </span>
                    </x-ui.button>
                </div>
            </div>

            <div class="mt-[8px] min-h-[17px] text-center text-[12px] font-medium text-[#738078]" role="status" aria-live="polite">
                <span x-show="pendingUploads > 0" x-cloak>Дождитесь завершения загрузки файлов</span>
                <span x-show="pendingUploads === 0">
                    <span x-show="clientDraftState === 'saving'" x-cloak>Сохраняем черновик...</span>
                    <span x-show="clientDraftState === 'dirty'" x-cloak>Есть несохранённые изменения</span>
                    <span x-show="clientDraftState === 'saved'" x-cloak>Черновик сохранён@if($draftSavedAt) в {{ $draftSavedAt }}@endif</span>
                    <span x-show="clientDraftState === 'error'" x-cloak>Не удалось сохранить черновик</span>
                    <span x-show="clientDraftState === 'idle'" x-cloak>Черновик ещё не сохранялся</span>
                    <span x-show="clientDraftState === 'submitted'" x-cloak>Контроль отправлен</span>
                </span>
            </div>
        </div>
    </form>

    <div x-data="{ reviewOpen: @entangle('reviewSheetOpen').live }">
        <x-ui.bottom-sheet x-model="reviewOpen">
            @php
    $summary = $this->reviewSummary;
@endphp

            <div class="max-h-[82dvh] overflow-y-auto overscroll-contain px-[16px] pb-[calc(20px+env(safe-area-inset-bottom))] pt-[12px]">
                <h1 class="text-[21px] font-semibold tracking-[-0.02em] text-[#17201A]">
                    Проверка перед отправкой
                </h1>

                <p class="mt-[6px] text-[13px] leading-[1.45] text-[#66736B]">
                    Проверьте ответы и вложения. Отправка сохранит контроль как финальный результат.
                </p>

                <div class="mt-[18px] space-y-[8px] rounded-[26px] bg-[#F8FAFC] p-[14px] text-[14px] font-medium text-[#111827]">
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Кого проверили</span><span class="text-right font-semibold">{{ $summary['cleaner'] }}</span></div>
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Квартира</span><span class="text-right font-semibold">{{ $summary['apartment'] }}</span></div>
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Дата уборки</span><span class="font-semibold">{{ $summary['cleaning_date'] }}</span></div>
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Дата проверки</span><span class="font-semibold">{{ $summary['inspection_date'] }}</span></div>
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Обязательные</span><span class="font-semibold">{{ $summary['required_done'] }} / {{ $summary['required_total'] }}</span></div>
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Фото и видео</span><span class="font-semibold">{{ $summary['photos_total'] }}</span></div>
                    <div class="flex justify-between gap-[12px]"><span class="text-[#64748B]">Прогресс</span><span class="font-semibold">{{ $summary['progress'] }}%</span></div>
                </div>

                @if(count($this->reviewProblems) > 0)
                    <div class="mt-[14px] space-y-[8px]">
                        <div class="text-[13px] font-semibold text-[#B42318]">Ошибки и корректирующие действия</div>
                        @foreach($this->reviewProblems as $problem)
                            @php
                                $corrective = $problem['corrective'] ?? [];
                            @endphp
                            <div class="rounded-[18px] border border-[#F6D28A] bg-[#FFFAEB] p-[12px] text-[13px] text-[#111827]">
                                <div class="font-semibold">{{ $problem['room_title'] ?? 'Комната' }}</div>
                                <div class="mt-[3px]">{{ $problem['question'] ?? 'Вопрос' }}</div>
                                <div class="mt-[6px] text-[#64748B]">Ответ: {{ $problem['answer'] ?? '—' }}</div>
                                @if(filled($corrective['action'] ?? null))
                                    <div class="mt-[6px]"><span class="font-semibold">Что сделать:</span> {{ $corrective['action'] }}</div>
                                @endif
                                @if(($corrective['repeats'] ?? null) !== null)
                                    <div class="mt-[4px] text-[#64748B]">Ошибка повторяется: {{ $corrective['repeats'] ? 'да' : 'нет' }}</div>
                                @endif
                                @if(($corrective['recheck'] ?? null) !== null)
                                    <div class="mt-[2px] text-[#64748B]">Повторный контроль: {{ $corrective['recheck'] ? 'нужен' : 'не нужен' }}</div>
                                @endif
                                @if(is_array($problem['media'] ?? null) && count($problem['media']) > 0)
                                    <div class="mt-[8px] flex gap-[6px] overflow-x-auto">
                                        @foreach($problem['media'] as $photoIndex => $photo)
                                            @if(is_array($photo))
                                                @php
                                                    $photoUrl = route('page-checks.control.draft-media', [$control, $problem['room_index'], $problem['question_index'], $photoIndex]);
                                                @endphp
                                                @if(str_starts_with((string) ($photo['mime'] ?? ''), 'video/'))
                                                    <video src="{{ $photoUrl }}" muted preload="metadata" class="h-[48px] w-[48px] shrink-0 rounded-[10px] object-cover"></video>
                                                @else
                                                    <img src="{{ $photoUrl }}" alt="" class="h-[48px] w-[48px] shrink-0 rounded-[10px] object-cover">
                                                @endif
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(count($this->reviewIssues) > 0)
                <div class="mt-[14px] rounded-[18px] bg-[#FFF5F3] p-[12px]" aria-live="polite">
                        <div class="text-[13px] font-semibold text-[#A33B32]">
                            Пунктов для проверки: {{ count($this->reviewIssues) }}
                        </div>
                        <div class="mt-[8px] space-y-[7px]">
                            @foreach($this->reviewIssues as $issue)
                                @if($issue['room'] !== null && $issue['question'] !== null)
                                    <button
                                        type="button"
                                        wire:click="goToQuestion({{ $issue['room'] }}, {{ $issue['question'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="goToQuestion"
                                        class="block min-h-[48px] w-full rounded-[13px] bg-white px-[11px] py-[8px] text-left ring-1 ring-inset ring-[#F1D9D6]"
                                    >
                                        <span class="block text-[11px] font-semibold text-[#7A625E]">{{ $issue['title'] }}</span>
                                        <span class="mt-[2px] block whitespace-normal break-words text-[13px] font-medium leading-[1.35] text-[#27322B]">{{ $issue['question_title'] }}</span>
                                        @foreach($issue['messages'] as $message)
                                            <span class="mt-[3px] block text-[11px] text-[#A33B32]">{{ $message }}</span>
                                        @endforeach
                                        <span class="mt-[5px] block text-[11px] font-semibold text-[#25845A]">Перейти к вопросу</span>
                                    </button>
                                @else
                                    <div class="rounded-[13px] bg-white px-[11px] py-[9px] text-[12px] text-[#A33B32]">
                                        <span class="font-semibold">{{ $issue['title'] }}</span>
                                        @foreach($issue['messages'] as $message)
                                            <span class="mt-[2px] block">{{ $message }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="mt-[14px] rounded-[16px] bg-[#EFF8F2] px-[12px] py-[10px] text-[13px] font-medium text-[#26704A]">
                        Все обязательные ответы и вложения готовы к отправке.
                    </div>
                @endif

                <div class="mt-[10px] rounded-[13px] bg-[#FFF8E8] px-[11px] py-[9px] text-[12px] font-medium text-[#765F23]" wire:loading.flex wire:target="photoUploads,finishPhotoUpload">
                    Фото или видео ещё загружается. Дождитесь завершения загрузки.
                </div>

                <div class="sticky bottom-0 z-10 -mx-[16px] mt-[18px] grid grid-cols-2 gap-[10px] border-t border-[#E7ECE8] bg-white px-[16px] pb-[calc(8px+env(safe-area-inset-bottom))] pt-[10px]">
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        @click="reviewOpen = false"
                    >
                        Назад
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        variant="primary"
                        x-on:click="confirmSubmitAfterSave()"
                        wire:loading.attr="disabled"
                        wire:target="confirmSubmit,submit,photoUploads,finishPhotoUpload"
                        :disabled="$isSubmitting || count($this->reviewIssues) > 0"
                    >
                        <span wire:loading.remove wire:target="confirmSubmit,submit,photoUploads,finishPhotoUpload">
                            {{ count($this->reviewIssues) > 0 ? 'Заполните пункты' : 'Отправить контроль' }}
                        </span>
                        <span wire:loading wire:target="confirmSubmit,submit">Отправляем...</span>
                        <span wire:loading wire:target="photoUploads,finishPhotoUpload">Ждём файл…</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.bottom-sheet>
    </div>

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

    <x-ui.guide
        guide-key="quality-control-guide-v1"
        :steps="[
            ['title' => 'Контроль качества', 'text' => 'Заполните данные контроля, ответьте на вопросы по комнатам и при необходимости добавьте фотографии.'],
            ['title' => 'Заполняйте по этапам', 'text' => 'Прогресс показывает, какие комнаты и обязательные вопросы уже пройдены. Незавершённые пункты можно открыть из сводки.'],
            ['title' => 'Проверьте и отправьте', 'text' => 'Перед окончательной отправкой проверьте сводку и нажмите «Отправить». До этого момента ответы остаются черновиком.'],
        ]"
    />
</div>
<script>
    window.controlCompressPhoto = async function (file) {
        try {
            if (!file || !file.type || !file.type.startsWith('image/')) {
                return file;
            }

            // HEIC/HEIF часто не читается через canvas, грузим как есть
            if (
                file.type.includes('heic') ||
                file.type.includes('heif') ||
                file.name.toLowerCase().endsWith('.heic') ||
                file.name.toLowerCase().endsWith('.heif')
            ) {
                return file;
            }

            if (file.size <= 900 * 1024) {
                return file;
            }

            const objectUrl = URL.createObjectURL(file);

            const image = await new Promise((resolve, reject) => {
                const img = new Image();

                img.onload = () => resolve(img);
                img.onerror = reject;
                img.src = objectUrl;
            });

            const maxSize = 1600;
            let width = image.naturalWidth || image.width;
            let height = image.naturalHeight || image.height;

            URL.revokeObjectURL(objectUrl);

            if (!width || !height) {
                return file;
            }

            if (width > height && width > maxSize) {
                height = Math.round(height * (maxSize / width));
                width = maxSize;
            } else if (height > maxSize) {
                width = Math.round(width * (maxSize / height));
                height = maxSize;
            }

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;

            const ctx = canvas.getContext('2d');

            if (!ctx) {
                return file;
            }

            ctx.drawImage(image, 0, 0, width, height);

            return await new Promise((resolve) => {
                canvas.toBlob((blob) => {
                    if (!blob) {
                        resolve(file);
                        return;
                    }

                    resolve(new File(
                        [blob],
                        file.name.replace(/\.[^.]+$/, '') + '.jpg',
                        {
                            type: 'image/jpeg',
                            lastModified: Date.now(),
                        }
                    ));
                }, 'image/jpeg', 0.72);
            });
        } catch (error) {
            console.warn('Photo compression failed, uploading original:', error);
            return file;
        }
    };

    window.controlUploadCompressedPhotos = async function (input, livewire, property, roomIndex, questionIndex, files, onProgress) {
        const selectedFiles = Array.from(files || []);

        if (!selectedFiles.length) {
            return;
        }

        const compressed = [];

        for (const file of selectedFiles) {
            compressed.push(await window.controlCompressPhoto(file));
        }

        try {
            await new Promise((resolve, reject) => {
                livewire.uploadMultiple(
                    property,
                    compressed,
                    () => livewire.call('finishPhotoUpload', roomIndex, questionIndex).then(resolve, reject),
                    () => reject(new Error('Upload failed')),
                    event => onProgress(event.detail?.progress ?? 0)
                );
            });
        } finally {
            input.value = '';
        }
    };
</script>
<script>
    const initializeControlNavigation = () => {
        const getScrollArea = () => document.getElementById('control-scroll-area');

        const scrollToControlTarget = (payload) => {
            const area = getScrollArea();

            if (!area) {
                return;
            }

            let target = null;

            if (payload?.type === 'meta') {
                target = document.getElementById(`field-${payload.key}`);

                if (payload.key === 'comment') {
                    window.dispatchEvent(new CustomEvent('control-open-comment'));
                }
            }

            if (payload?.type === 'room') {
                target = document.getElementById(`room-${payload.room}`);
            }

            if (payload?.type === 'question') {
                target = document.getElementById(`question-${payload.room}-${payload.q}`);
            }

            if (payload?.type === 'top') {
                area.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                return;
            }

            if (!target) {
                target = area;
            }

            if (target === area) {
                area.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                return;
            }

            const areaRect = area.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const top = area.scrollTop + (targetRect.top - areaRect.top) - 16;

            area.scrollTo({ top: Math.max(0, top), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            target.classList.remove('control-scroll-highlight');

            requestAnimationFrame(() => {
                target.classList.add('control-scroll-highlight');
                window.setTimeout(() => target.classList.remove('control-scroll-highlight'), 1900);
            });
        };

        Livewire.on('control-scroll', (event) => {
            const payload = Array.isArray(event) ? event[0] : event;
            window.setTimeout(() => scrollToControlTarget(payload), 80);
        });
    };

    if (window.Livewire?.on) {
        initializeControlNavigation();
    } else {
        document.addEventListener('livewire:init', initializeControlNavigation, { once: true });
    }
</script>
