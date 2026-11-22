<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\FocusV2MediaUpload;
use App\Services\Controls\ControlCompletionNotificationService;
use App\Services\Controls\ControlRunManager;
use App\Services\Controls\ControlSchema;
use App\Services\RolePreviewContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FocusV2SubmitController extends Controller
{
    /**
     * Finalize only the persisted, revision-checked server snapshot.
     * The client never sends score, answers or file paths to this endpoint.
     */
    public function __invoke(
        Request $request,
        RolePreviewContext $preview,
        ControlRunManager $runs,
        ControlCompletionNotificationService $notifications,
    ): JsonResponse {
        $preview->assertWritable();
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, ['admin', 'supervisor'], true), 403);

        $input = $request->validate([
            'control_id' => ['required', 'integer', 'min:1'],
            'run_id' => ['required', 'uuid'],
            'schema_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'draft_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        // A response might have committed while the connection to the user's
        // phone failed. Return the same result; never create a second one.
        $existing = ControlResponse::query()->where('submission_token', $input['run_id'])->first();
        if ($existing) {
            return $this->submittedResponse($existing, $input, (int) $user->getKey(), true);
        }

        $lock = Cache::lock('control-submit:'.$input['run_id'], 30);
        if (! $lock->get()) {
            return response()->json([
                'error' => 'submission_in_progress',
                'message' => 'Проверка уже отправляется. Повторите запрос.',
            ], 409)->header('Cache-Control', 'private, no-store');
        }

        try {
            return DB::transaction(function () use ($input, $user, $runs, $notifications): JsonResponse {
                $existing = ControlResponse::query()->where('submission_token', $input['run_id'])->first();
                if ($existing) {
                    return $this->submittedResponse($existing, $input, (int) $user->getKey(), true);
                }

                $control = Control::query()->where('is_active', true)->latest()->firstOrFail();
                abort_unless((int) $input['control_id'] === (int) $control->getKey(), 409, 'Шаблон контроля изменился.');

                $rooms = is_array($control->main) ? array_values($control->main) : [];
                $schema = new ControlSchema((int) $control->getKey(), $rooms);
                abort_unless(hash_equals($schema->version(), $input['schema_version']), 409, 'Версия шаблона изменилась.');

                $draft = ControlResponseDraft::query()
                    ->whereKey((int) $input['draft_id'])
                    ->where('control_id', $control->getKey())
                    ->where('supervisor_id', $user->getKey())
                    ->where('run_id', $input['run_id'])
                    ->where('state', 'active')
                    ->lockForUpdate()
                    ->first();
                abort_unless($draft, 409, 'Черновик не найден. Возможно, контроль уже завершён.');
                abort_unless((int) $draft->revision === (int) $input['expected_revision'], 409, 'Черновик изменился. Обновите ответы перед отправкой.');
                abort_unless(is_string($draft->schema_version)
                    && hash_equals($schema->version(), $draft->schema_version)
                    && is_array($draft->schema_snapshot)
                    && hash_equals($schema->version(), (new ControlSchema((int) $control->getKey(), $draft->schema_snapshot))->version()),
                    409, 'Версия черновика не соответствует шаблону.');

                $answers = is_array($draft->responses) ? $draft->responses : [];
                $errors = $schema->validateAnswers($answers, requireComplete: true);
                foreach ($schema->questionIds() as $roomIndex => $questions) {
                    foreach ($questions as $questionIndex => $questionId) {
                        $storedId = $answers[$roomIndex][$questionIndex]['question_id'] ?? null;
                        // Older drafts did not store question IDs. Match the
                        // existing Focus restore rule: validate any ID present,
                        // then attach canonical IDs before final submission.
                        if ($storedId !== null && $storedId !== $questionId) {
                            $errors["answers.$roomIndex.$questionIndex"] = 'Ответ относится к другой версии вопроса.';
                        }
                    }
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $answers = $schema->attachQuestionIds($answers);

                $this->validateSetup($draft);
                $this->validateMedia($draft, $rooms);

                // Holding the draft row lock here also serializes upload start
                // and attachment operations, which lock the same row.
                $pending = FocusV2MediaUpload::query()
                    ->where('draft_id', $draft->getKey())
                    ->whereIn('status', ['pending', 'ready'])
                    ->exists();
                if ($pending) {
                    throw ValidationException::withMessages([
                        'media' => 'Есть файлы, загрузка или прикрепление которых не завершены. Дождитесь их или отмените.',
                    ]);
                }

                $score = ControlResponse::calculateScores($rooms, $answers);
                $response = $runs->submitOnce(
                    controlId: (int) $control->getKey(),
                    supervisorId: (int) $user->getKey(),
                    runId: $input['run_id'],
                    schemaVersion: $schema->version(),
                    expectedRevision: (int) $draft->revision,
                    draftId: (int) $draft->getKey(),
                    attributes: [
                        'cleaner_id' => $draft->cleaner_id,
                        'apartment_id' => $draft->apartment_id,
                        'is_assigned' => $draft->is_assigned,
                        'previous_cleaner' => trim((string) $draft->previous_cleaner),
                        'cleaning_date' => $draft->cleaning_date,
                        'inspection_date' => $draft->inspection_date,
                        'comment' => trim((string) $draft->comment),
                        'responses' => $answers,
                        'schema_snapshot' => $rooms,
                        'schema_version' => $schema->version(),
                        ...$score,
                        'status' => ControlResponse::STATUS_SENT,
                        'sent_at' => now(),
                    ],
                    afterCreate: function (ControlResponse $response) use ($notifications, $user, $control, $draft, $score): void {
                        $notifications->queueFor($response);

                        activity()->causedBy($user)->performedOn($response)->event('control_completed')
                            ->withProperties([
                                'control_id' => $control->getKey(),
                                'control_name' => $control->name,
                                'cleaner_id' => $draft->cleaner_id,
                                'apartment_id' => $draft->apartment_id,
                                'cleaning_date' => $draft->cleaning_date?->toDateString(),
                                'inspection_date' => $draft->inspection_date?->toDateString(),
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

                return $this->submittedResponse($response, $input, (int) $user->getKey(), false);
            });
        } finally {
            $lock->release();
        }
    }

    private function validateSetup(ControlResponseDraft $draft): void
    {
        Validator::make([
            'cleaner_id' => $draft->cleaner_id,
            'apartment_id' => $draft->apartment_id,
            'cleaning_date' => $draft->cleaning_date?->toDateString(),
            'inspection_date' => $draft->inspection_date?->toDateString(),
            'previous_cleaner' => $draft->previous_cleaner,
            'comment' => $draft->comment,
        ], [
            'cleaner_id' => ['required', 'integer', Rule::exists('users', 'id')
                ->where('is_active', true)->where('status', 'approved')->whereIn('role', ['cleaner', 'supervisor'])],
            'apartment_id' => ['required', 'integer', Rule::exists('apartments', 'id')->where('is_active', true)],
            'cleaning_date' => ['required', 'date_format:Y-m-d'],
            'inspection_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:cleaning_date'],
            'previous_cleaner' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ])->validate();
    }

    private function validateMedia(ControlResponseDraft $draft, array $rooms): void
    {
        $errors = [];

        foreach ($rooms as $roomIndex => $room) {
            foreach (array_values((array) ($room['items'] ?? [])) as $questionIndex => $question) {
                $files = $draft->responses[$roomIndex][$questionIndex]['media'] ?? [];
                if (! is_array($files) || count($files) > 12) {
                    $errors["answers.$roomIndex.$questionIndex"] = 'Максимум 12 файлов на вопрос.';
                    continue;
                }

                foreach ($files as $file) {
                    $disk = is_array($file) ? ($file['disk'] ?? null) : null;
                    $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
                    if (! in_array($disk, ['local', 'public'], true)
                        || ! str_starts_with($path, 'controls/'.$draft->control_id.'/')
                        || str_contains($path, '..') || str_contains($path, '\\')
                        || ! Storage::disk($disk)->exists($path)) {
                        $errors["answers.$roomIndex.$questionIndex"] = 'Не найден прикреплённый файл. Проверьте медиа.';
                        break;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function submittedResponse(ControlResponse $response, array $input, int $userId, bool $replayed): JsonResponse
    {
        abort_unless((int) $response->supervisor_id === $userId
            && (int) $response->control_id === (int) $input['control_id']
            && is_string($response->schema_version)
            && hash_equals($response->schema_version, (string) $input['schema_version'])
            && $response->submission_token === $input['run_id'], 409, 'Идентификатор проверки уже используется.');

        return response()->json([
            'submitted' => true,
            'replayed' => $replayed,
            'response_id' => $response->getKey(),
            'result_url' => route('page-profile.checks.result', $response->getKey()),
            'total_points' => $response->total_points,
            'max_points' => $response->max_points,
            'score_percent' => $response->score_percent,
            'penalty_points' => $response->penalty_points,
            'errors_count' => $response->errors_count,
            'result_zone' => $response->result_zone,
            'result_zone_reason' => $response->result_zone_reason,
        ])->header('Cache-Control', 'private, no-store');
    }
}
