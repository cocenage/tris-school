<?php

namespace App\Services\Controls;

use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ControlRunManager
{
    public function saveDraft(
        int $controlId,
        int $supervisorId,
        string $runId,
        int $expectedRevision,
        ?int $draftId,
        string $schemaVersion,
        array $attributes,
    ): ControlResponseDraft {
        $this->assertRunIdentity($runId, $schemaVersion);

        try {
            return DB::transaction(function () use (
                $controlId,
                $supervisorId,
                $runId,
                $expectedRevision,
                $draftId,
                $schemaVersion,
                $attributes,
            ): ControlResponseDraft {
                // Let the unique run_id index arbitrate concurrent first saves.
                $draft = $draftId === null
                    ? null
                    : ControlResponseDraft::query()
                        ->where('control_id', $controlId)
                        ->where('supervisor_id', $supervisorId)
                        ->whereKey($draftId)
                        ->lockForUpdate()
                        ->first();

                if ($draft) {
                    if (
                        $draft->state !== 'active'
                        || ($draft->run_id !== null && $draft->run_id !== $runId)
                        || ($draft->schema_version !== null && ! hash_equals($schemaVersion, $draft->schema_version))
                        || (int) $draft->revision !== $expectedRevision
                    ) {
                        throw ValidationException::withMessages([
                            'draft' => 'Черновик уже обновлён в другой вкладке. Перезагрузите контроль перед продолжением.',
                        ]);
                    }

                    $draft->fill($attributes);
                    $draft->run_id = $runId;
                    $draft->schema_version = $schemaVersion;
                    $draft->revision = $expectedRevision + 1;
                    $draft->state = 'active';
                    $draft->save();

                    return $draft->refresh();
                }

                if ($draftId !== null || $expectedRevision !== 0) {
                    throw ValidationException::withMessages([
                        'draft' => 'Черновик не найден или его версия устарела. Перезагрузите контроль перед продолжением.',
                    ]);
                }

                return ControlResponseDraft::create([
                    ...$attributes,
                    'control_id' => $controlId,
                    'supervisor_id' => $supervisorId,
                    'run_id' => $runId,
                    'schema_version' => $schemaVersion,
                    'revision' => 1,
                    'state' => 'active',
                ]);
            });
        } catch (QueryException $exception) {
            if (ControlResponseDraft::query()->where('run_id', $runId)->exists()) {
                throw ValidationException::withMessages([
                    'draft' => 'Черновик уже сохранён более новой версией. Перезагрузите контроль перед продолжением.',
                ]);
            }

            throw $exception;
        }
    }

    public function markSchemaConflict(int $draftId, int $controlId, int $supervisorId): void
    {
        DB::transaction(function () use ($draftId, $controlId, $supervisorId): void {
            $draft = ControlResponseDraft::query()
                ->whereKey($draftId)
                ->where('control_id', $controlId)
                ->where('supervisor_id', $supervisorId)
                ->lockForUpdate()
                ->first();

            if ($draft && $draft->state === 'active') {
                $draft->state = 'conflict';
                $draft->save();
            }
        });
    }

    /**
     * The unique database index on submission_token is the final idempotency
     * boundary. The cache lock can reduce contention, but is not relied on.
     */
    public function submitOnce(
        int $controlId,
        int $supervisorId,
        string $runId,
        string $schemaVersion,
        int $expectedRevision,
        ?int $draftId,
        array $attributes,
        Closure $afterCreate,
    ): ControlResponse {
        $this->assertRunIdentity($runId, $schemaVersion);

        try {
            return DB::transaction(function () use (
                $controlId,
                $supervisorId,
                $runId,
                $schemaVersion,
                $expectedRevision,
                $draftId,
                $attributes,
                $afterCreate,
            ): ControlResponse {
                $existing = ControlResponse::query()->where('submission_token', $runId)->first();
                if ($existing) {
                    $this->assertSubmissionOwner($existing, $controlId, $supervisorId, $schemaVersion);

                    return $existing;
                }

                if ($draftId !== null) {
                    $draft = ControlResponseDraft::query()
                        ->whereKey($draftId)
                        ->where('control_id', $controlId)
                        ->where('supervisor_id', $supervisorId)
                        ->where('state', 'active')
                        ->lockForUpdate()
                        ->first();

                    if (! $draft || (int) $draft->revision !== $expectedRevision
                        || ($draft->run_id !== null && $draft->run_id !== $runId)
                        || ($draft->schema_version !== null && ! hash_equals($schemaVersion, $draft->schema_version))) {
                        throw ValidationException::withMessages([
                            'draft' => 'Черновик изменился. Перезагрузите контроль перед отправкой.',
                        ]);
                    }
                }

                $response = ControlResponse::create([
                    ...$attributes,
                    'control_id' => $controlId,
                    'supervisor_id' => $supervisorId,
                    'schema_version' => $schemaVersion,
                    'submission_token' => $runId,
                ]);

                $afterCreate($response);

                if ($draftId !== null) {
                    ControlResponseDraft::query()
                        ->whereKey($draftId)
                        ->where('control_id', $controlId)
                        ->where('supervisor_id', $supervisorId)
                        ->where('state', 'active')
                        ->delete();
                }

                return $response;
            });
        } catch (QueryException $exception) {
            $existing = ControlResponse::query()->where('submission_token', $runId)->first();

            if ($existing) {
                $this->assertSubmissionOwner($existing, $controlId, $supervisorId, $schemaVersion);

                return $existing;
            }

            throw $exception;
        }
    }

    private function assertRunIdentity(string $runId, string $schemaVersion): void
    {
        if (! Str::isUuid($runId)) {
            throw ValidationException::withMessages(['draft' => 'Идентификатор проверки некорректен.']);
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $schemaVersion)) {
            throw ValidationException::withMessages(['draft' => 'Версия схемы контроля некорректна.']);
        }
    }

    private function assertSubmissionOwner(
        ControlResponse $response,
        int $controlId,
        int $supervisorId,
        string $schemaVersion,
    ): void {
        if (
            (int) $response->control_id !== $controlId
            || (int) $response->supervisor_id !== $supervisorId
            || ! is_string($response->schema_version)
            || ! hash_equals($schemaVersion, $response->schema_version)
        ) {
            throw ValidationException::withMessages([
                'submit' => 'Идентификатор завершения уже связан с другой проверкой.',
            ]);
        }
    }
}
