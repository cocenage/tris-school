<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Services\Controls\ControlRunManager;
use App\Services\Controls\ControlSchema;
use App\Services\RolePreviewContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FocusV2DraftController extends Controller
{
    /**
     * Persist a complete answer snapshot with optimistic draft revision checks.
     * Media metadata is always taken from the database, never from the browser.
     */
    public function __invoke(Request $request, RolePreviewContext $preview, ControlRunManager $runs): JsonResponse
    {
        $preview->assertWritable();
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, ['admin', 'supervisor'], true), 403);

        $input = $request->validate([
            'control_id' => ['required', 'integer', 'min:1'],
            'run_id' => ['required', 'uuid'],
            'schema_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'draft_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'setup' => ['required', 'array'],
            'setup.cleaner_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')
                ->where('is_active', true)->where('status', 'approved')->whereIn('role', ['cleaner', 'supervisor'])],
            'setup.apartment_id' => ['present', 'nullable', 'integer', Rule::exists('apartments', 'id')->where('is_active', true)],
            'setup.is_assigned' => ['required', 'boolean'],
            'setup.previous_cleaner' => ['present', 'nullable', 'string', 'max:255'],
            'setup.cleaning_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'setup.inspection_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'setup.comment' => ['present', 'nullable', 'string', 'max:2000'],
            'answers' => ['required', 'array'],
        ]);

        $setup = $input['setup'];
        if (($setup['cleaning_date'] ?? null) && ($setup['inspection_date'] ?? null)
            && $setup['inspection_date'] < $setup['cleaning_date']) {
            throw ValidationException::withMessages(['setup.inspection_date' => 'Проверка не может быть раньше уборки.']);
        }

        $control = Control::query()->where('is_active', true)->latest()->firstOrFail();
        $rooms = is_array($control->main) ? array_values($control->main) : [];
        $schema = new ControlSchema((int) $control->id, $rooms);
        $schemaVersion = $schema->version();
        if ((int) $input['control_id'] !== (int) $control->id
            || ! hash_equals($schemaVersion, (string) $input['schema_version'])) {
            return $this->conflict('schema_changed', 'Шаблон контроля изменился. Обновите данные.');
        }

        $runId = (string) $input['run_id'];
        if (ControlResponse::query()->where('submission_token', $runId)->exists()) {
            return $this->conflict('already_submitted', 'Этот контроль уже завершён.');
        }

        $owned = ControlResponseDraft::query()
            ->where('control_id', $control->id)
            ->where('supervisor_id', $user->getKey())
            ->where('state', 'active');
        $draftId = $input['draft_id'] ?? null;
        $draft = $draftId !== null
            ? (clone $owned)->whereKey($draftId)->first()
            : (clone $owned)->where('run_id', $runId)->first();

        if ($draftId !== null && $draft === null) {
            return $this->conflict('draft_not_found', 'Черновик не найден или принадлежит другой проверке.');
        }
        if ($draft && $draft->run_id !== $runId) {
            return $this->conflict('run_mismatch', 'Черновик принадлежит другому запуску.');
        }
        if ($draft && is_string($draft->schema_version) && ! hash_equals($schemaVersion, $draft->schema_version)) {
            return $this->conflict('schema_changed', 'У сохранённого черновика другая версия схемы.');
        }
        if ($draft && $draft->schema_snapshot !== null
            && ! hash_equals($schemaVersion, (new ControlSchema((int) $control->id, $draft->schema_snapshot))->version())) {
            return $this->conflict('schema_changed', 'Схема сохранённого черновика устарела.');
        }
        if (! $draft && $draftId === null && (clone $owned)->exists()) {
            return $this->conflict('active_draft_exists', 'Уже существует активный черновик. Восстановите его перед продолжением.');
        }

        $answers = $this->canonicalAnswers($schema, $rooms, $input['answers'], $draft?->responses ?? []);
        $errors = $schema->validateAnswers($answers);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $attributes = [
            'cleaner_id' => $setup['cleaner_id'] ?? null,
            'apartment_id' => $setup['apartment_id'] ?? null,
            'is_assigned' => (bool) $setup['is_assigned'],
            'previous_cleaner' => (string) ($setup['previous_cleaner'] ?? ''),
            'cleaning_date' => $setup['cleaning_date'] ?? null,
            'inspection_date' => $setup['inspection_date'] ?? null,
            'comment' => (string) ($setup['comment'] ?? ''),
            'responses' => $schema->attachQuestionIds($answers),
            'schema_snapshot' => $rooms,
            'schema_version' => $schemaVersion,
        ];

        $expectedRevision = (int) $input['expected_revision'];
        // An acknowledgement may be lost. An exact retry of the last saved
        // snapshot must return the same revision without writing it twice.
        if ($draft && (int) $draft->revision === $expectedRevision + 1
            && $this->isIdenticalSnapshot($draft, $attributes)) {
            return $this->success($draft, $runId, true);
        }
        if ($draft && (int) $draft->revision !== $expectedRevision) {
            return $this->conflict('revision_conflict', 'Черновик изменился в другой вкладке.', $draft);
        }
        if (! $draft && $expectedRevision !== 0) {
            return $this->conflict('revision_conflict', 'Неизвестная версия черновика.');
        }

        try {
            $saved = $runs->saveDraft(
                controlId: (int) $control->id,
                supervisorId: (int) $user->getKey(),
                runId: $runId,
                expectedRevision: $expectedRevision,
                draftId: $draft?->id,
                schemaVersion: $schemaVersion,
                attributes: $attributes,
            );
        } catch (ValidationException) {
            // Includes a concurrent request that won the optimistic revision lock.
            return $this->conflict('revision_conflict', 'Версия черновика изменилась. Загрузите актуальные данные.');
        }

        return $this->success($saved, $runId);
    }

    private function canonicalAnswers(ControlSchema $schema, array $rooms, array $submitted, array $stored): array
    {
        if (! array_is_list($submitted) || count($submitted) !== count($rooms)) {
            throw ValidationException::withMessages(['answers' => 'Структура комнат не совпадает с шаблоном.']);
        }

        $answers = [];
        foreach ($rooms as $roomIndex => $room) {
            $items = array_values((array) ($room['items'] ?? []));
            $provided = $submitted[$roomIndex] ?? null;
            if (! is_array($provided) || ! array_is_list($provided) || count($provided) !== count($items)) {
                throw ValidationException::withMessages(['answers' => 'Структура вопросов не совпадает с шаблоном.']);
            }
            foreach ($items as $questionIndex => $question) {
                $answer = $provided[$questionIndex] ?? null;
                if (! is_array($answer) || array_key_exists('media', $answer)
                    || ! is_string($answer['selected'] ?? '') || ! is_string($answer['custom'] ?? '')
                    || ! is_array($answer['corrective'] ?? [])) {
                    throw ValidationException::withMessages([
                        "answers.$roomIndex.$questionIndex" => 'Некорректный формат ответа или запрещённое изменение медиа.',
                    ]);
                }
                $selected = trim((string) ($answer['selected'] ?? ''));
                $custom = trim((string) ($answer['custom'] ?? ''));
                if (mb_strlen($selected) > 255 || mb_strlen($custom) > 2000) {
                    throw ValidationException::withMessages(["answers.$roomIndex.$questionIndex" => 'Превышен лимит длины ответа.']);
                }

                $corrective = $answer['corrective'] ?? [];
                $negative = ControlResponse::isNegativeAnswer($question, $selected);
                $answers[$roomIndex][$questionIndex] = [
                    'selected' => $selected,
                    'custom' => $custom,
                    'media' => array_values((array) ($stored[$roomIndex][$questionIndex]['media'] ?? [])),
                    'corrective' => $negative ? [
                        'repeats' => $corrective['repeats'] ?? null,
                        'action' => trim((string) ($corrective['action'] ?? '')),
                        'recheck' => $corrective['recheck'] ?? null,
                    ] : ['repeats' => null, 'action' => '', 'recheck' => null],
                ];
            }
        }

        return $answers;
    }

    private function isIdenticalSnapshot(ControlResponseDraft $draft, array $attributes): bool
    {
        foreach (['cleaner_id', 'apartment_id'] as $key) {
            if (($draft->{$key} === null ? null : (int) $draft->{$key})
                !== ($attributes[$key] === null ? null : (int) $attributes[$key])) {
                return false;
            }
        }

        return (bool) $draft->is_assigned === (bool) $attributes['is_assigned']
            && (string) $draft->previous_cleaner === $attributes['previous_cleaner']
            && $draft->cleaning_date?->toDateString() === $attributes['cleaning_date']
            && $draft->inspection_date?->toDateString() === $attributes['inspection_date']
            && (string) $draft->comment === $attributes['comment']
            && $draft->schema_version === $attributes['schema_version']
            && $draft->responses === $attributes['responses'];
    }

    private function success(ControlResponseDraft $draft, string $runId, bool $replayed = false): JsonResponse
    {
        return response()->json([
            'run' => [
                'id' => $runId,
                'draft_id' => $draft->id,
                'revision' => (int) $draft->revision,
                'schema_version' => $draft->schema_version,
            ],
            'saved_at' => $draft->updated_at?->toIso8601String(),
            'replayed' => $replayed,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function conflict(string $code, string $message, ?ControlResponseDraft $draft = null): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
            'current_revision' => $draft?->revision,
        ], 409)->header('Cache-Control', 'private, no-store');
    }
}
