<?php

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\User;
use App\Services\Controls\ControlRunManager;
use App\Services\Controls\ControlCompletionNotificationService;
use App\Services\Controls\ControlSchema;
use App\Services\Controls\FocusMediaLimits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads {
        _startUpload as private startDefaultUpload;
    }

    #[Locked]
    public ?Control $control = null;

    #[Locked]
    public string $schemaVersion = '';

    #[Locked]
    public string $runId = '';

    #[Locked]
    public int $draftRevision = 0;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public ?int $conflictedDraftId = null;

    #[Locked]
    public bool $draftSchemaConflict = false;

    #[Locked]
    public string $draftSchemaNotice = '';

    public array $answers = [];

    protected array $savedMediaOverrides = [];

    public array $peopleOptions = [];

    public array $apartmentOptions = [];

    public string $stage = 'setup';

    #[Locked]
    public bool $editingReviewData = false;

    public int $currentRoomIndex = 0;

    public ?int $cleaner_id = null;

    public ?int $apartment_id = null;

    public ?string $cleaning_date = null;

    public ?string $inspection_date = null;

    public bool $is_assigned = false;

    public string $previous_cleaner = '';

    public string $comment = '';

    public string $draftState = 'idle';

    public ?string $draftSavedAt = null;

    public string $draftError = '';

    #[Locked]
    public bool $isSubmitting = false;

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public ?int $submittedResponseId = null;

    public ?string $successMessage = null;

    #[Locked]
    public int $pendingMediaCount = 0;

    public $mediaUpload = null;

    public function mount(): void
    {
        $this->ensureAuthorized();

        $this->control = Control::query()->where('is_active', true)->latest()->first();
        abort_if(! $this->control, 404);

        $rooms = $this->rooms;
        $this->schemaVersion = (new ControlSchema((int) $this->control->id, $rooms))->version();
        $this->runId = (string) Str::uuid();
        $this->initializeAnswers();
        $this->cleaning_date = now()->toDateString();
        $this->inspection_date = now()->toDateString();
        $this->restoreDraft();

        $this->peopleOptions = User::query()->activeStaff()->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name')->get(['id', 'name', 'telegram_avatar_path', 'telegram_photo_url'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'thumbnail' => $user->avatar_url])
            ->all();
        $this->apartmentOptions = Apartment::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'image'])
            ->map(fn (Apartment $apartment): array => [
                'id' => $apartment->id,
                'name' => $apartment->name,
                'thumbnail' => filled($apartment->image) ? Storage::disk('public')->url($apartment->image) : null,
            ])->all();
    }

    protected function ensureAuthorized(): void
    {
        abort_unless(Auth::user()?->is_active && in_array(Auth::user()->role, ['admin', 'supervisor'], true), 403);
    }

    protected function emptyAnswer(): array
    {
        return ['selected' => '', 'custom' => '', 'corrective' => [
            'repeats' => null, 'action' => '', 'recheck' => null,
        ]];
    }

    public function getRoomsProperty(): array
    {
        return is_array($this->control?->main) ? array_values($this->control->main) : [];
    }

    public function getSavedMediaProperty(): array
    {
        $media = [];

        if ($this->control && $this->submittedResponseId !== null) {
            $response = ControlResponse::query()
                ->whereKey($this->submittedResponseId)
                ->where('control_id', $this->control->id)
                ->where('supervisor_id', Auth::id())
                ->first();
            $responses = $response?->responses ?? [];
        } elseif ($this->control && $this->draftId !== null) {
            $draft = ControlResponseDraft::query()
                ->whereKey($this->draftId)
                ->where('control_id', $this->control->id)
                ->where('supervisor_id', Auth::id())
                ->where('state', 'active')
                ->first();
            $responses = $draft?->responses ?? [];
        } else {
            $responses = [];
        }

        foreach ($responses as $roomIndex => $questions) {
                foreach ((array) $questions as $questionIndex => $answer) {
                    $files = is_array($answer['media'] ?? null) ? $answer['media'] : [];
                    $media[$roomIndex][$questionIndex] = array_values(array_filter($files, function (mixed $file): bool {
                        if (! is_array($file)) {
                            return false;
                        }

                        $disk = $file['disk'] ?? null;
                        $path = (string) ($file['path'] ?? '');

                        return in_array($disk, ['local', 'public'], true)
                            && str_starts_with($path, 'controls/'.$this->control->id.'/')
                            && ! str_contains($path, '..')
                            && ! str_contains($path, '\\');
                    }));
                }
        }

        foreach ($this->savedMediaOverrides as $roomIndex => $questions) {
            foreach ($questions as $questionIndex => $files) {
                $media[$roomIndex][$questionIndex] = array_values($files);
            }
        }

        return $media;
    }

    protected function setSavedMediaForQuestion(int $roomIndex, int $questionIndex, array $files): void
    {
        $this->savedMediaOverrides[$roomIndex][$questionIndex] = array_values($files);
        unset($this->savedMedia);
    }

    protected function clearSavedMediaOverride(int $roomIndex, int $questionIndex): void
    {
        unset($this->savedMediaOverrides[$roomIndex][$questionIndex]);
        unset($this->savedMedia);
    }

    protected function initializeAnswers(): void
    {
        $this->answers = [];

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $this->answers[$roomIndex][$questionIndex] = $this->emptyAnswer();
            }
        }
    }

    public function getQuestionsProperty(): array
    {
        $questions = [];
        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $questions[] = [
                    'room_index' => $roomIndex,
                    'question_index' => $questionIndex,
                    'room_title' => $room['title'] ?? 'Комната '.($roomIndex + 1),
                    'optional' => (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false)),
                    'question' => $question,
                ];
            }
        }

        return $questions;
    }

    public function getCompletedCountProperty(): int
    {
        $completed = 0;
        foreach ($this->questionCompletion as $questions) {
            $completed += count(array_filter($questions));
        }

        return $completed;
    }

    public function getQuestionCompletionProperty(): array
    {
        $completion = [];
        foreach ($this->questions as $item) {
            $roomIndex = $item['room_index'];
            $questionIndex = $item['question_index'];
            $completion[$roomIndex][$questionIndex] = $this->filledAnswer(
                $this->answers[$roomIndex][$questionIndex] ?? [],
            );
        }

        return $completion;
    }

    public function getRequiredQuestionsByRoomProperty(): array
    {
        $required = [];
        foreach ($this->questions as $item) {
            if (! $item['optional']) {
                $required[$item['room_index']][] = $item['question_index'];
            }
        }

        return $required;
    }

    public function getIncompleteQuestionsProperty(): array
    {
        return array_values(array_filter($this->questions, function (array $item): bool {
            $answer = $this->answers[$item['room_index']][$item['question_index']] ?? [];

            return ! $item['optional'] && ! $this->filledAnswer($answer);
        }));
    }

    protected function filledAnswer(mixed $answer): bool
    {
        return is_array($answer) && (trim((string) ($answer['selected'] ?? '')) !== ''
            || trim((string) ($answer['custom'] ?? '')) !== '');
    }

    public function updated(string $name): void
    {
        if ($name === 'mediaUpload' || $this->submitted) {
            return;
        }

        if (str_starts_with($name, 'answers.') || in_array($name, [
            'cleaner_id', 'apartment_id', 'cleaning_date', 'inspection_date',
            'is_assigned', 'previous_cleaner', 'comment',
        ], true)) {
            $this->draftState = 'dirty';
            $this->draftError = '';
        }
    }

    protected function currentCanonicalRooms(): ?array
    {
        if (! $this->control) {
            return null;
        }

        $control = Control::query()
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $control || (int) $control->id !== (int) $this->control->id) {
            $message = 'Активный шаблон контроля изменился. Перезагрузите страницу или начните новый черновик.';
            $this->addError('draft', $message);
            $this->draftError = $message;

            return null;
        }

        $rooms = is_array($control->main) ? array_values($control->main) : [];
        $version = (new ControlSchema((int) $control->id, $rooms))->version();

        if (! hash_equals($this->schemaVersion, $version)) {
            $this->draftSchemaConflict = true;
            $this->conflictedDraftId = $this->draftId;
            $message = 'Шаблон контроля изменился во время проверки. Старый черновик сохранён; начните новую проверку.';
            $this->addError('draft', $message);
            $this->draftError = $message;

            return null;
        }

        return $rooms;
    }

    protected function normalizedAnswers(array $rooms): array
    {
        $normalized = [];
        foreach ($rooms as $roomIndex => $room) {
            foreach (array_values($room['items'] ?? []) as $questionIndex => $question) {
                $answer = $this->answers[$roomIndex][$questionIndex] ?? [];
                $answer = is_array($answer) ? $answer : [];
                $selected = mb_substr(trim((string) ($answer['selected'] ?? '')), 0, 255);
                $negative = ControlResponse::isNegativeAnswer($question, $selected);
                $corrective = is_array($answer['corrective'] ?? null) ? $answer['corrective'] : [];
                $normalized[$roomIndex][$questionIndex] = [
                    'selected' => $selected,
                    'custom' => mb_substr(trim((string) ($answer['custom'] ?? '')), 0, 2000),
                    'media' => array_values($this->savedMedia[$roomIndex][$questionIndex] ?? []),
                    'corrective' => $negative ? [
                        'repeats' => is_bool($corrective['repeats'] ?? null) ? $corrective['repeats'] : null,
                        'action' => mb_substr(trim((string) ($corrective['action'] ?? '')), 0, 2000),
                        'recheck' => is_bool($corrective['recheck'] ?? null) ? $corrective['recheck'] : null,
                    ] : $this->emptyAnswer()['corrective'],
                ];
            }
        }

        return (new ControlSchema((int) $this->control->id, $rooms))->attachQuestionIds($normalized);
    }

    protected function draftPayload(array $rooms): array
    {
        return [
            'cleaner_id' => $this->cleaner_id,
            'apartment_id' => $this->apartment_id,
            'cleaning_date' => $this->cleaning_date,
            'inspection_date' => $this->inspection_date,
            'is_assigned' => $this->is_assigned,
            'previous_cleaner' => $this->previous_cleaner,
            'comment' => $this->comment,
            'responses' => $this->normalizedAnswers($rooms),
            'schema_snapshot' => $rooms,
            'schema_version' => (new ControlSchema((int) $this->control->id, $rooms))->version(),
        ];
    }
    public function saveDraft(bool $silent = false): bool
    {
        $this->ensureAuthorized();
        if (! $this->control || $this->submitted || $this->draftSchemaConflict) {
            return false;
        }

        $rooms = $this->currentCanonicalRooms();
        if ($rooms === null) {
            return false;
        }

        $schema = new ControlSchema((int) $this->control->id, $rooms);
        $validationErrors = $schema->validateAnswers($this->answers);
        if ($validationErrors !== []) {
            $this->resetErrorBag();
            foreach ($validationErrors as $key => $message) {
                $this->addError($key, $message);
            }
            $this->draftState = 'error';
            $this->draftError = reset($validationErrors) ?: 'Проверьте ответы перед сохранением.';

            return false;
        }

        $this->resetErrorBag();
        $this->draftState = 'saving';
        $this->draftError = '';

        try {
            $draft = app(ControlRunManager::class)->saveDraft(
                controlId: (int) $this->control->id,
                supervisorId: (int) Auth::id(),
                runId: $this->runId,
                expectedRevision: $this->draftRevision,
                draftId: $this->draftId,
                schemaVersion: $schema->version(),
                attributes: $this->draftPayload($rooms),
            );

            $this->draftId = $draft->id;
            $this->draftRevision = (int) $draft->revision;
            $this->runId = (string) $draft->run_id;
            $this->draftState = 'saved';
            $this->draftSavedAt = $draft->updated_at?->format('H:i');

            return true;
        } catch (ValidationException $e) {
            $message = $e->errors()['draft'][0] ?? 'Не удалось сохранить черновик. Повторите сохранение.';
            $this->draftState = 'error';
            $this->draftError = $message;
            $this->addError('draft', $message);

            return false;
        } catch (Throwable $e) {
            report($e);
            $this->draftState = 'error';
            $this->draftError = 'Не удалось сохранить черновик. Повторите сохранение.';

            return false;
        }
    }

    protected function restoreDraft(): void
    {
        $draft = ControlResponseDraft::query()
            ->where('control_id', $this->control->id)
            ->where('supervisor_id', Auth::id())
            ->where('state', 'active')
            ->latest('updated_at')
            ->first();

        if (! $draft) {
            $conflict = ControlResponseDraft::query()
                ->where('control_id', $this->control->id)
                ->where('supervisor_id', Auth::id())
                ->where('state', 'conflict')
                ->latest('updated_at')
                ->first();

            if ($conflict) {
                $this->draftSchemaConflict = true;
                $this->conflictedDraftId = $conflict->id;
                $this->draftState = 'error';
                $this->draftError = 'Найден сохранённый черновик другой версии. Его ответы сохранены отдельно.';
            }

            return;
        }


        $draftVersion = $draft->schema_version;
        if (! $draftVersion && is_array($draft->schema_snapshot)) {
            $draftVersion = (new ControlSchema((int) $this->control->id, $draft->schema_snapshot))->version();
        }

        if (! is_string($draftVersion) || ! hash_equals($this->schemaVersion, $draftVersion)) {
            $this->draftSchemaConflict = true;
            $this->conflictedDraftId = $draft->id;
            $this->draftId = null;
            $this->draftRevision = 0;
            $this->runId = (string) Str::uuid();
            $this->draftState = 'error';
            $this->draftError = 'Черновик относится к другой версии списка. Ответы не перенесены, старый черновик сохранён.';

            return;
        }

        $schema = new ControlSchema((int) $this->control->id, $this->rooms);
        foreach ($schema->questionIds() as $roomIndex => $questions) {
            foreach ($questions as $questionIndex => $questionId) {
                $storedId = $draft->responses[$roomIndex][$questionIndex]['question_id'] ?? null;
                if (is_string($storedId) && ! hash_equals($questionId, $storedId)) {
                    $this->draftSchemaConflict = true;
                    $this->conflictedDraftId = $draft->id;
                    $this->draftId = null;
                    $this->draftRevision = 0;
                    $this->runId = (string) Str::uuid();
                    $this->draftState = 'error';
                    $this->draftError = 'Ответы черновика не совпадают с его снимком. Старый черновик сохранён.';

                    return;
                }
            }
        }

        foreach (['cleaner_id', 'apartment_id', 'is_assigned', 'previous_cleaner', 'comment'] as $field) {
            $this->{$field} = $draft->{$field} ?? $this->{$field};
        }
        $this->cleaning_date = $draft->cleaning_date?->toDateString() ?? $this->cleaning_date;
        $this->inspection_date = $draft->inspection_date?->toDateString() ?? $this->inspection_date;

        $this->draftId = $draft->id;
        $this->draftRevision = (int) $draft->revision;
        $this->runId = $draft->run_id ?: (string) Str::uuid();

        if (is_array($draft->responses)) {
            $restoredAnswers = $draft->responses;
            foreach ($this->questions as $item) {
                $roomIndex = $item['room_index'];
                $questionIndex = $item['question_index'];
                unset($restoredAnswers[$roomIndex][$questionIndex]['media']);
            }
            $this->answers = array_replace_recursive($this->answers, $restoredAnswers);
        }

        if ($this->completedCount > 0) {
            $this->currentRoomIndex = $this->incompleteQuestions[0]['room_index'] ?? max(0, count($this->rooms) - 1);
        }
        $this->draftState = 'saved';
        $this->draftSavedAt = $draft->updated_at?->format('H:i');
    }

    public function startNewDraft(): void
    {
        $this->ensureAuthorized();
        if (! $this->draftSchemaConflict || ! $this->control) {
            return;
        }

        if ($this->conflictedDraftId !== null) {
            app(ControlRunManager::class)->markSchemaConflict(
                $this->conflictedDraftId,
                (int) $this->control->id,
                (int) Auth::id(),
            );
        }

        $control = Control::query()
            ->where('is_active', true)
            ->latest()
            ->firstOrFail();
        $this->control = $control;
        $rooms = is_array($control->main) ? array_values($control->main) : [];
        $this->schemaVersion = (new ControlSchema((int) $control->id, $rooms))->version();
        $this->draftId = null;
        $this->draftRevision = 0;
        $this->conflictedDraftId = null;
        $this->runId = (string) Str::uuid();
        $this->draftSchemaConflict = false;
        $this->draftSchemaNotice = 'Старый черновик сохранён без переноса ответов. Новая проверка начата отдельно.';
        $this->currentRoomIndex = 0;
        $this->stage = 'setup';
        $this->editingReviewData = false;
        $this->initializeAnswers();
        $this->draftState = 'dirty';
        $this->saveDraft(true);
    }
    public function start(): bool
    {
        $this->ensureAuthorized();
        if ($this->draftSchemaConflict) {
            return false;
        }

        $this->resetErrorBag();
        $this->validateSetup();
        if ($this->getErrorBag()->isNotEmpty() || ! $this->saveDraft(true)) {
            return false;
        }

        $this->stage = $this->editingReviewData ? 'review' : 'questions';
        $this->editingReviewData = false;

        return true;
    }
    public function goToQuestion(int $roomIndex, int $questionIndex): void
    {
        $this->ensureAuthorized();
        foreach ($this->questions as $item) {
            if ($item['room_index'] === $roomIndex && $item['question_index'] === $questionIndex) {
                $this->currentRoomIndex = $roomIndex;
                $this->stage = 'questions';
                $this->dispatch('focus-question-selected', room: $roomIndex, question: $questionIndex);

                return;
            }
        }
    }

    public function goToRoom(int $roomIndex): void
    {
        foreach ($this->questions as $item) {
            if ($item['room_index'] === $roomIndex) {
                $this->goToQuestion($roomIndex, $item['question_index']);

                return;
            }
        }
    }

    public function next(): void
    {
        $this->ensureAuthorized();
        if ($this->stage !== 'questions') {
            return;
        }

        foreach ($this->incompleteQuestions as $item) {
            if ($item['room_index'] === $this->currentRoomIndex) {
                $this->goToQuestion($item['room_index'], $item['question_index']);

                return;
            }
        }

        if ($this->currentRoomIndex + 1 < count($this->rooms)) {
            $this->currentRoomIndex++;
        } else {
            $this->review($this->currentRoomIndex);
        }
    }

    public function previous(): void
    {
        $this->ensureAuthorized();
        if ($this->stage === 'review') {
            $this->stage = 'questions';
        } elseif ($this->currentRoomIndex > 0) {
            $this->currentRoomIndex--;
        } else {
            $this->stage = 'setup';
        }
    }

    public function backToSetup(): void
    {
        $this->ensureAuthorized();
        $this->editingReviewData = $this->stage === 'review';
        $this->stage = 'setup';
    }

    public function setAnswer(int $roomIndex, int $questionIndex, string $value): void
    {
        $this->ensureAuthorized();
        abort_if($this->draftSchemaConflict, 409);
        $question = $this->rooms[$roomIndex]['items'][$questionIndex] ?? null;
        abort_unless(is_array($question), 404);
        $allowed = array_map(
            fn (array $option, int $index): string => (string) ($option['value'] ?? $option['label'] ?? 'option_'.$index),
            $question['answer_options_scored'] ?? [],
            array_keys($question['answer_options_scored'] ?? [])
        );
        abort_unless(in_array($question['answer_type'] ?? 'options', ['options', 'both'], true)
            && in_array($value, $allowed, true), 422);

        $this->answers[$roomIndex][$questionIndex]['selected'] = $value;
        if (! ControlResponse::isNegativeAnswer($question, $value)) {
            $this->answers[$roomIndex][$questionIndex]['corrective'] = $this->emptyAnswer()['corrective'];
        }
        $this->saveDraft(true);
    }

    public function setCorrectiveBoolean(int $roomIndex, int $questionIndex, string $field, bool $value): void
    {
        $this->ensureAuthorized();
        abort_unless(in_array($field, ['repeats', 'recheck'], true), 422);
        $question = $this->rooms[$roomIndex]['items'][$questionIndex] ?? null;
        abort_unless(is_array($question), 404);
        $selected = (string) ($this->answers[$roomIndex][$questionIndex]['selected'] ?? '');
        abort_unless(ControlResponse::isNegativeAnswer($question, $selected), 422);
        $this->answers[$roomIndex][$questionIndex]['corrective'][$field] = $value;
        $this->saveDraft(true);
    }

    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple)
    {
        $this->ensureAuthorized();
        abort_unless($name === 'mediaUpload' && ! $isMultiple, 422);

        if (FileUploadConfiguration::isUsingS3() || FileUploadConfiguration::isUsingGCS()) {
            $this->startDefaultUpload($name, $fileInfo, $isMultiple);

            return;
        }

        $this->dispatch('upload:generatedSignedUrl', name: $name, url: URL::temporarySignedRoute(
            'page-checks.control.focus-upload',
            now()->addMinutes(15),
            ['user' => Auth::id()],
        ))->self();
    }

    public function beginMediaUpload(): void
    {
        $this->ensureAuthorized();
        $this->pendingMediaCount = 1;
    }

    public function failMediaUpload(): void
    {
        $this->pendingMediaCount = max(0, $this->pendingMediaCount - 1);
        $this->mediaUpload = null;
        $this->addError('mediaUpload', 'Файл не загружен. Попробуйте ещё раз.');
    }

    public function cancelMediaUpload(): void
    {
        $this->ensureAuthorized();
        $this->pendingMediaCount = max(0, $this->pendingMediaCount - 1);
        $this->mediaUpload = null;
        $this->resetErrorBag('mediaUpload');
    }

    public function attachMedia(int $roomIndex, int $questionIndex, ?string $uploadId = null): bool
    {
        $this->ensureAuthorized();
        $this->resetErrorBag('mediaUpload');
        if ($uploadId !== null && (strlen($uploadId) > 100 || preg_match('/\A[A-Za-z0-9._-]+\z/', $uploadId) !== 1)) {
            abort(422);
        }

        $question = $this->rooms[$roomIndex]['items'][$questionIndex] ?? null;
        $file = $this->mediaUpload;
        $this->mediaUpload = null;
        $this->pendingMediaCount = max(0, $this->pendingMediaCount - 1);

        if (! is_array($question) || ! $file instanceof TemporaryUploadedFile) {
            $this->addError('mediaUpload', 'Файл не загружен. Попробуйте ещё раз.');
            $this->draftState = 'error';
            $this->draftError = 'Файл не сохранён. Повторите загрузку.';

            return false;
        }

        if ($uploadId !== null) {
            $existingDraft = ControlResponseDraft::query()
                ->where('control_id', $this->control->id)
                ->where('supervisor_id', Auth::id())
                ->where('run_id', $this->runId)
                ->where('state', 'active')
                ->first();

            foreach (($existingDraft?->responses ?? []) as $savedRoomIndex => $savedQuestions) {
                foreach ((array) $savedQuestions as $savedQuestionIndex => $savedAnswer) {
                    foreach (($savedAnswer['media'] ?? []) as $savedFile) {
                        if (! is_array($savedFile) || ! hash_equals((string) ($savedFile['upload_id'] ?? ''), $uploadId)) {
                            continue;
                        }

                        if ($savedRoomIndex !== $roomIndex || $savedQuestionIndex !== $questionIndex) {
                            abort(409, 'This upload is already attached to another question.');
                        }

                        abort_if(
                            ! is_string($existingDraft->schema_version)
                                || ! hash_equals($this->schemaVersion, $existingDraft->schema_version),
                            409,
                            'The checklist schema changed during this upload.',
                        );

                        $this->draftId = $existingDraft->id;
                        $this->draftRevision = (int) $existingDraft->revision;
                        $this->pendingMediaCount = 0;
                        $this->mediaUpload = null;
                        $this->draftState = 'saved';
                        $this->draftError = '';
                        $this->draftSavedAt = $existingDraft->updated_at?->format('H:i');

                        return true;
                    }
                }
            }
        }

        $mime = (string) $file->getMimeType();
        $isImage = in_array($mime, FocusMediaLimits::IMAGE_MIMES, true);
        $isVideo = in_array($mime, FocusMediaLimits::VIDEO_MIMES, true);
        $size = $file->getSize();
        $mediaCount = count($this->savedMedia[$roomIndex][$questionIndex] ?? []);

        if ((! $isImage && ! $isVideo) || $size > ($isImage ? FocusMediaLimits::IMAGE_KIB : FocusMediaLimits::VIDEO_KIB) * 1024 || $mediaCount >= 12) {
            $this->addError('mediaUpload', 'Допустимо до 12 файлов: фото до 10 МБ, видео до 100 МБ (JPEG, PNG, WebP, HEIC, MP4, MOV, WebM).');
            $this->draftState = 'error';
            $this->draftError = 'Файл не сохранён. Проверьте формат, размер и число файлов.';

            return false;
        }

        $path = null;
        try {
            $path = $file->store('controls/'.$this->control->id.'/'.now()->format('Y/m'), 'local');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Failed to store Focus media.');
            }

            $media = $this->savedMedia[$roomIndex][$questionIndex] ?? [];
            $media[] = [
                'disk' => 'local', 'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
                'mime' => $mime, 'size' => $size, 'uploaded_at' => now()->toDateTimeString(),
                ...($uploadId !== null ? ['upload_id' => $uploadId] : []),
            ];
            $this->setSavedMediaForQuestion($roomIndex, $questionIndex, $media);
            if (! $this->saveDraft(true)) {
                $this->clearSavedMediaOverride($roomIndex, $questionIndex);
                Storage::disk('local')->delete($path);

                return false;
            }

            $this->clearSavedMediaOverride($roomIndex, $questionIndex);

            return true;
        } catch (Throwable $e) {
            $this->clearSavedMediaOverride($roomIndex, $questionIndex);
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            report($e);
            $this->addError('mediaUpload', 'Не удалось сохранить файл. Попробуйте ещё раз.');
            $this->draftState = 'error';
            $this->draftError = 'Файл не сохранён. Повторите загрузку.';

            return false;
        }
    }

    public function removeMedia(int $roomIndex, int $questionIndex, int $mediaIndex): void
    {
        $this->ensureAuthorized();
        $media = $this->savedMedia[$roomIndex][$questionIndex][$mediaIndex] ?? null;
        if (! is_array($media)) {
            return;
        }

        $files = $this->savedMedia[$roomIndex][$questionIndex];
        unset($files[$mediaIndex]);
        $this->setSavedMediaForQuestion($roomIndex, $questionIndex, array_values($files));
        if (! $this->saveDraft(true)) {
            $this->clearSavedMediaOverride($roomIndex, $questionIndex);

            return;
        }

        $this->clearSavedMediaOverride($roomIndex, $questionIndex);

        $path = (string) ($media['path'] ?? '');
        $disk = (string) ($media['disk'] ?? '');
        if (in_array($disk, ['local', 'public'], true)
            && str_starts_with($path, 'controls/'.$this->control->id.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')) {
            Storage::disk($disk)->delete($path);
        }
    }

    public function saveMediaComment(int $roomIndex, int $questionIndex, int $mediaIndex, string $comment, ?string $fileKey = null): bool
    {
        $this->ensureAuthorized();
        if ($this->submitted || $this->stage === 'done') {
            return false;
        }

        $media = $this->savedMedia[$roomIndex][$questionIndex] ?? null;
        if (! is_array($media) || ! is_array($media[$mediaIndex] ?? null)
            || ($fileKey !== null && ! hash_equals(hash('sha256', (string) ($media[$mediaIndex]['path'] ?? '')), $fileKey))) {
            return false;
        }

        $comment = mb_substr(trim($comment), 0, 2000);
        if ($comment === trim((string) ($media[$mediaIndex]['comment'] ?? ''))) {
            return true;
        }

        if ($comment === '') {
            unset($media[$mediaIndex]['comment']);
        } else {
            $media[$mediaIndex]['comment'] = $comment;
        }

        $this->setSavedMediaForQuestion($roomIndex, $questionIndex, array_values($media));
        if (! $this->saveDraft(true)) {
            $this->clearSavedMediaOverride($roomIndex, $questionIndex);

            return false;
        }

        $this->clearSavedMediaOverride($roomIndex, $questionIndex);

        return true;
    }

    protected function validateSetup(): void
    {
        if (! $this->cleaner_id || ! User::query()->activeStaff()->whereIn('role', ['cleaner', 'supervisor'])->whereKey($this->cleaner_id)->exists()) {
            $this->addError('cleaner_id', 'Выберите сотрудника.');
        }
        if (! $this->apartment_id || ! Apartment::query()->where('is_active', true)->whereKey($this->apartment_id)->exists()) {
            $this->addError('apartment_id', 'Выберите квартиру.');
        }
        $validCleaningDate = $this->isCalendarDate($this->cleaning_date);
        if (! $validCleaningDate) {
            $this->addError('cleaning_date', 'Укажите корректную дату уборки.');
        }
        if (! $this->isCalendarDate($this->inspection_date)) {
            $this->addError('inspection_date', 'Укажите корректную дату проверки.');
        } elseif ($validCleaningDate && $this->inspection_date < $this->cleaning_date) {
            $this->addError('inspection_date', 'Дата проверки не может быть раньше уборки.');
        }
        if (mb_strlen($this->previous_cleaner) > 255 || mb_strlen($this->comment) > 2000) {
            $this->addError('comment', 'Проверьте длину комментария и предыдущего сотрудника.');
        }
    }

    protected function isCalendarDate(?string $date): bool
    {
        if (! is_string($date) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    protected function validateControl(): array|false
    {
        $this->resetErrorBag();
        if ($this->draftSchemaConflict) {
            $this->addError('draft', $this->draftError ?: 'Разрешите конфликт версии черновика перед продолжением.');

            return false;
        }

        $rooms = $this->currentCanonicalRooms();
        if ($rooms === null) {
            return false;
        }

        $this->validateSetup();

        $schema = new ControlSchema((int) $this->control->id, $rooms);
        foreach ($schema->validateAnswers($this->answers, requireComplete: true) as $key => $message) {
            $this->addError($key, $message);
        }

        foreach ($rooms as $roomIndex => $room) {
            foreach (array_values($room['items'] ?? []) as $questionIndex => $question) {
                if (count($this->savedMedia[$roomIndex][$questionIndex] ?? []) > 12) {
                    $this->addError("answers.$roomIndex.$questionIndex", 'Максимум 12 файлов на вопрос.');
                }
            }
        }

        if ($this->pendingMediaCount > 0 || $this->mediaUpload instanceof TemporaryUploadedFile) {
            $this->addError('mediaUpload', 'Дождитесь завершения загрузки файлов.');
        }

        return $this->getErrorBag()->isEmpty() ? $rooms : false;
    }
    public function review(?int $roomIndex = null): bool
    {
        $this->ensureAuthorized();
        if ($this->draftSchemaConflict || ! $this->saveDraft(true)) {
            return false;
        }

        if ($roomIndex !== null && isset($this->rooms[$roomIndex])) {
            $this->currentRoomIndex = $roomIndex;
        }

        $this->stage = 'review';

        return true;
    }
    public function submit(): void
    {
        $this->ensureAuthorized();
        if ($this->stage !== 'review' || $this->isSubmitting || $this->submitted) {
            return;
        }

        $rooms = $this->validateControl();
        if ($rooms === false) {
            return;
        }

        $this->isSubmitting = true;
        $lockKey = implode(':', ['control-submit', $this->runId]);
        $lock = Cache::lock($lockKey, 30);
        if (! $lock->get()) {
            $this->isSubmitting = false;
            $this->addError('submit', 'Контроль уже отправляется. Повторите через несколько секунд.');

            return;
        }

        try {
            $answers = $this->normalizedAnswers($rooms);
            $score = ControlResponse::calculateScores($rooms, $answers);
            $response = app(ControlRunManager::class)->submitOnce(
                controlId: (int) $this->control->id,
                supervisorId: (int) Auth::id(),
                runId: $this->runId,
                schemaVersion: $this->schemaVersion,
                expectedRevision: $this->draftRevision,
                draftId: $this->draftId,
                attributes: [
                    'cleaner_id' => $this->cleaner_id,
                    'apartment_id' => $this->apartment_id,
                    'is_assigned' => $this->is_assigned,
                    'previous_cleaner' => trim($this->previous_cleaner),
                    'cleaning_date' => $this->cleaning_date,
                    'inspection_date' => $this->inspection_date,
                    'comment' => trim($this->comment),
                    'responses' => $answers,
                    'schema_snapshot' => $rooms,
                    'schema_version' => $this->schemaVersion,
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
                ],
                afterCreate: function (ControlResponse $response) use ($score): void {
                    app(ControlCompletionNotificationService::class)->queueFor($response);

                    activity()->causedBy(Auth::user())->performedOn($response)->event('control_completed')
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
                },
            );

            $this->draftId = null;
            $this->submittedResponseId = $response->id;
            $this->submitted = true;
            $this->stage = 'done';
            $this->successMessage = "Оценка: {$response->total_points} / {$response->max_points} ({$response->score_percent}%).";
        } catch (ValidationException $e) {
            $this->addError('submit', $e->errors()['draft'][0] ?? $e->errors()['submit'][0] ?? 'Черновик изменился. Перезагрузите контроль перед отправкой.');
        } catch (Throwable $e) {
            report($e);
            $this->addError('submit', 'Не удалось отправить контроль. Попробуйте ещё раз.');
        } finally {
            $lock->release();
            $this->isSubmitting = false;
        }
    }

};
?>

<x-slot:header>
    <div class="flex h-[64px] items-center justify-between gap-3 px-4">
        <a href="{{ route('page-checks') }}" class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-full bg-[#EAECF0] px-3 text-sm font-semibold text-[#213259]">
            <x-heroicon-o-arrow-left class="size-4" />
            Контроли
        </a>
        @if($stage === 'setup')
            <a href="{{ route('page-checks.control') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-semibold text-[#285B86]">Классический</a>
        @endif
    </div>
</x-slot:header>

<div class="contents">
{{-- Keep the Alpine state for this run across Livewire renders. --}}
<div wire:key="focus-run-{{ $runId }}" wire:ignore.self class="flex min-h-full flex-col bg-[#E7E8EA] pt-6 text-[#182230] sm:pt-8"
    x-data="{
        activeRoomIndex: @entangle('currentRoomIndex'),
        cleanerId: @entangle('cleaner_id'),
        apartmentId: @entangle('apartment_id'),
        cleaningDate: @entangle('cleaning_date'),
        inspectionDate: @entangle('inspection_date'),
        isAssigned: @entangle('is_assigned'),
        previousCleaner: @entangle('previous_cleaner'),
        controlComment: @entangle('comment'),
        questionCompletion: @js($this->questionCompletion),
        requiredQuestionsByRoom: @js($this->requiredQuestionsByRoom),
        missingQuestion: null,
        clientDraftState: @js($draftState),
        clientDraftError: @js($draftError),
        saveTimer: null, saveInFlight: false, saveRevision: 0, persistedSaveRevision: 0,
        mutationQueue: Promise.resolve(),
        roomCount: {{ count($this->rooms) }},
        failedComments: {},
        pendingComments: 0,
        transportBytes: @js(FocusMediaLimits::transportBytes()),
        uploads: [], queue: Promise.resolve(), nextId: 0, attachmentTarget: null,
        destroy() {
            window.clearTimeout(this.saveTimer);
        },
        enqueueMutation(action) {
            const request = this.mutationQueue.catch(() => {}).then(action);
            this.mutationQueue = request.catch(() => {});
            return request;
        },
        updateQuestionCompletion(room, question, selected, custom) {
            this.questionCompletion[room] ||= [];
            const answered = String(selected ?? '').trim() !== '' || String(custom ?? '').trim() !== '';
            this.questionCompletion[room][question] = answered;
            if (answered && room === this.activeRoomIndex && question === this.missingQuestion) this.missingQuestion = null;
        },
        get setupComplete() {
            return Boolean(this.cleanerId && this.apartmentId && this.cleaningDate && this.inspectionDate
                && this.inspectionDate >= this.cleaningDate);
        },
        roomAnsweredCount(index) {
            return (this.requiredQuestionsByRoom[index] || []).filter(question => this.questionCompletion[index]?.[question]).length;
        },
        roomProgress(index) {
            const required = this.requiredQuestionsByRoom[index] || [];
            const completed = this.roomAnsweredCount(index);
            if (required.length === completed) return 'complete';
            return completed > 0 ? 'partial' : 'empty';
        },
        scheduleAutosave(delay = 900) {
            this.saveRevision++;
            this.clientDraftState = 'dirty';
            this.clientDraftError = '';
            window.clearTimeout(this.saveTimer);
            this.saveTimer = window.setTimeout(() => this.flushAutosave(), delay);
        },
        async flushAutosave() {
            window.clearTimeout(this.saveTimer);
            this.saveTimer = null;
            if (this.saveInFlight) return false;
            if (this.clientDraftState === 'saved' && this.persistedSaveRevision === this.saveRevision) return true;

            const revision = this.saveRevision;
            this.saveInFlight = true;
            this.clientDraftState = 'saving';
            try {
                const saved = await this.enqueueMutation(() => this.$wire.saveDraft());
                if (saved === true) {
                    this.markDraftSaved(revision);
                    return this.saveRevision === revision;
                }
                this.clientDraftState = 'error';
                this.clientDraftError = this.$wire.get('draftError') || 'Не удалось сохранить. Повторите попытку.';
                return false;
            } catch (error) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Нет подтверждения сохранения. Проверьте соединение и повторите попытку.';
                return false;
            } finally {
                this.saveInFlight = false;
                if (this.saveRevision > revision) this.scheduleAutosave(0);
            }
        },
        markDraftSaved(revision) {
            this.persistedSaveRevision = Math.max(this.persistedSaveRevision, revision);
            this.clientDraftState = this.saveRevision === revision ? 'saved' : 'dirty';
            this.clientDraftError = '';
        },
        syncDraftState(saved, revision = this.saveRevision) {
            const serverState = this.$wire.get('draftState');
            if (saved && serverState === 'saved') {
                this.markDraftSaved(revision);
            } else if (serverState === 'error') {
                this.clientDraftState = 'error';
                this.clientDraftError = this.$wire.get('draftError') || 'Не удалось сохранить. Повторите попытку.';
            }
        },
        async startControl() {
            const revision = this.saveRevision;
            this.clientDraftState = 'saving';
            try {
                const started = await this.enqueueMutation(() => this.$wire.start());
                if (started === true) {
                    this.markDraftSaved(revision);
                } else {
                    this.clientDraftState = 'error';
                    this.clientDraftError = this.$wire.get('draftError') || 'Не удалось начать контроль. Проверьте данные.';
                }
            } catch (error) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Не удалось начать контроль. Повторите попытку.';
            }
        },
        async openReview() {
            const revision = this.saveRevision;
            this.clientDraftState = 'saving';
            try {
                const reviewed = await this.enqueueMutation(() => this.$wire.review(this.activeRoomIndex));
                if (reviewed === true) {
                    this.markDraftSaved(revision);
                } else {
                    this.clientDraftState = 'error';
                    this.clientDraftError = this.$wire.get('draftError') || 'Не удалось открыть сводку. Проверьте ответы.';
                }
            } catch (error) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Не удалось открыть сводку. Повторите попытку.';
            }
        },
        async removeSavedMedia(room, question, media) {
            if (Object.keys(this.failedComments).some(key => key.startsWith(`${room}.${question}.`))) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Сначала повторите сохранение комментария к вложению.';
                return;
            }
            const revision = this.saveRevision;
            this.clientDraftState = 'saving';
            try {
                await this.enqueueMutation(() => this.$wire.removeMedia(room, question, media));
                this.syncDraftState(this.$wire.get('draftState') === 'saved', revision);
            } catch (error) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Не удалось изменить вложение. Повторите попытку.';
            }
        },
        async savePhotoComment(room, question, media, comment, element = null, fileKey = null) {
            const revision = this.saveRevision;
            const key = `${room}.${question}.${media}`;
            this.pendingComments++;
            this.clientDraftState = 'saving';
            try {
                const saved = await this.enqueueMutation(() => this.$wire.saveMediaComment(room, question, media, comment, fileKey));
                if (!saved) throw new Error(this.$wire.get('draftError') || 'Не удалось сохранить комментарий к файлу.');
                this.syncDraftState(true, revision);
                delete this.failedComments[key];
                if (element) element.dataset.original = comment;
            } catch (error) {
                this.failedComments[key] = { room, question, media, comment, fileKey };
                this.clientDraftState = 'error';
                this.clientDraftError = error.message || 'Не удалось сохранить комментарий. Повторите попытку.';
            } finally {
                this.pendingComments--;
            }
        },
        async retryDraft() {
            const comments = Object.values(this.failedComments);
            for (const item of comments) {
                await this.savePhotoComment(item.room, item.question, item.media, item.comment, null, item.fileKey);
            }
            if (!Object.keys(this.failedComments).length) await this.flushAutosave();
        },
        goToRoom(index) {
            if (index < 0 || index >= this.roomCount) return;
            this.missingQuestion = null;
            this.activeRoomIndex = index;
            this.$nextTick(() => this.scrollToActiveRoom());
        },
        nextRoom() {
            const room = this.activeRoomIndex;
            const missing = (this.requiredQuestionsByRoom[room] || []).find(question => !this.questionCompletion[room]?.[question]);
            if (missing !== undefined) {
                this.focusQuestion({ room, question: missing });
                return;
            }
            if (this.activeRoomIndex + 1 < this.roomCount) {
                this.goToRoom(this.activeRoomIndex + 1);
                return;
            }
            this.openReview();
        },
        previousRoom() {
            if (this.activeRoomIndex > 0) {
                this.goToRoom(this.activeRoomIndex - 1);
                return;
            }
            this.enqueueMutation(() => this.$wire.backToSetup());
        },
        scrollToActiveRoom() {
            this.$refs.roomTop?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
            this.$nextTick(() => {
                const activeChip = this.$refs.roomTabs?.querySelector('[aria-current=step]');
                const tabs = this.$refs.roomTabs;
                if (activeChip && tabs) {
                    tabs.scrollLeft += activeChip.getBoundingClientRect().left - tabs.getBoundingClientRect().left
                        - (tabs.clientWidth - activeChip.offsetWidth) / 2;
                }
            });
        },
        focusQuestion(detail) {
            this.activeRoomIndex = detail.room;
            const required = this.requiredQuestionsByRoom[detail.room] || [];
            this.missingQuestion = required.includes(detail.question) && !this.questionCompletion[detail.room]?.[detail.question] ? detail.question : null;
            window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                const question = document.getElementById(`focus-question-${detail.room}-${detail.question}`);
                question?.focus({ preventScroll: true });
                question?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
            }));
        },
        openAttachments(room, question) {
            this.attachmentTarget = { room, question };
            this.$refs.attachmentSheet.showModal();
        },
        pickAttachment(event) {
            if (this.attachmentTarget) this.add(event, this.attachmentTarget.room, this.attachmentTarget.question);
            this.$refs.attachmentSheet.close();
        },
        isUploadPending(item) {
            return ['ожидание', 'загрузка', 'загружено'].includes(item.status);
        },
        add(event, room, question) {
            for (const file of Array.from(event.target.files || [])) {
                const uploadId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
                const item = { id: ++this.nextId, uploadId, file, room, question, name: file.name,
                    preview: URL.createObjectURL(file), kind: file.type.startsWith('video/') || /\.(mp4|mov|webm)$/i.test(file.name) ? 'video' : 'image',
                    status: 'ожидание', progress: 0, cancelled: false, timedOut: false, timeout: null };
                const max = Math.min(this.transportBytes, (item.kind === 'image' ? 10 : 100) * 1024 * 1024);
                if (file.size > max) {
                    const limit = new Intl.NumberFormat('ru', { maximumFractionDigits: 1 }).format(Math.floor(max / 104857.6) / 10);
                    item.error = `Файл превышает доступный лимит ${limit} МБ.`;
                    item.status = 'ошибка';
                    item.permanentError = true;
                }
                this.uploads.push(item);
                if (!item.permanentError) this.queue = this.queue.then(() => this.send(item));
            }
            event.target.value = '';
        },
        async send(item) {
            if (item.cancelled) return;
            const revision = this.saveRevision;
            item.status = 'загрузка';
            item.progress = 0;
            let serverPending = false;
            try {
                await this.enqueueMutation(() => this.$wire.beginMediaUpload());
                serverPending = true;
                if (item.cancelled) throw new Error('cancelled');
                await new Promise((resolve, reject) => {
                    let settled = false;
                    const finish = (callback, value) => {
                        if (settled) return;
                        settled = true;
                        window.clearTimeout(item.timeout);
                        callback(value);
                    };
                    item.timeout = window.setTimeout(() => {
                        item.timedOut = true;
                        this.$wire.cancelUpload('mediaUpload');
                        finish(reject, new Error('timeout'));
                    }, 780000);
                    this.$wire.upload('mediaUpload', item.file,
                        () => finish(resolve),
                        () => finish(reject, new Error('upload')),
                        event => { item.progress = event.detail?.progress ?? 0; },
                        () => finish(reject, new Error('cancelled')));
                });
                item.status = 'загружено';
                const saved = await this.enqueueMutation(() => this.$wire.attachMedia(item.room, item.question, item.uploadId));
                serverPending = false;
                this.syncDraftState(saved === true, revision);
                if (!saved) throw new Error('save');
                item.status = 'сохранено в контроле';
                URL.revokeObjectURL(item.preview);
                this.uploads = this.uploads.filter(candidate => candidate.id !== item.id);
            } catch (error) {
                window.clearTimeout(item.timeout);
                if (item.cancelled) {
                    if (serverPending) {
                        try { await this.enqueueMutation(() => this.$wire.cancelMediaUpload()); } catch (ignored) {}
                    }
                    item.status = 'отменено';
                    return;
                }
                if (item.status !== 'загружено' && serverPending) {
                    try {
                        await this.enqueueMutation(() => this.$wire.failMediaUpload());
                        this.syncDraftState(false);
                    } catch (ignored) {}
                    serverPending = false;
                }
                item.error = item.timedOut ? 'Загрузка не завершилась вовремя.' : (this.$wire.get('draftError') || 'Не удалось загрузить файл.');
                item.status = 'ошибка';
            }
        },
        retry(item) {
            item.cancelled = false;
            item.timedOut = false;
            item.error = '';
            item.status = 'ожидание';
            this.queue = this.queue.then(() => this.send(item));
        },
        cancel(item) {
            if (!['ожидание', 'загрузка'].includes(item.status)) return;
            item.cancelled = true;
            if (item.status === 'загрузка') this.$wire.cancelUpload('mediaUpload');
            else item.status = 'отменено';
        },
        dismiss(item) {
            if (item.status === 'загрузка' || item.status === 'загружено') return;
            if (item.preview) URL.revokeObjectURL(item.preview);
            this.uploads = this.uploads.filter(candidate => candidate.id !== item.id);
        },
        get busy() { return this.uploads.some(item => this.isUploadPending(item)); }
    }"
    x-on:focus-question-selected.window="focusQuestion($event.detail)">
    <dialog wire:ignore x-ref="attachmentSheet" aria-label="Добавить фото или видео"
        @click.self="$el.close()"
        class="fixed inset-x-0 bottom-0 top-auto m-0 mx-auto max-h-[85dvh] w-full max-w-[640px] overflow-y-auto rounded-t-3xl bg-white p-0 text-[#182230] shadow-xl backdrop:bg-black/50">
        <div class="grid gap-2 p-4 pb-[calc(16px+env(safe-area-inset-bottom))]">
            <h2 class="text-base font-semibold">Добавить к вопросу</h2>
            <button autofocus type="button" @click="$refs.takePhoto.click()" class="flex min-h-12 items-center gap-3 rounded-xl bg-[#EDF4FB] px-3 text-left font-medium">
                <x-heroicon-o-camera aria-hidden="true" class="size-5" /> Снять фото
            </button>
            <button type="button" @click="$refs.recordVideo.click()" class="flex min-h-12 items-center gap-3 rounded-xl bg-[#EDF4FB] px-3 text-left font-medium">
                <x-heroicon-o-video-camera aria-hidden="true" class="size-5" /> Записать видео
            </button>
            <button type="button" @click="$refs.chooseMedia.click()" class="flex min-h-12 items-center gap-3 rounded-xl bg-[#EDF4FB] px-3 text-left font-medium">
                <x-heroicon-o-photo aria-hidden="true" class="size-5" /> Выбрать из галереи
            </button>
            <button type="button" @click="$refs.attachmentSheet.close()" class="min-h-11 rounded-xl text-sm font-semibold text-[#475569]">Отмена</button>
            <input x-ref="takePhoto" type="file" accept="image/*" capture="environment" hidden @change="pickAttachment($event)">
            <input x-ref="recordVideo" type="file" accept="video/*" capture="environment" hidden @change="pickAttachment($event)">
            <input x-ref="chooseMedia" type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm" hidden @change="pickAttachment($event)">
        </div>
    </dialog>
    <div class="mx-auto w-full min-w-0 max-w-[640px] flex-1 rounded-t-[36px] bg-white px-4 pb-7 pt-4 sm:px-6">
    @if($draftSchemaConflict)
        <div role="alert" class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
            <p class="font-semibold">{{ $draftError ?: 'Схема контроля изменилась. Старый черновик сохранён без переноса ответов.' }}</p>
            <button type="button" wire:click="startNewDraft" class="mt-3 min-h-11 rounded-xl bg-amber-900 px-4 font-semibold text-white">
                Начать новую проверку
            </button>
        </div>
    @elseif(filled($draftSchemaNotice))
        <div role="status" class="mb-4 rounded-2xl bg-blue-50 p-4 text-sm text-blue-900">{{ $draftSchemaNotice }}</div>
    @endif
    @if(in_array($stage, ['setup', 'questions'], true))
        <nav wire:key="focus-tabs-{{ $stage }}" x-ref="roomTabs" class="-mx-4 flex gap-1.5 overflow-x-auto overscroll-x-contain px-4 pb-3 sm:-mx-6 sm:px-6" aria-label="Этапы контроля">
            @if($stage === 'setup')
                <span aria-current="step" class="inline-flex min-h-10 shrink-0 items-center gap-1 rounded-full px-3.5 text-sm font-semibold"
                    :class="setupComplete ? 'bg-[#DCEFE5] text-[#235D3E]' : 'bg-[#626B76] text-white'">
                    <x-heroicon-o-check x-show="setupComplete" x-cloak aria-hidden="true" class="size-4" /> Данные
                </span>
            @else
                <button type="button" x-on:click="enqueueMutation(() => $wire.backToSetup())"
                    class="inline-flex min-h-10 shrink-0 items-center gap-1 rounded-full px-3.5 text-sm font-semibold"
                    :class="setupComplete ? 'bg-[#DCEFE5] text-[#235D3E]' : 'bg-[#E9ECF0] text-[#344054]'">
                    <x-heroicon-o-check x-show="setupComplete" x-cloak aria-hidden="true" class="size-4" /> Данные
                </button>
            @endif
            @foreach($this->rooms as $roomIndex => $room)
                @php
                    $roomTitle = $room['title'] ?? 'Комната '.($roomIndex + 1);
                @endphp
                @if($stage === 'setup')
                    <span class="inline-flex min-h-10 shrink-0 items-center rounded-full px-3.5 text-sm font-semibold"
                        :class="roomProgress({{ $roomIndex }}) === 'complete' ? 'bg-[#DCEFE5] text-[#235D3E]' : (roomProgress({{ $roomIndex }}) === 'partial' ? 'bg-[#DCE8F7] text-[#284E89]' : 'bg-[#F0F1F3] text-[#596476]')">
                        {{ $roomTitle }}
                    </span>
                @else
                    <button type="button" x-on:click="goToRoom({{ $roomIndex }})"
                        :aria-current="activeRoomIndex === {{ $roomIndex }} ? 'step' : null"
                        :aria-label="@js($roomTitle) + (roomProgress({{ $roomIndex }}) === 'complete' ? ' — заполнена' : (roomProgress({{ $roomIndex }}) === 'partial' ? ' — заполнена частично' : ''))"
                        :class="[
                            roomProgress({{ $roomIndex }}) === 'complete' ? 'bg-[#DCEFE5] text-[#235D3E]' :
                                (roomProgress({{ $roomIndex }}) === 'partial' ? 'bg-[#DCE8F7] text-[#284E89]' : 'bg-[#F0F1F3] text-[#596476]'),
                            activeRoomIndex === {{ $roomIndex }} ? 'ring-2 ring-inset ring-[#285B86]' : ''
                        ]"
                        class="inline-flex min-h-10 shrink-0 items-center gap-1 rounded-full px-3.5 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#285B86]">
                        <x-heroicon-o-check x-show="roomProgress({{ $roomIndex }}) === 'complete'" x-cloak aria-hidden="true" class="size-4" />
                        {{ $roomTitle }}
                    </button>
                @endif
            @endforeach
        </nav>
    @endif

    @if($stage !== 'done')
        <div wire:key="focus-draft-status" class="mb-3">
            <p x-show="clientDraftState !== 'idle'" x-cloak class="mt-2 text-xs" :class="clientDraftState === 'error' ? 'text-[#B42318]' : 'text-[#8A97A8]'" role="status" aria-live="polite">
                <span x-show="pendingComments === 0 && clientDraftState === 'dirty'">Изменения не сохранены</span>
                <span x-show="pendingComments > 0 || clientDraftState === 'saving'">Сохранение выполняется…</span>
                <span x-show="pendingComments === 0 && clientDraftState === 'saved'">Изменения сохранены</span>
                <span x-show="pendingComments === 0 && clientDraftState === 'error'" x-text="clientDraftError"></span>
            </p>
            <button x-show="clientDraftState === 'error' || Object.keys(failedComments).length > 0" type="button" x-on:click="retryDraft()" class="mt-2 text-xs font-semibold text-[#285B86]">Повторить сохранение</button>
        </div>
    @endif

    @if($stage === 'setup')
        <section wire:key="focus-setup" class="space-y-3.5">
            <div>
                <h1 class="text-[26px] font-bold leading-tight tracking-tight">Данные проверки</h1>
                <p class="mt-1 text-sm text-[#596476]">Выбери квартиру, сотрудника и даты.</p>
            </div>
            <x-controls.setup-select label="Кто убирался" :options="$peopleOptions" model="cleanerId" field="cleaner_id"
                placeholder="Выберите сотрудника" icon="heroicon-o-user" />

            <fieldset>
                <legend class="text-sm font-semibold">Закреплён за квартирой? <span aria-hidden="true" class="text-[#285B86]">*</span></legend>
                <div class="mt-1.5 grid grid-cols-2 gap-2.5">
                    <button type="button" :aria-pressed="isAssigned ? 'true' : 'false'" x-on:click="isAssigned = true; scheduleAutosave()"
                        :class="isAssigned ? 'bg-[#DCE8F7] text-[#234E79] ring-1 ring-[#AFC8E6]' : 'bg-[#EAEBED] text-[#344054]'"
                        class="min-h-12 rounded-full px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-[#285B86]">Да</button>
                    <button type="button" :aria-pressed="isAssigned ? 'false' : 'true'" x-on:click="isAssigned = false; scheduleAutosave()"
                        :class="!isAssigned ? 'bg-[#DCE8F7] text-[#234E79] ring-1 ring-[#AFC8E6]' : 'bg-[#EAEBED] text-[#344054]'"
                        class="min-h-12 rounded-full px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-[#285B86]">Нет</button>
                </div>
            </fieldset>

            <x-controls.setup-select label="Квартира" :options="$apartmentOptions" model="apartmentId" field="apartment_id"
                placeholder="Выберите квартиру" icon="heroicon-o-home-modern" />

            <div class="grid grid-cols-1 gap-2.5 min-[420px]:grid-cols-2">
                <label class="block min-w-0 text-sm font-semibold">Дата уборки <span aria-hidden="true" class="text-[#285B86]">*</span>
                    <input type="date" x-model="cleaningDate" x-on:change="scheduleAutosave()" aria-required="true" @error('cleaning_date') aria-invalid="true" @enderror
                        class="mt-1.5 min-h-12 w-full min-w-0 rounded-full border-0 bg-[#EAEBED] px-3 text-base font-normal text-[#20262D] focus:ring-2 focus:ring-[#285B86]">
                    @error('cleaning_date') <span class="mt-1.5 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
                </label>
                <label class="block min-w-0 text-sm font-semibold">Дата проверки <span aria-hidden="true" class="text-[#285B86]">*</span>
                    <input type="date" x-model="inspectionDate" x-on:change="scheduleAutosave()" aria-required="true" @error('inspection_date') aria-invalid="true" @enderror
                        class="mt-1.5 min-h-12 w-full min-w-0 rounded-full border-0 bg-[#EAEBED] px-3 text-base font-normal text-[#20262D] focus:ring-2 focus:ring-[#285B86]">
                    @error('inspection_date') <span class="mt-1.5 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
                </label>
            </div>

            <x-controls.setup-select label="Кто убирался до этого" :options="$peopleOptions" model="previousCleaner" field="previous_cleaner"
                placeholder="Найдите сотрудника или введите имя" icon="heroicon-o-user" :free-text="true" />
            <button type="button" x-on:click="startControl()" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-[#285B86] px-4 text-base font-semibold text-white">
                {{ $editingReviewData ? 'Вернуться к проверке' : 'К вопросам' }} <x-heroicon-o-arrow-right class="size-4" />
            </button>
        </section>
    @elseif($stage === 'questions')
        <div wire:key="focus-room-pages" class="space-y-3">
            @foreach($this->rooms as $roomIndex => $room)
                @php $requiredCount = count($this->requiredQuestionsByRoom[$roomIndex] ?? []); @endphp
                <template x-if="activeRoomIndex === {{ $roomIndex }}">
                    <section x-ref="roomTop" data-focus-room-page class="min-w-0 scroll-mt-4 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <h1 class="min-w-0 break-words text-[26px] font-bold leading-tight text-[#18283D]">{{ $room['title'] ?? 'Комната '.($roomIndex + 1) }}</h1>
                            @if($requiredCount > 0)
                                <span class="shrink-0 rounded-full bg-[#EDF4FB] px-2.5 py-1 text-xs font-semibold tabular-nums text-[#285B86]"
                                    :aria-label="roomAnsweredCount({{ $roomIndex }}) + ' из {{ $requiredCount }} обязательных вопросов заполнено'"
                                    x-text="roomAnsweredCount({{ $roomIndex }}) + ' из {{ $requiredCount }}'"></span>
                            @endif
                        </div>

                        @foreach(($room['items'] ?? []) as $questionIndex => $question)
                            @php
                                $media = $this->savedMedia[$roomIndex][$questionIndex] ?? [];
                                $type = $question['answer_type'] ?? 'options';
                                $options = $question['answer_options_scored'] ?? [];
                                $optional = (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false));
                                $answerPath = 'answers.'.$roomIndex.'.'.$questionIndex;
                            @endphp
                            <article id="focus-question-{{ $roomIndex }}-{{ $questionIndex }}" wire:key="focus-question-{{ $roomIndex }}-{{ $questionIndex }}" tabindex="-1"
                                aria-labelledby="focus-question-label-{{ $roomIndex }}-{{ $questionIndex }}"
                                x-data="{ selected: @entangle($answerPath . '.selected'), custom: @entangle($answerPath . '.custom') }"
                                :class="missingQuestion === {{ $questionIndex }} ? 'border-[#EBA39E] bg-[#FFF9F8]' : 'border-[#E4EAF0] bg-white'"
                                class="scroll-mt-4 rounded-2xl border p-3 focus:outline-none sm:p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 id="focus-question-label-{{ $roomIndex }}-{{ $questionIndex }}" class="min-w-0 text-base font-semibold leading-snug text-[#18283D]">{{ $question['question'] ?? 'Вопрос' }}</h3>
                                    @if($optional) <span class="shrink-0 pt-0.5 text-[11px] text-[#64748B]">Необязательно</span> @endif
                                </div>
                                @if(filled($question['question_image'] ?? null))
                                    <img src="{{ Storage::disk('public')->url($question['question_image']) }}" alt="" loading="lazy" class="mt-3 max-h-40 w-full rounded-xl object-contain">
                                @endif
                                @error("answers.$roomIndex.$questionIndex") <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p> @enderror
                                <p x-show="missingQuestion === {{ $questionIndex }}" x-cloak role="alert" class="mt-2 text-sm font-medium text-[#B42318]">Ответь на обязательный вопрос.</p>

                                @if(in_array($type, ['options', 'both'], true))
                                    <div class="mt-3 grid auto-rows-fr gap-2">
                                        @foreach($options as $optionIndex => $option)
                                            @php
                                                $value = (string) ($option['value'] ?? $option['label'] ?? 'option_'.$optionIndex);
                                            @endphp
                                            <button type="button" x-on:click="if (selected !== @js($value)) { selected = @js($value); updateQuestionCompletion({{ $roomIndex }}, {{ $questionIndex }}, selected, custom); scheduleAutosave() }" :aria-pressed="selected === @js($value)"
                                                :class="selected === @js($value) ? 'border-[#9DBCD6] bg-[#EDF4FB] text-[#194C75]' : 'border-[#E4EAF0] bg-[#F5F7F9] text-[#263B51] hover:bg-[#EEF2F5]'"
                                                class="flex min-h-12 w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-[#285B86]">
                                                <span aria-hidden="true" :class="selected === @js($value) ? 'border-[#285B86] bg-[#285B86] text-white' : 'border-[#A6B4C2] bg-white'" class="grid size-5 shrink-0 place-items-center rounded-full border">
                                                    <span x-show="selected === @js($value)" x-cloak><x-heroicon-o-check class="size-3" /></span>
                                                </span>
                                                <span class="min-w-0 break-words">{{ $option['label'] ?? $value }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                @if(in_array($type, ['text', 'both'], true))
                                    <label class="mt-3 block text-xs font-semibold text-[#475569]">{{ $type === 'both' ? ($question['custom_answer_label'] ?? 'Комментарий к ответу') : 'Ответ' }}
                                        <textarea x-model="custom" x-on:input="updateQuestionCompletion({{ $roomIndex }}, {{ $questionIndex }}, selected, $event.target.value); scheduleAutosave(900)" maxlength="2000" rows="2"
                                            class="mt-1.5 w-full rounded-xl border-[#CBD5E1] text-base" placeholder="Введите ответ"></textarea>
                                    </label>
                                @endif

                                <div class="mt-3">
                                    <button type="button" aria-haspopup="dialog"
                                        @click="openAttachments({{ $roomIndex }}, {{ $questionIndex }})"
                                        class="flex min-h-12 w-full min-w-0 items-center gap-3 rounded-xl border border-[#C9DAEA] bg-[#EDF4FB] px-3 py-2.5 text-left text-sm font-semibold text-[#285B86] focus-visible:ring-2 focus-visible:ring-[#285B86]">
                                        <x-heroicon-o-paper-clip aria-hidden="true" class="size-5 shrink-0" />
                                        <span class="min-w-0 flex-1">Добавить фото или видео</span>
                                        @if(count($media)) <span class="shrink-0 text-xs tabular-nums">{{ count($media) }}/12</span> @endif
                                    </button>
                                    <template x-if="uploads.some(file => file.room === {{ $roomIndex }} && file.question === {{ $questionIndex }} && isUploadPending(file))">
                                        <p class="mt-1.5 text-xs font-semibold text-[#8A6116]" role="status">Загружаем файлы · можно продолжать проверку</p>
                                    </template>
                                    <p class="mt-1.5 text-xs text-[#596476]">Фото до 10 МБ · видео до 100 МБ.
                                        @if(FocusMediaLimits::transportBytes() < FocusMediaLimits::VIDEO_KIB * 1024)
                                            На этом сервере до {{ number_format(floor(FocusMediaLimits::transportBytes() / 104857.6) / 10, 1, ',', ' ') }} МБ на файл.
                                        @endif
                                    </p>
                                    @error('mediaUpload') <p class="mt-2 text-xs text-[#B42318]">{{ $message }}</p> @enderror
                                    <div class="mt-2 grid grid-cols-2 gap-2 min-[390px]:grid-cols-3" aria-live="polite">
                                        @foreach($media as $mediaIndex => $file)
                                            <div class="min-w-0 overflow-hidden rounded-xl bg-[#F6F8FB] p-1" wire:key="focus-media-{{ $roomIndex }}-{{ $questionIndex }}-{{ $mediaIndex }}">
                                                @if(str_starts_with((string) ($file['mime'] ?? ''), 'video/'))
                                                    <video src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId]) }}" controls preload="none" class="aspect-square w-full rounded-lg object-cover"></video>
                                                @else
                                                    <img src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId, 'thumbnail' => 1]) }}" alt="Вложение к вопросу" loading="lazy" class="aspect-square w-full rounded-lg object-cover">
                                                @endif
                                                <div class="flex items-center justify-between gap-1 px-1 py-1 text-[10px]">
                                                    <span class="font-semibold text-[#16834B]">Сохранено</span>
                                                    <button type="button" x-on:click="removeSavedMedia({{ $roomIndex }}, {{ $questionIndex }}, {{ $mediaIndex }})" class="font-semibold text-[#B42318]">Удалить</button>
                                                </div>
                                                <textarea
                                                    rows="2"
                                                    maxlength="2000"
                                                    aria-label="Комментарий к вложению {{ $mediaIndex + 1 }}"
                                                    placeholder="Комментарий к фото или видео"
                                                    x-on:focus="if (!$el.dataset.originalSet) { $el.dataset.original = $el.defaultValue; $el.dataset.originalSet = '1' }"
                                                    x-on:input="clientDraftState = 'dirty'"
                                                    x-on:blur="if ($el.value !== $el.dataset.original) savePhotoComment({{ $roomIndex }}, {{ $questionIndex }}, {{ $mediaIndex }}, $el.value, $el, @js(hash('sha256', (string) ($file['path'] ?? ''))))"
                                                    class="mt-1 block min-h-12 w-full resize-y rounded-lg border border-[#D7DEE6] bg-white px-2 py-1.5 text-xs leading-5 text-[#263238] placeholder:text-[#7A858B] focus:border-[#285B86] focus:ring-2 focus:ring-[#285B86]"
                                                >{{ $file['comment'] ?? '' }}</textarea>
                                            </div>
                                        @endforeach
                                        <template x-for="item in uploads.filter(file => file.room === {{ $roomIndex }} && file.question === {{ $questionIndex }})" :key="item.id">
                                            <div class="min-w-0 overflow-hidden rounded-xl bg-[#F6F8FB] p-1">
                                                <div class="relative overflow-hidden rounded-lg bg-[#E7EBEF]">
                                                    <img x-show="item.kind === 'image'" :src="item.preview" alt="" class="aspect-square w-full object-cover">
                                                    <video x-show="item.kind === 'video'" :src="item.preview" muted preload="metadata" class="aspect-square w-full object-cover"></video>
                                                    <template x-if="item.status === 'загрузка'">
                                                        <div class="absolute inset-0 grid place-items-center bg-black/35">
                                                            <div class="relative grid size-12 place-items-center rounded-full bg-black/65 text-white"
                                                                role="progressbar" aria-label="Прогресс загрузки файла" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="item.progress">
                                                                <svg viewBox="0 0 44 44" aria-hidden="true" class="absolute inset-0 size-full -rotate-90 text-white">
                                                                    <circle cx="22" cy="22" r="19" stroke="currentColor" stroke-width="2" fill="none" opacity=".3"/>
                                                                    <circle cx="22" cy="22" r="19" stroke="currentColor" stroke-width="2" fill="none" pathLength="100"
                                                                        stroke-linecap="round" :stroke-dasharray="item.progress + ' 100'"/>
                                                                </svg>
                                                                <span class="text-xs font-semibold tabular-nums" x-text="item.progress + '%'"></span>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>
                                                <p class="truncate px-1 pt-1 text-xs font-medium" x-text="item.name"></p>
                                                <p class="px-1 text-xs" :class="item.status === 'ошибка' ? 'text-[#B42318]' : 'text-[#596476]'" x-text="item.error || item.status"></p>
                                                <div class="flex gap-2 px-1 text-[10px] font-semibold">
                                                    <button x-show="item.status === 'ожидание' || item.status === 'загрузка'" type="button" @click="cancel(item)" class="text-[#475569]">Отменить</button>
                                                    <button x-show="!item.permanentError && (item.status === 'ошибка' || item.status === 'отменено')" type="button" @click="retry(item)" class="text-[#285B86]">Повторить</button>
                                                    <button x-show="item.status === 'ошибка' || item.status === 'отменено'" type="button" @click="dismiss(item)" class="text-[#B42318]">Удалить</button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </section>
                </template>
            @endforeach
        </div>
        <div wire:key="focus-room-actions" class="sticky bottom-0 z-10 -mx-4 mt-3 grid sm:-mx-6 sm:px-6 grid-cols-2 gap-2 border-t border-[#EEF1F4] bg-white/95 px-4 pt-2.5 pb-[calc(12px+env(safe-area-inset-bottom))] backdrop-blur-sm">
            <button type="button" x-on:click="previousRoom()" class="min-h-11 rounded-xl bg-[#F1F5F9] px-3 text-sm font-semibold">← Назад</button>
            <button type="button" x-on:click="nextRoom()" class="min-h-11 rounded-xl bg-[#285B86] px-3 text-sm font-semibold text-white">
                <span x-show="activeRoomIndex + 1 !== roomCount">Дальше →</span>
                <span x-show="activeRoomIndex + 1 === roomCount">Проверить ответы</span>
            </button>
        </div>
    @elseif($stage === 'review')
        <section wire:key="focus-review" class="min-w-0 pb-5">
            <h1 class="text-[26px] font-bold leading-tight tracking-tight">Проверка перед отправкой</h1>
            <p class="mt-2 text-sm text-[#64748B]">{{ $this->completedCount }} ответов из {{ count($this->questions) }}. Проверьте данные и незаполненные вопросы.</p>
            <div class="mt-4 space-y-1 rounded-2xl bg-[#F8FAFC] p-4 text-sm">
                <p><strong>Сотрудник:</strong> {{ collect($peopleOptions)->firstWhere('id', $cleaner_id)['name'] ?? 'Не выбран' }}</p>
                <p><strong>Квартира:</strong> {{ collect($apartmentOptions)->firstWhere('id', $apartment_id)['name'] ?? 'Не выбрана' }}</p>
                <p><strong>Уборка:</strong> {{ $cleaning_date ?: 'Не указана' }}</p>
                <p><strong>Проверка:</strong> {{ $inspection_date ?: 'Не указана' }}</p>
                <button type="button" wire:click="backToSetup" class="mt-2 font-semibold text-[#285B86]">Изменить данные</button>
            </div>
            <label class="mt-4 block text-sm font-semibold">Резюме контроля <span class="font-normal text-[#596476]">· необязательно</span>
                <textarea x-model="controlComment" x-on:input="scheduleAutosave(900)" maxlength="2000" rows="3"
                    class="mt-1.5 w-full rounded-[22px] border-0 bg-[#F8FAFC] px-4 py-3 text-base font-normal text-[#20262D] focus:ring-2 focus:ring-[#285B86]"></textarea>
            </label>
            @error('comment') <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p> @enderror
            @if(collect($this->savedMedia)->flatten(2)->isNotEmpty())
                <section class="mt-4 rounded-2xl bg-[#F8FAFC] p-3">
                    <h3 class="mb-2 text-sm font-semibold">Фото и видео с комментариями</h3>
                    <div class="space-y-3">
                        @foreach($this->rooms as $roomIndex => $room)
                            @foreach(($room['items'] ?? []) as $questionIndex => $question)
                                @php $reviewMedia = $this->savedMedia[$roomIndex][$questionIndex] ?? []; @endphp
                                @if($reviewMedia !== [])
                                    <div class="min-w-0">
                                        <p class="mb-1 text-xs font-semibold text-[#475569]">{{ $room['title'] ?? 'Комната '.($roomIndex + 1) }} · {{ $question['question'] ?? 'Вопрос' }}</p>
                                        <div class="grid grid-cols-2 gap-2 min-[390px]:grid-cols-3">
                                            @foreach($reviewMedia as $mediaIndex => $file)
                                                <figure class="min-w-0 overflow-hidden rounded-xl bg-white">
                                                    @if(str_starts_with((string) ($file['mime'] ?? ''), 'video/'))
                                                        <video src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId]) }}" controls preload="none" class="aspect-square w-full object-cover"></video>
                                                    @else
                                                        <img src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId, 'thumbnail' => 1]) }}" alt="Фото к вопросу" loading="lazy" decoding="async" class="aspect-square w-full object-cover">
                                                    @endif
                                                    @if(filled($file['comment'] ?? null))
                                                        <figcaption class="whitespace-pre-wrap break-words px-2 py-1.5 text-xs leading-5 text-[#263238]">{{ $file['comment'] }}</figcaption>
                                                    @endif
                                                </figure>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        @endforeach
                    </div>
                </section>
            @endif
            @if(count($this->incompleteQuestions))
                <h3 class="mt-5 font-bold text-[#B42318]">Незаполненные обязательные вопросы</h3>
                <div class="mt-2 space-y-2">
                    @foreach($this->incompleteQuestions as $item)
                <button type="button" wire:click="goToQuestion({{ $item['room_index'] }}, {{ $item['question_index'] }})" class="block min-h-11 w-full rounded-xl bg-[#FEF3F2] px-3 py-2 text-left text-sm text-[#B42318]">
                            {{ $item['room_title'] }} · {{ $item['question']['question'] ?? 'Вопрос' }} →
                        </button>
                    @endforeach
                </div>
            @endif
            @if($pendingMediaCount > 0)
                <p class="mt-4 rounded-xl bg-[#FFFAEB] p-3 text-sm text-[#8A6116]">Есть незавершённые загрузки. Дождитесь их сохранения.</p>
            @else
                <p x-show="busy" x-cloak class="mt-4 rounded-xl bg-[#FFFAEB] p-3 text-sm text-[#8A6116]">Есть незавершённые загрузки. Дождитесь их сохранения.</p>
            @endif
            @error('mediaUpload') <p class="mt-3 text-sm text-[#B42318]">{{ $message }}</p> @enderror
            @error('submit') <p class="mt-3 text-sm text-[#B42318]">{{ $message }}</p> @enderror
            @if($errors->any()) <p class="mt-3 text-sm text-[#B42318]">Проверьте отмеченные поля и вопросы.</p> @endif
            <div class="mt-6 grid grid-cols-1 gap-3 min-[360px]:grid-cols-2">
                <button type="button" wire:click="previous" class="min-h-12 rounded-2xl bg-[#F1F5F9] px-3 font-semibold">Назад</button>
                <button type="button" wire:click="submit" wire:loading.attr="disabled" :disabled="busy || pendingComments > 0 || Object.keys(failedComments).length > 0 || ['dirty', 'saving', 'error'].includes(clientDraftState) || @js($isSubmitting || $pendingMediaCount > 0 || count($this->incompleteQuestions) > 0)"
                    class="min-h-12 rounded-2xl bg-[#285B86] px-3 font-semibold text-white disabled:opacity-50">
                        <span wire:loading.remove wire:target="submit">Отправить контроль</span>
                        <span wire:loading wire:target="submit">Отправляем контроль…</span>
                </button>
            </div>
        </section>
    @elseif($stage === 'done')
        <section wire:key="focus-done" class="rounded-3xl bg-[#E7F8EF] p-6 text-center">
            <h1 class="text-xl font-bold text-[#16834B]">Контроль отправлен</h1>
            <p class="mt-2 text-sm">{{ $successMessage }}</p>
            @if($submittedResponseId)
                <a href="{{ route('page-profile.checks.result', $submittedResponseId) }}" class="mt-5 inline-flex min-h-11 items-center rounded-full bg-[#285B86] px-5 font-semibold text-white">Открыть результат</a>
            @endif
            <a href="{{ route('page-profile.checks', ['tab' => 'conducted']) }}" class="mt-2 inline-flex min-h-11 items-center px-4 font-semibold text-[#285B86]">Мои контроли</a>
        </section>
    @endif

    </div>
</div>
</div>
