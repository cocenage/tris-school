<?php

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\User;
use App\Services\Controls\ControlRunManager;
use App\Services\Controls\ControlCompletionNotificationService;
use App\Services\Controls\ControlSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

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
            ->orderBy('name')->get(['id', 'name'])->toArray();
        $this->apartmentOptions = Apartment::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->toArray();
    }

    protected function ensureAuthorized(): void
    {
        abort_unless(Auth::check() && in_array(Auth::user()?->role, ['admin', 'supervisor'], true), 403);
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
        $this->initializeAnswers();
        $this->draftState = 'dirty';
        $this->saveDraft(true);
    }
    public function start(): bool
    {
        $this->ensureAuthorized();
        if ($this->draftSchemaConflict || ! $this->saveDraft(true)) {
            return false;
        }

        $this->currentRoomIndex = 0;
        $this->stage = 'questions';

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

    public function beginMediaUpload(): void
    {
        $this->ensureAuthorized();
        $this->pendingMediaCount++;
        $this->draftState = 'dirty';
    }

    public function failMediaUpload(): void
    {
        $this->pendingMediaCount = max(0, $this->pendingMediaCount - 1);
        $this->addError('mediaUpload', 'Файл не загружен. Попробуйте ещё раз.');
        $this->draftState = 'error';
        $this->draftError = 'Файл не сохранён. Повторите загрузку.';
    }

    public function cancelMediaUpload(): void
    {
        $this->ensureAuthorized();
        $this->pendingMediaCount = max(0, $this->pendingMediaCount - 1);
        $this->resetErrorBag('mediaUpload');
        $this->draftState = 'dirty';
        $this->draftError = '';
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
        $imageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        $videoMimes = ['video/mp4', 'video/quicktime', 'video/webm'];
        $isImage = in_array($mime, $imageMimes, true);
        $isVideo = in_array($mime, $videoMimes, true);
        $size = $file->getSize();
        $mediaCount = count($this->savedMedia[$roomIndex][$questionIndex] ?? []);

        if ((! $isImage && ! $isVideo) || $size > ($isImage ? 10 : 12) * 1024 * 1024 || $mediaCount >= 12) {
            $this->addError('mediaUpload', 'Допустимо до 12 файлов: фото до 10 МБ, видео до 12 МБ при текущем лимите загрузки (JPEG, PNG, WebP, HEIC, MP4, MOV, WebM).');
            $this->draftState = 'error';
            $this->draftError = 'Файл не сохранён. Проверьте формат, размер и число файлов.';

            return false;
        }

        $path = null;
        try {
            $path = $file->store('controls/'.$this->control->id.'/'.now()->format('Y/m'), 'local');
            $media = $this->savedMedia[$roomIndex][$questionIndex] ?? [];
            $media[] = [
                'disk' => 'local', 'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 180),
                'mime' => $mime, 'size' => $size, 'uploaded_at' => now()->toDateTimeString(),
                ...($uploadId !== null ? ['upload_id' => $uploadId] : []),
            ];
            $this->setSavedMediaForQuestion($roomIndex, $questionIndex, $media);
            $this->mediaUpload = null;
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

    public function saveMediaComment(int $roomIndex, int $questionIndex, int $mediaIndex, string $comment): void
    {
        $this->ensureAuthorized();
        if ($this->submitted || $this->stage === 'done') {
            return;
        }

        $media = $this->savedMedia[$roomIndex][$questionIndex] ?? null;
        if (! is_array($media) || ! is_array($media[$mediaIndex] ?? null)) {
            return;
        }

        $comment = mb_substr(trim($comment), 0, 2000);
        if ($comment === trim((string) ($media[$mediaIndex]['comment'] ?? ''))) {
            return;
        }

        if ($comment === '') {
            unset($media[$mediaIndex]['comment']);
        } else {
            $media[$mediaIndex]['comment'] = $comment;
        }

        $this->setSavedMediaForQuestion($roomIndex, $questionIndex, array_values($media));
        if (! $this->saveDraft(true)) {
            $this->clearSavedMediaOverride($roomIndex, $questionIndex);

            return;
        }

        $this->clearSavedMediaOverride($roomIndex, $questionIndex);
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

        if (! $this->cleaner_id || ! User::query()->activeStaff()->whereIn('role', ['cleaner', 'supervisor'])->whereKey($this->cleaner_id)->exists()) {
            $this->addError('cleaner_id', 'Выберите сотрудника.');
        }
        if (! $this->apartment_id || ! Apartment::query()->where('is_active', true)->whereKey($this->apartment_id)->exists()) {
            $this->addError('apartment_id', 'Выберите квартиру.');
        }
        if (! $this->cleaning_date || ! strtotime($this->cleaning_date)) {
            $this->addError('cleaning_date', 'Укажите дату уборки.');
        }
        if (! $this->inspection_date || ! strtotime($this->inspection_date)
            || ($this->cleaning_date && strtotime($this->inspection_date) < strtotime($this->cleaning_date))) {
            $this->addError('inspection_date', 'Дата проверки не может быть раньше уборки.');
        }
        if (mb_strlen($this->previous_cleaner) > 255 || mb_strlen($this->comment) > 2000) {
            $this->addError('comment', 'Проверьте длину комментария и предыдущего сотрудника.');
        }

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
    public function review(?int $roomIndex = null): void
    {
        $this->ensureAuthorized();
        if ($this->draftSchemaConflict || ! $this->saveDraft(true)) {
            return;
        }

        if ($roomIndex !== null && isset($this->rooms[$roomIndex])) {
            $this->currentRoomIndex = $roomIndex;
        }

        $this->stage = 'review';
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
        <a href="{{ route('page-checks') }}" class="rounded-full px-3 py-2 text-sm font-semibold text-[#285B86]">← Контроли</a>
        <span class="truncate text-sm font-semibold text-[#111827]">{{ $control?->name ?? 'Контроль качества' }}</span>
    </div>
</x-slot:header>

<div class="mx-auto flex min-h-full w-full max-w-[620px] flex-col bg-white px-4 pb-8 pt-5 text-[#111827]"
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
        clientDraftState: @js($draftState),
        clientDraftError: @js($draftError),
        saveTimer: null, saveInFlight: false, saveRevision: 0, persistedSaveRevision: 0,
        mutationQueue: Promise.resolve(),
        roomCount: {{ count($this->rooms) }},
        totalQuestions: {{ count($this->questions) }},
        uploads: [], queue: Promise.resolve(), nextId: 0,
        enqueueMutation(action) {
            const request = this.mutationQueue.catch(() => {}).then(action);
            this.mutationQueue = request.catch(() => {});
            return request;
        },
        updateQuestionCompletion(room, question, selected, custom) {
            this.questionCompletion[room] ||= [];
            this.questionCompletion[room][question] = String(selected ?? '').trim() !== '' || String(custom ?? '').trim() !== '';
        },
        get completedCount() {
            return Object.values(this.questionCompletion).reduce((count, questions) =>
                count + Object.values(questions || {}).filter(Boolean).length, 0);
        },
        get completedPercent() {
            return this.totalQuestions ? Math.round(this.completedCount / this.totalQuestions * 100) : 0;
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
                const serverState = this.$wire.get('draftState');
                if (saved === true && serverState === 'saved') {
                    this.persistedSaveRevision = revision;
                    this.clientDraftState = this.saveRevision === revision ? 'saved' : 'dirty';
                    this.clientDraftError = '';
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
        syncDraftState(saved, revision = this.saveRevision) {
            const serverState = this.$wire.get('draftState');
            if (saved && serverState === 'saved') {
                this.persistedSaveRevision = Math.max(this.persistedSaveRevision, revision);
                this.clientDraftState = this.saveRevision === revision ? 'saved' : 'dirty';
                this.clientDraftError = '';
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
                this.syncDraftState(started === true, revision);
                if (!started) {
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
                await this.enqueueMutation(() => this.$wire.review(this.activeRoomIndex));
                const reviewed = this.$wire.get('stage') === 'review';
                this.syncDraftState(reviewed, revision);
                if (!reviewed && this.clientDraftState !== 'error') {
                    this.clientDraftState = 'error';
                    this.clientDraftError = this.$wire.get('draftError') || 'Не удалось открыть сводку. Проверьте ответы.';
                }
            } catch (error) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Не удалось открыть сводку. Повторите попытку.';
            }
        },
        async removeSavedMedia(room, question, media) {
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
        async savePhotoComment(room, question, media, comment, element = null) {
            const revision = this.saveRevision;
            this.clientDraftState = 'saving';
            try {
                await this.enqueueMutation(() => this.$wire.saveMediaComment(room, question, media, comment));
                this.syncDraftState(this.$wire.get('draftState') === 'saved', revision);
                if (element) element.dataset.original = comment;
            } catch (error) {
                this.clientDraftState = 'error';
                this.clientDraftError = 'Не удалось сохранить комментарий. Повторите попытку.';
            }
        },
        goToRoom(index) {
            if (index < 0 || index >= this.roomCount) return;
            this.activeRoomIndex = index;
            this.$nextTick(() => this.scrollToActiveRoom());
        },
        nextRoom() {
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
            const room = this.$refs.roomTop;
            if (!room) return;
            const top = room.getBoundingClientRect().top + window.scrollY - 72;
            window.scrollTo({ top: Math.max(0, top), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        },
        focusQuestion(detail) {
            this.activeRoomIndex = detail.room;
            window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
                const question = document.getElementById(`focus-question-${detail.room}-${detail.question}`);
                if (!question) return;
                const top = question.getBoundingClientRect().top + window.scrollY - 72;
                window.scrollTo({ top: Math.max(0, top), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            }));
        },
        add(event, room, question) {
            for (const file of Array.from(event.target.files || [])) {
                const uploadId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
                const item = { id: ++this.nextId, uploadId, file, room, question, name: file.name,
                    preview: URL.createObjectURL(file), kind: file.type.startsWith('video/') ? 'video' : 'image',
                    status: 'ожидание', progress: 0, cancelled: false, timedOut: false, timeout: null };
                this.uploads.push(item);
                this.queue = this.queue.then(() => this.enqueueMutation(() => this.send(item)));
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
                await this.$wire.beginMediaUpload();
                serverPending = true;
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
                    }, 90000);
                    this.$wire.upload('mediaUpload', item.file,
                        () => finish(resolve),
                        () => finish(reject, new Error('upload')),
                        event => { item.progress = event.detail?.progress ?? 0; },
                        () => finish(reject, new Error('cancelled')));
                });
                item.status = 'загружено';
                const saved = await this.$wire.attachMedia(item.room, item.question, item.uploadId);
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
                        try { await this.$wire.cancelMediaUpload(); } catch (ignored) {}
                    }
                    item.status = 'отменено';
                    return;
                }
                if (item.status !== 'загружено' && serverPending) {
                    try {
                        await this.$wire.failMediaUpload();
                        this.syncDraftState(false);
                    } catch (ignored) {}
                    serverPending = false;
                }
                item.error = item.timedOut ? 'Загрузка не завершилась вовремя.' : 'Не удалось загрузить файл.';
                item.status = 'ошибка';
            }
        },
        retry(item) {
            item.cancelled = false;
            item.timedOut = false;
            item.error = '';
            item.status = 'ожидание';
            this.queue = this.queue.then(() => this.enqueueMutation(() => this.send(item)));
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
        get busy() { return this.uploads.some(item => ['ожидание', 'загрузка', 'загружено'].includes(item.status)); }
    }"
    x-on:focus-question-selected.window="focusQuestion($event.detail)">
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
    <div class="mb-4 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="mt-1 truncate text-[22px] font-bold leading-tight">{{ $stage === 'review' ? 'Проверка контроля' : ($control?->name ?? 'Контроль качества') }}</h1>
        </div>
        @if($stage === 'setup')
            <a href="{{ route('page-checks.control') }}" class="shrink-0 rounded-full bg-[#F1F5F9] px-3 py-2 text-xs font-semibold text-[#285B86]">Classic</a>
        @endif
    </div>

    @if($stage !== 'done')
        <div class="mb-5">
            <div class="flex justify-between text-xs font-semibold text-[#475569]">
                @if($stage === 'questions')
                    <span x-text="`Комната ${activeRoomIndex + 1} из ${roomCount}`"></span>
                    <span>Ответов <span x-text="completedCount"></span> из {{ count($this->questions) }}</span>
                @else
                    <span>Отвечено <span x-text="completedCount"></span> из {{ count($this->questions) }}</span>
                    <span x-text="`${completedPercent}%`"></span>
                @endif
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[#E8EDF3]">
                @if($stage === 'questions')
                    <div class="h-full rounded-full bg-[#285B86] transition-[width] duration-200" :style="`width: ${roomCount ? ((activeRoomIndex + 1) / roomCount) * 100 : 0}%`"></div>
                @else
                    <div class="h-full rounded-full bg-[#285B86] transition-[width] duration-200" :style="`width: ${completedPercent}%`"></div>
                @endif
            </div>
            @if($stage === 'questions')
                <nav class="-mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="Переход между комнатами">
                    @foreach($this->rooms as $roomIndex => $room)
                        <button type="button"
                            x-on:click="goToRoom({{ $roomIndex }})"
                            :aria-current="activeRoomIndex === {{ $roomIndex }} ? 'step' : null"
                            :class="activeRoomIndex === {{ $roomIndex }} ? 'border-[#285B86] bg-[#EAF2F8] text-[#194C75]' : 'border-[#E2E8F0] bg-white text-[#475569]'"
                            aria-label="{{ $room['title'] ?? 'Комната '.($roomIndex + 1) }}"
                            class="flex min-h-10 min-w-10 shrink-0 items-center justify-center gap-1 rounded-full border px-3 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#285B86]">
                            {{ $roomIndex + 1 }}
                            <span x-show="Object.values(questionCompletion[{{ $roomIndex }}] || {}).some(Boolean)" aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-[#16834B]"></span>
                        </button>
                    @endforeach
                </nav>
            @endif
            <p x-show="busy" class="mt-2 text-xs font-semibold text-[#8A6116]" role="status">Загружаем файлы · можно продолжать вопросы</p>
            <p x-show="!busy" class="mt-2 text-xs" :class="clientDraftState === 'error' ? 'text-[#B42318]' : 'text-[#8A97A8]'" role="status" aria-live="polite">
                <span x-show="clientDraftState === 'dirty'">Изменения не сохранены</span>
                <span x-show="clientDraftState === 'saving'">Сохранение выполняется…</span>
                <span x-show="clientDraftState === 'saved'">Изменения сохранены</span>
                <span x-show="clientDraftState === 'idle'">Черновик ещё не сохранён</span>
                <span x-show="clientDraftState === 'error'" x-text="clientDraftError"></span>
            </p>
            <button x-show="clientDraftState === 'error'" type="button" x-on:click="flushAutosave()" class="mt-2 text-xs font-semibold text-[#285B86]">Повторить сохранение</button>
        </div>
    @endif

    @if($stage === 'setup')
        <section class="space-y-4 rounded-3xl bg-[#F7F9FB] p-4">
            <div>
                <h2 class="text-lg font-bold">Перед началом</h2>
                <p class="mt-1 text-sm text-[#64748B]">Выберите сотрудника, квартиру и даты. Вопросы откроются на следующем экране.</p>
            </div>
            <label class="block text-sm font-semibold">Сотрудник
                <select x-model="cleanerId" x-on:change="scheduleAutosave()" class="mt-2 w-full rounded-2xl border-[#CBD5E1] text-base">
                    <option value="">Выберите сотрудника</option>
                    @foreach($peopleOptions as $person)
                        <option value="{{ $person['id'] }}">{{ $person['name'] }}</option>
                    @endforeach
                </select>
                @error('cleaner_id') <span class="mt-1 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
            </label>
            <label class="block text-sm font-semibold">Квартира
                <select x-model="apartmentId" x-on:change="scheduleAutosave()" class="mt-2 w-full rounded-2xl border-[#CBD5E1] text-base">
                    <option value="">Выберите квартиру</option>
                    @foreach($apartmentOptions as $apartment)
                        <option value="{{ $apartment['id'] }}">{{ $apartment['name'] }}</option>
                    @endforeach
                </select>
                @error('apartment_id') <span class="mt-1 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
            </label>
            <div class="grid grid-cols-1 gap-4 min-[360px]:grid-cols-2">
                <label class="block text-sm font-semibold">Дата уборки
                    <input type="date" x-model="cleaningDate" x-on:change="scheduleAutosave()" class="mt-2 w-full rounded-2xl border-[#CBD5E1] text-base">
                    @error('cleaning_date') <span class="mt-1 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-semibold">Дата проверки
                    <input type="date" x-model="inspectionDate" x-on:change="scheduleAutosave()" class="mt-2 w-full rounded-2xl border-[#CBD5E1] text-base">
                    @error('inspection_date') <span class="mt-1 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
                </label>
            </div>
            <label class="flex items-center gap-3 text-sm font-semibold"><input type="checkbox" x-model="isAssigned" x-on:change="scheduleAutosave()" class="rounded border-[#CBD5E1]"> Уборка назначена</label>
            <label class="block text-sm font-semibold">Предыдущий сотрудник <span class="font-normal text-[#64748B]">· необязательно</span>
                <input type="text" x-model="previousCleaner" x-on:input="scheduleAutosave(900)" maxlength="255" class="mt-2 w-full rounded-2xl border-[#CBD5E1] text-base">
            </label>
            <label class="block text-sm font-semibold">Комментарий <span class="font-normal text-[#64748B]">· необязательно</span>
                <textarea x-model="controlComment" x-on:input="scheduleAutosave(900)" maxlength="2000" rows="3" class="mt-2 w-full rounded-2xl border-[#CBD5E1] text-base"></textarea>
            </label>
            <button type="button" x-on:click="startControl()" class="min-h-12 w-full rounded-2xl bg-[#285B86] px-4 text-base font-semibold text-white">К вопросам →</button>
        </section>
    @elseif($stage === 'questions')
        <div class="space-y-3">
            @foreach($this->rooms as $roomIndex => $room)
                <template x-if="activeRoomIndex === {{ $roomIndex }}">
                    <section x-ref="roomTop" data-focus-room-page class="min-w-0">
                        <div class="mb-2">
                            <p class="text-xs font-semibold text-[#64748B]">Комната {{ $roomIndex + 1 }} из {{ count($this->rooms) }}</p>
                            <h2 class="mt-1 text-xl font-bold leading-tight text-[#18283D]">{{ $room['title'] ?? 'Комната '.($roomIndex + 1) }}</h2>
                            <p class="mt-1 truncate text-xs text-[#64748B]">{{ collect($apartmentOptions)->firstWhere('id', $apartment_id)['name'] ?? 'Квартира не выбрана' }}</p>
                        </div>

                        @foreach(($room['items'] ?? []) as $questionIndex => $question)
                            @php
                                $answer = $answers[$roomIndex][$questionIndex] ?? [];
                                $media = $this->savedMedia[$roomIndex][$questionIndex] ?? [];
                                $type = $question['answer_type'] ?? 'options';
                                $options = $question['answer_options_scored'] ?? [];
                                $optional = (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false));
                                $binaryOptions = count($options) === 2 && collect($options)
                                    ->pluck('label')->map(fn ($label) => mb_strtolower(trim((string) $label)))
                                    ->sort()->values()->all() === ['да', 'нет'];
                            @endphp
                            <article id="focus-question-{{ $roomIndex }}-{{ $questionIndex }}" wire:key="focus-question-{{ $roomIndex }}-{{ $questionIndex }}" class="border-b border-[#EEF1F4] py-4 last:border-b-0">
                                <div class="flex items-start justify-between gap-3">
                                    <h3 class="min-w-0 text-base font-semibold leading-snug text-[#18283D]">{{ $question['question'] ?? 'Вопрос' }}</h3>
                                    @if($optional) <span class="shrink-0 pt-0.5 text-[11px] text-[#64748B]">Необязательно</span> @endif
                                </div>
                                @if(filled($question['question_image'] ?? null))
                                    <img src="{{ Storage::disk('public')->url($question['question_image']) }}" alt="" loading="lazy" class="mt-3 max-h-40 w-full rounded-xl object-contain">
                                @endif
                                @error("answers.$roomIndex.$questionIndex") <p class="mt-2 text-sm text-[#B42318]">{{ $message }}</p> @enderror

                                @if(in_array($type, ['options', 'both'], true))
                                    <div class="mt-3 grid grid-cols-1 gap-2" x-data="{ selected: @entangle('answers.' . $roomIndex . '.' . $questionIndex . '.selected') }">
                                        @foreach(($question['answer_options_scored'] ?? []) as $optionIndex => $option)
                                            @php
                                                $value = (string) ($option['value'] ?? $option['label'] ?? 'option_'.$optionIndex);
                                                $isPositiveBinaryOption = $binaryOptions && mb_strtolower(trim((string) ($option['label'] ?? ''))) === 'да';
                                                $isNegativeBinaryOption = $binaryOptions && mb_strtolower(trim((string) ($option['label'] ?? ''))) === 'нет';
                                            @endphp
                                                <button type="button" x-on:click="if (selected !== @js($value)) { selected = @js($value); scheduleAutosave() }" :aria-pressed="selected === @js($value)"
                                                :class="selected === @js($value) ? (@js($isPositiveBinaryOption) ? 'border-[#B8E3C8] bg-[#EAF7EE] text-[#176B3A] ring-2 ring-[#2F9E5B]/10' : (@js($isNegativeBinaryOption) ? 'border-[#F3C5C3] bg-[#FFF1F0] text-[#A63832] ring-2 ring-[#D4544D]/10' : 'border-[#D7E2EC] bg-[#F1F6FA] text-[#194C75] ring-2 ring-[#285B86]/10')) : 'border-transparent bg-[#F5F7F9] text-[#263B51] hover:bg-[#EEF2F5]'"
                                                class="flex {{ $binaryOptions ? 'min-h-14 text-base' : 'min-h-12 text-sm' }} w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left font-semibold transition-colors">
                                                @if($binaryOptions)
                                                    <span aria-hidden="true" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/80 text-lg">{{ $isPositiveBinaryOption ? '✓' : '×' }}</span>
                                                @endif
                                                <span class="min-w-0 break-words">{{ $option['label'] ?? $value }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                @if(in_array($type, ['text', 'both'], true))
                                    <label class="mt-3 block text-xs font-semibold text-[#475569]">{{ $type === 'both' ? ($question['custom_answer_label'] ?? 'Комментарий к ответу') : 'Ответ' }}
                                        <textarea x-data="{ custom: @entangle('answers.' . $roomIndex . '.' . $questionIndex . '.custom') }" x-model="custom" x-on:input="scheduleAutosave(900)" maxlength="2000" rows="2"
                                            class="mt-1.5 w-full rounded-xl border-[#CBD5E1] text-base" placeholder="Введите ответ"></textarea>
                                    </label>
                                @endif

                                <div class="mt-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <h4 class="text-sm font-semibold">Фото и видео</h4>
                                        <span class="text-[11px] text-[#8A97A8]">{{ count($media) }} / 12</span>
                                    </div>
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <label class="flex min-h-10 cursor-pointer items-center justify-center gap-2 rounded-xl bg-[#EEF5FC] px-3 text-xs font-semibold text-[#285B86]">
                                            Фото
                                            <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif" class="sr-only" x-on:change="add($event, {{ $roomIndex }}, {{ $questionIndex }})">
                                        </label>
                                        <label class="flex min-h-10 cursor-pointer items-center justify-center gap-2 rounded-xl bg-[#EEF5FC] px-3 text-xs font-semibold text-[#285B86]">
                                            Видео
                                            <input type="file" multiple accept="video/mp4,video/quicktime,video/webm" class="sr-only" x-on:change="add($event, {{ $roomIndex }}, {{ $questionIndex }})">
                                        </label>
                                    </div>
                                    <p class="mt-1.5 text-[11px] text-[#8A97A8]">Фото до 10 МБ · видео до 12 МБ.</p>
                                    @error('mediaUpload') <p class="mt-2 text-xs text-[#B42318]">{{ $message }}</p> @enderror
                                    <div class="mt-2 grid grid-cols-2 gap-2 min-[390px]:grid-cols-3" aria-live="polite">
                                        @foreach($media as $mediaIndex => $file)
                                            <div class="min-w-0 overflow-hidden rounded-xl bg-[#F6F8FB] p-1" wire:key="focus-media-{{ $roomIndex }}-{{ $questionIndex }}-{{ $mediaIndex }}">
                                                @if(str_starts_with((string) ($file['mime'] ?? ''), 'video/'))
                                                    <video src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId]) }}" controls preload="metadata" class="h-20 w-full rounded-lg object-cover"></video>
                                                @else
                                                    <img src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId]) }}" alt="Вложение к вопросу" loading="lazy" class="h-20 w-full rounded-lg object-cover">
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
                                                    x-on:focus="$el.dataset.original = $el.value"
                                                    x-on:input="clientDraftState = 'dirty'"
                                                    x-on:blur="if ($el.value !== $el.dataset.original) savePhotoComment({{ $roomIndex }}, {{ $questionIndex }}, {{ $mediaIndex }}, $el.value, $el)"
                                                    class="mt-1 block min-h-12 w-full resize-y rounded-lg border border-[#D7DEE6] bg-white px-2 py-1.5 text-xs leading-5 text-[#263238] placeholder:text-[#7A858B] focus:border-[#285B86] focus:ring-2 focus:ring-[#285B86]"
                                                >{{ $file['comment'] ?? '' }}</textarea>
                                            </div>
                                        @endforeach
                                        <template x-for="item in uploads.filter(file => file.room === {{ $roomIndex }} && file.question === {{ $questionIndex }})" :key="item.id">
                                            <div class="min-w-0 overflow-hidden rounded-xl bg-[#F6F8FB] p-1">
                                                <img x-show="item.kind === 'image'" :src="item.preview" alt="Локальное превью" class="h-20 w-full rounded-lg object-cover">
                                                <video x-show="item.kind === 'video'" :src="item.preview" muted preload="metadata" class="h-20 w-full rounded-lg object-cover"></video>
                                                <p class="truncate px-1 pt-1 text-[10px] font-medium" x-text="item.name"></p>
                                                <p class="px-1 text-[10px]" :class="item.status === 'ошибка' ? 'text-[#B42318]' : 'text-[#64748B]'" x-text="item.error || (item.status === 'загрузка' ? 'Загрузка · ' + item.progress + '%' : item.status)"></p>
                                                <progress x-show="item.status === 'загрузка'" :value="item.progress" max="100" class="mt-1 w-full"></progress>
                                                <div class="flex gap-2 px-1 text-[10px] font-semibold">
                                                    <button x-show="item.status === 'ожидание' || item.status === 'загрузка'" type="button" @click="cancel(item)" class="text-[#475569]">Отменить</button>
                                                    <button x-show="item.status === 'ошибка' || item.status === 'отменено'" type="button" @click="retry(item)" class="text-[#285B86]">Повторить</button>
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
        <div class="sticky bottom-0 z-10 -mx-4 mt-3 grid grid-cols-2 gap-2 border-t border-[#EEF1F4] bg-white/95 px-4 pt-2.5 pb-[calc(12px+env(safe-area-inset-bottom))] backdrop-blur-sm">
            <button type="button" x-on:click="previousRoom()" class="min-h-11 rounded-xl bg-[#F1F5F9] px-3 text-sm font-semibold">← Назад</button>
            <button type="button" x-on:click="nextRoom()" class="min-h-11 rounded-xl bg-[#285B86] px-3 text-sm font-semibold text-white">
                <span x-show="activeRoomIndex + 1 !== roomCount">Далее →</span>
                <span x-show="activeRoomIndex + 1 === roomCount">Завершить контроль</span>
            </button>
        </div>
    @elseif($stage === 'review')
        <section class="min-w-0 pb-5">
            <h2 class="text-xl font-bold">Проверка перед отправкой</h2>
            <p class="mt-2 text-sm text-[#64748B]">{{ $this->completedCount }} ответов из {{ count($this->questions) }}. Проверьте данные и незаполненные вопросы.</p>
            <div class="mt-4 space-y-1 rounded-2xl bg-[#F8FAFC] p-4 text-sm">
                <p><strong>Сотрудник:</strong> {{ collect($peopleOptions)->firstWhere('id', $cleaner_id)['name'] ?? 'Не выбран' }}</p>
                <p><strong>Квартира:</strong> {{ collect($apartmentOptions)->firstWhere('id', $apartment_id)['name'] ?? 'Не выбрана' }}</p>
                <p><strong>Уборка:</strong> {{ $cleaning_date ?: 'Не указана' }}</p>
                <p><strong>Проверка:</strong> {{ $inspection_date ?: 'Не указана' }}</p>
                <button type="button" wire:click="$set('stage', 'setup')" class="mt-2 font-semibold text-[#285B86]">Изменить данные</button>
            </div>
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
                                                        <video src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId]) }}" controls preload="metadata" class="aspect-[4/3] w-full object-cover"></video>
                                                    @else
                                                        <img src="{{ route('page-checks.control.draft-media', [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draftId]) }}" alt="Фото к вопросу" loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover">
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
                <button type="button" wire:click="submit" wire:loading.attr="disabled" :disabled="busy || ['dirty', 'saving', 'error'].includes(clientDraftState) || {{ $isSubmitting || $pendingMediaCount > 0 ? 'true' : 'false' }}"
                    class="min-h-12 rounded-2xl bg-[#285B86] px-3 font-semibold text-white disabled:opacity-50">Отправить контроль</button>
            </div>
        </section>
    @elseif($stage === 'done')
        <section class="rounded-3xl bg-[#E7F8EF] p-6 text-center">
            <h2 class="text-xl font-bold text-[#16834B]">Контроль отправлен</h2>
            <p class="mt-2 text-sm">{{ $successMessage }}</p>
            <a href="{{ route('page-checks') }}" class="mt-5 inline-flex min-h-11 items-center rounded-2xl bg-white px-5 font-semibold text-[#285B86]">К контролям</a>
        </section>
    @endif

</div>
