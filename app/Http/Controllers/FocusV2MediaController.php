<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\FocusV2MediaUpload;
use App\Services\Controls\ControlSchema;
use App\Services\Controls\FocusMediaLimits;
use App\Services\RolePreviewContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class FocusV2MediaController extends Controller
{
    // Small requests avoid the shared host's typical multipart/PHP body limits.
    // 1 MiB halves requests for large videos while staying within typical
    // shared-host request-body limits. Existing sessions keep their own stored
    // chunk_bytes and remain resumable without migration.
    private const CHUNK_BYTES = 1024 * 1024;

    private const MAX_MEDIA_PER_QUESTION = 12;

    public function start(Request $request, RolePreviewContext $preview): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'test_mode' => ['sometimes', 'boolean'],
            'control_id' => ['required', 'integer', 'min:1'],
            'draft_id' => ['required', 'integer', 'min:1'],
            'run_id' => ['required', 'uuid'],
            'schema_version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/i'],
            'room_index' => ['required', 'integer', 'min:0'],
            'question_index' => ['required', 'integer', 'min:0'],
            'original_name' => ['required', 'string', 'max:180'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1', 'max:104857600'],
        ]);
        // After finalization only a previously registered upload ID may be
        // resumed. No new evidence can be silently introduced after sign-off.
        $existingForResult = FocusV2MediaUpload::query()->whereKey($data['upload_id'])
            ->whereNotNull('control_response_id')->first();
        if ($existingForResult) {
            $this->ownedUpload($request, $preview, $data['upload_id']);
            abort_unless(
                (int) $existingForResult->control_id === (int) $data['control_id']
                && (int) $existingForResult->draft_id === (int) $data['draft_id']
                && $existingForResult->run_id === $data['run_id']
                && $existingForResult->schema_version === $data['schema_version']
                && $existingForResult->room_index === (int) $data['room_index']
                && $existingForResult->question_index === (int) $data['question_index']
                && $existingForResult->original_name === mb_substr(basename(str_replace('\\', '/', $data['original_name'])), 0, 180)
                && $existingForResult->declared_mime === $data['mime']
                && $existingForResult->size_bytes === (int) $data['size']
                && $existingForResult->status !== 'cancelled',
                409,
                'Загрузку можно продолжить только с исходным файлом.',
            );

            return $this->describe($existingForResult);
        }

        [$control, $draft, $rooms] = $this->context($request, $preview, $data);
        $room = (int) $data['room_index'];
        $question = (int) $data['question_index'];
        abort_unless(isset($rooms[$room]['items'][$question]), 422);
        $mime = (string) $data['mime'];
        abort_unless(in_array($mime, [...FocusMediaLimits::IMAGE_MIMES, ...FocusMediaLimits::VIDEO_MIMES], true), 422);
        $isImage = in_array($mime, FocusMediaLimits::IMAGE_MIMES, true);
        abort_unless((int) $data['size'] <= ($isImage ? FocusMediaLimits::IMAGE_KIB : FocusMediaLimits::VIDEO_KIB) * 1024, 422);

        $uploadId = (string) $data['upload_id'];
        $filename = mb_substr(basename(str_replace('\\', '/', $data['original_name'])), 0, 180);
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?: 'file';
        $totalChunks = (int) ceil(((int) $data['size']) / self::CHUNK_BYTES);

        $upload = DB::transaction(function () use ($uploadId, $data, $request, $control, $draft, $room, $question, $filename, $mime, $totalChunks): FocusV2MediaUpload {
            // Serialize reservations for this draft so concurrent start requests
            // cannot bypass the 12-file limit before files get attached.
            $lockedDraft = ControlResponseDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedDraft->state === (! empty($data['test_mode']) ? 'sandbox' : 'active')
                && $lockedDraft->run_id === $data['run_id'], 409);
            $existing = FocusV2MediaUpload::query()->whereKey($uploadId)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(
                    (int) $existing->supervisor_id === (int) $request->user()->id
                    && (int) $existing->control_id === (int) $control->id
                    && (int) $existing->draft_id === (int) $draft->id
                    && $existing->run_id === $data['run_id']
                    && $existing->schema_version === $data['schema_version']
                    && $existing->room_index === $room
                    && $existing->question_index === $question
                    && $existing->original_name === $filename
                    && $existing->declared_mime === $mime
                    && $existing->size_bytes === (int) $data['size']
                    // Uploads registered on previous deployments retain
                    // their original (512 KiB) chunk size on resume. Never
                    // force an in-flight video into the new 1 MiB grid.
                    && $existing->chunk_bytes > 0
                    && $existing->total_chunks === (int) ceil($existing->size_bytes / $existing->chunk_bytes)
                    && $existing->status !== 'cancelled',
                    409,
                );

                return $existing;
            }

            $attachedCount = count((array) ($lockedDraft->responses[$room][$question]['media'] ?? []));
            $reservedCount = FocusV2MediaUpload::query()
                ->where('draft_id', $lockedDraft->id)
                ->where('room_index', $room)
                ->where('question_index', $question)
                ->whereIn('status', ['pending', 'ready'])
                ->count();
            abort_unless($attachedCount + $reservedCount < self::MAX_MEDIA_PER_QUESTION,
                422, 'На один вопрос допускается не больше 12 вложений, включая загружаемые.');

            return FocusV2MediaUpload::create([
                'id' => $uploadId,
                'control_id' => $control->id,
                'supervisor_id' => $request->user()->id,
                'draft_id' => $draft->id,
                'run_id' => $data['run_id'],
                'schema_version' => $data['schema_version'],
                'room_index' => $room,
                'question_index' => $question,
                'original_name' => $filename,
                'declared_mime' => $mime,
                'size_bytes' => (int) $data['size'],
                'chunk_bytes' => self::CHUNK_BYTES,
                'total_chunks' => $totalChunks,
                'status' => 'pending',
            ]);
        });

        return $this->describe($upload);
    }

    public function status(Request $request, RolePreviewContext $preview, string $upload): JsonResponse
    {
        return $this->describe($this->ownedUpload($request, $preview, $upload));
    }

    public function chunk(Request $request, RolePreviewContext $preview, string $upload, int $index): JsonResponse
    {
        $record = $this->ownedUpload($request, $preview, $upload);
        abort_unless($index >= 0 && $index < $record->total_chunks, 422);
        abort_unless($record->status === 'pending', 409);
        $content = $request->getContent();
        $expectedBytes = min($record->chunk_bytes, $record->size_bytes - $index * $record->chunk_bytes);
        abort_unless(strlen($content) === $expectedBytes, 422, 'Неверный размер части файла.');

        return DB::transaction(function () use ($record, $index, $content): JsonResponse {
            $locked = FocusV2MediaUpload::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 409);
            $disk = Storage::disk('local');
            $path = $this->chunkPath($locked, $index);
            if ($disk->exists($path)) {
                abort_unless(hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $content)), 409, 'Часть файла отличается.');
                return response()->json(['index' => $index, 'replayed' => true]);
            }
            $temporary = $path.'.part';
            if (! $disk->put($temporary, $content) || ! $disk->move($temporary, $path)) {
                abort(500, 'Не удалось записать часть файла.');
            }

            $locked->touch();

            return response()->json(['index' => $index, 'replayed' => false]);
        })->header('Cache-Control', 'private, no-store');
    }

    public function complete(Request $request, RolePreviewContext $preview, string $upload): JsonResponse
    {
        $record = $this->ownedUpload($request, $preview, $upload);

        $response = DB::transaction(function () use ($record): JsonResponse {
            $upload = FocusV2MediaUpload::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if($upload->status === 'cancelled', 409);
            if (in_array($upload->status, ['ready', 'attached'], true)) {
                return $this->describe($upload);
            }

            $disk = Storage::disk('local');
            $directory = $this->tempDirectory($upload);
            $assembledPath = $directory.'/assembled.part';
            $disk->makeDirectory($directory);
            $destination = fopen($disk->path($assembledPath), 'wb');
            abort_unless($destination, 500, 'Не удалось создать временный файл.');

            $bytes = 0;
            try {
                for ($i = 0; $i < $upload->total_chunks; $i++) {
                    $path = $this->chunkPath($upload, $i);
                    abort_unless($disk->exists($path), 409, 'Не все части загружены.');
                    $expected = min($upload->chunk_bytes, $upload->size_bytes - $i * $upload->chunk_bytes);
                    abort_unless($disk->size($path) === $expected, 409, 'Повреждённая часть файла.');
                    $source = fopen($disk->path($path), 'rb');
                    abort_unless($source, 500);
                    try {
                        $copied = stream_copy_to_stream($source, $destination);
                        abort_unless($copied === $expected, 500);
                        $bytes += $copied;
                    } finally {
                        fclose($source);
                    }
                }
            } finally {
                fclose($destination);
            }
            abort_unless($bytes === $upload->size_bytes, 422);

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $actualMime = $finfo->file($disk->path($assembledPath));
            $isImage = in_array($actualMime, FocusMediaLimits::IMAGE_MIMES, true);
            $isVideo = in_array($actualMime, FocusMediaLimits::VIDEO_MIMES, true);
            if ((! $isImage && ! $isVideo)
                || $upload->size_bytes > ($isImage ? FocusMediaLimits::IMAGE_KIB : FocusMediaLimits::VIDEO_KIB) * 1024
                || ($isImage !== in_array($upload->declared_mime, FocusMediaLimits::IMAGE_MIMES, true))) {
                $disk->delete($assembledPath);
                throw ValidationException::withMessages(['file' => 'Файл не прошёл проверку формата и размера.']);
            }

            $draftState = ControlResponseDraft::query()->whereKey($upload->draft_id)->value('state');
            abort_unless(in_array($draftState, ['active', 'sandbox'], true), 409);
            $finalPath = $draftState === 'sandbox'
                ? 'focus-v2-sandbox/'.$upload->supervisor_id.'/'.$upload->run_id.'/'.$upload->id
                : 'controls/'.$upload->control_id.'/'.now()->format('Y/m').'/'.$upload->id;
            $disk->makeDirectory(dirname($finalPath));
            abort_unless($disk->move($assembledPath, $finalPath), 500, 'Не удалось сохранить файл.');
            $upload->forceFill([
                'status' => 'ready', 'stored_path' => $finalPath, 'actual_mime' => $actualMime,
            ])->save();
            return $this->describe($upload);
        });

        // Clean chunks only after the database confirms the ready file. If a
        // transaction fails, the parts remain available for safe retry.
        Storage::disk('local')->deleteDirectory($this->tempDirectory($record));

        return $response;
    }

    public function attach(Request $request, RolePreviewContext $preview, string $upload): JsonResponse
    {
        $input = $request->validate([
            'draft_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $record = $this->ownedUpload($request, $preview, $upload);
        abort_unless($record->draft_id === (int) $input['draft_id'], 409);

        if ($record->control_response_id !== null) {
            return $this->attachToResult($record);
        }

        return DB::transaction(function () use ($record, $input, $request): JsonResponse {
            $draft = ControlResponseDraft::query()->whereKey($record->draft_id)->lockForUpdate()->firstOrFail();
            $locked = FocusV2MediaUpload::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($draft->state, ['active', 'sandbox'], true)
                && ($draft->state !== 'sandbox' || $request->user()->role === 'admin')
                && $draft->run_id === $locked->run_id
                && $draft->schema_version === $locked->schema_version, 409);
            abort_unless(in_array($locked->status, ['ready', 'attached'], true), 409);

            $responses = is_array($draft->responses) ? $draft->responses : [];
            $room = $locked->room_index;
            $question = $locked->question_index;
            $files = array_values((array) ($responses[$room][$question]['media'] ?? []));
            $existingIndex = null;
            foreach ($files as $index => $file) {
                if (is_array($file) && ($file['upload_id'] ?? null) === $locked->id) {
                    $existingIndex = $index;
                    break;
                }
            }

            if ($existingIndex === null) {
                abort_unless($locked->status === 'ready', 409);
                abort_unless((int) $draft->revision === (int) $input['expected_revision'], 409, 'Черновик изменился. Повторите сохранение.');
                abort_unless(count($files) < self::MAX_MEDIA_PER_QUESTION, 422, 'Превышен лимит медиа.');
                abort_unless($locked->stored_path && Storage::disk('local')->exists($locked->stored_path), 409);

                $files[] = [
                    'disk' => 'local', 'path' => $locked->stored_path,
                    'original_name' => $locked->original_name, 'mime' => $locked->actual_mime,
                    'size' => $locked->size_bytes, 'uploaded_at' => now()->toDateTimeString(),
                    'upload_id' => $locked->id,
                ];
                $existingIndex = count($files) - 1;
                $responses[$room][$question]['media'] = $files;
                $draft->responses = $responses;
                $draft->revision = (int) $draft->revision + 1;
                $draft->save();
                $locked->status = 'attached';
                $locked->save();
            }

            $parameters = [$locked->control_id, $room, $question, $existingIndex, 'draft_id' => $draft->id];
            if ($draft->state === 'sandbox') {
                $parameters['test_mode'] = 1;
            }

            return response()->json([
                'run' => ['draft_id' => $draft->id, 'revision' => (int) $draft->revision],
                'room_index' => $room,
                'question_index' => $question,
                'media_item' => [
                    'index' => $existingIndex, 'upload_id' => $locked->id,
                    'name' => $locked->original_name, 'mime' => $locked->actual_mime,
                    'size' => $locked->size_bytes,
                    'url' => route('page-checks.control.draft-media', $parameters),
                    'thumbnail_url' => route('page-checks.control.draft-media', [...$parameters, 'thumbnail' => 1]),
                ],
            ])->header('Cache-Control', 'private, no-store');
        });
    }

    /**
     * User-confirmed deletion of an already attached draft photo/video.
     * Submitted result evidence is immutable and cannot be removed here.
     */
    public function removeAttached(Request $request, RolePreviewContext $preview, string $upload): JsonResponse
    {
        $input = $request->validate(['expected_revision' => ['required', 'integer', 'min:1']]);
        $record = $this->ownedUpload($request, $preview, $upload);
        abort_unless($record->control_response_id === null, 409, 'Завершённый контроль нельзя изменить.');

        $data = DB::transaction(function () use ($record, $input): array {
            $draft = ControlResponseDraft::query()
                ->whereKey($record->draft_id)->whereIn('state', ['active', 'sandbox'])
                ->lockForUpdate()->firstOrFail();
            $locked = FocusV2MediaUpload::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'attached' && $locked->control_response_id === null
                && $locked->run_id === $draft->run_id
                && (int) $locked->supervisor_id === (int) $draft->supervisor_id, 409);
            abort_unless((int) $draft->revision === (int) $input['expected_revision'],
                409, 'Черновик изменился. Обновите страницу.');

            $responses = is_array($draft->responses) ? $draft->responses : [];
            $files = array_values((array) ($responses[$locked->room_index][$locked->question_index]['media'] ?? []));
            $kept = array_values(array_filter($files,
                static fn ($file): bool => ! is_array($file) || ($file['upload_id'] ?? null) !== $locked->id));
            abort_unless(count($kept) === count($files) - 1, 409, 'Файл отсутствует в черновике.');

            $responses[$locked->room_index][$locked->question_index]['media'] = $kept;
            $draft->responses = $responses;
            $draft->revision = (int) $draft->revision + 1;
            $draft->save();

            $path = (string) $locked->stored_path;
            $safePrefix = $draft->state === 'sandbox'
                ? 'focus-v2-sandbox/'.$locked->supervisor_id.'/'.$locked->run_id.'/'
                : 'controls/'.$locked->control_id.'/';
            $safeToDelete = str_starts_with($path, $safePrefix)
                && ! str_contains($path, '..') && ! str_contains($path, '\\');
            $locked->delete();

            return ['revision' => $draft->revision, 'path' => $safeToDelete ? $path : null];
        });
        // Database commit takes precedence; a failed unlink can be cleaned
        // separately without leaving the response pointing to a missing file.
        if ($data['path'] !== null) {
            Storage::disk('local')->delete($data['path']);
        }

        return response()->json(['deleted' => true, 'run' => [
            'draft_id' => $record->draft_id, 'revision' => $data['revision'],
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, RolePreviewContext $preview, string $upload): JsonResponse
    {
        $record = $this->ownedUpload($request, $preview, $upload);

        return DB::transaction(function () use ($record): JsonResponse {
            $record = FocusV2MediaUpload::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if($record->status === 'attached', 409, 'Прикреплённый файл нельзя отменить.');
            if ($record->status !== 'cancelled') {
                $record->status = 'cancelled';
                $record->save();
                Storage::disk('local')->deleteDirectory($this->tempDirectory($record));
                if ($record->stored_path) {
                    Storage::disk('local')->delete($record->stored_path);
                }
            }
            return response()->json(['cancelled' => true])->header('Cache-Control', 'private, no-store');
        });
    }

    /**
     * Post-finalization attachment updates evidence on the immutable scored
     * result. It never recalculates the score or edits the inspection answers.
     */
    private function attachToResult(FocusV2MediaUpload $record): JsonResponse
    {
        $response = DB::transaction(function () use ($record): array {
            $result = ControlResponse::query()->whereKey($record->control_response_id)
                ->lockForUpdate()->firstOrFail();
            $upload = FocusV2MediaUpload::query()->whereKey($record->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless((int) $upload->control_response_id === (int) $result->getKey()
                && in_array($upload->status, ['ready', 'attached'], true), 409);

            $room = $upload->room_index;
            $question = $upload->question_index;
            abort_unless(isset($result->schema_snapshot[$room]['items'][$question]), 409);
            $answers = is_array($result->responses) ? $result->responses : [];
            $files = array_values((array) ($answers[$room][$question]['media'] ?? []));
            $index = null;
            foreach ($files as $i => $file) {
                if (is_array($file) && ($file['upload_id'] ?? null) === $upload->id) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                abort_unless($upload->status === 'ready', 409);
                abort_unless(count($files) < self::MAX_MEDIA_PER_QUESTION, 422);
                abort_unless($upload->stored_path
                    && Storage::disk('local')->exists($upload->stored_path), 409);
                $files[] = [
                    'disk' => 'local', 'path' => $upload->stored_path,
                    'original_name' => $upload->original_name,
                    'mime' => $upload->actual_mime,
                    'size' => $upload->size_bytes,
                    'uploaded_at' => now()->toDateTimeString(),
                    'upload_id' => $upload->id,
                ];
                $index = count($files) - 1;
                $answers[$room][$question]['media'] = $files;
                $result->responses = $answers;
                $result->save();
                $upload->status = 'attached';
                $upload->save();
            }

            $params = [$result, $room, $question, $index];
            return [$result, [
                'run' => ['draft_id' => null, 'revision' => null],
                'room_index' => $room,
                'question_index' => $question,
                'media_item' => [
                    'index' => $index, 'upload_id' => $upload->id,
                    'name' => $upload->original_name, 'mime' => $upload->actual_mime,
                    'size' => $upload->size_bytes,
                    'url' => route('page-checks.control.result-media', $params),
                    'thumbnail_url' => route('page-checks.control.result-media', [...$params, 'thumbnail' => 1]),
                ],
            ]];
        });

        [$result, $body] = $response;
        $progress = app(\App\Services\Controls\FocusV2MediaProgress::class)->forResponse($result, false);
        if ($progress['state'] === 'complete') {
            app(\App\Services\Controls\ControlCompletionNotificationService::class)->queueFor($result);
        }
        $body['media'] = $progress;

        return response()->json($body)->header('Cache-Control', 'private, no-store');
    }

    private function context(Request $request, RolePreviewContext $preview, array $data): array
    {
        $preview->assertWritable();
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, ['admin', 'supervisor'], true), 403);
        $control = Control::query()->where('is_active', true)->latest()->firstOrFail();
        $rooms = is_array($control->main) ? array_values($control->main) : [];
        $schemaVersion = (new ControlSchema((int) $control->id, $rooms))->version();
        abort_unless((int) $data['control_id'] === (int) $control->id
            && hash_equals($schemaVersion, (string) $data['schema_version']), 409, 'Шаблон контроля изменился.');
        $sandbox = (bool) ($data['test_mode'] ?? false);
        if ($sandbox) {
            abort_unless($user->role === 'admin', 403);
        }
        $draft = ControlResponseDraft::query()
            ->whereKey((int) $data['draft_id'])
            ->where('control_id', $control->id)
            ->where('supervisor_id', $user->getKey())
            ->where('run_id', (string) $data['run_id'])
            ->where('schema_version', $schemaVersion)
            ->where('state', $sandbox ? 'sandbox' : 'active')
            ->firstOrFail();

        return [$control, $draft, $rooms];
    }

    private function ownedUpload(Request $request, RolePreviewContext $preview, string $id): FocusV2MediaUpload
    {
        $preview->assertWritable();
        abort_unless($request->user()?->is_active
            && in_array($request->user()->role, ['admin', 'supervisor'], true), 403);
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        $upload = FocusV2MediaUpload::query()->whereKey($id)
            ->where('supervisor_id', $request->user()->getKey())->firstOrFail();
        if ($upload->control_response_id !== null) {
            $response = ControlResponse::query()->whereKey($upload->control_response_id)->firstOrFail();
            abort_unless((int) $response->supervisor_id === (int) $request->user()->getKey()
                && (int) $response->control_id === (int) $upload->control_id
                && $response->submission_token === $upload->run_id
                && $response->schema_version === $upload->schema_version, 403);
        } else {
            $sandbox = ControlResponseDraft::query()->whereKey($upload->draft_id)->value('state') === 'sandbox';
            $this->context($request, $preview, [
                'control_id' => $upload->control_id, 'draft_id' => $upload->draft_id,
                'run_id' => $upload->run_id, 'schema_version' => $upload->schema_version,
                'test_mode' => $sandbox,
            ]);
        }

        return $upload;
    }

    private function describe(FocusV2MediaUpload $upload): JsonResponse
    {
        $disk = Storage::disk('local');
        $received = [];
        if ($upload->status === 'pending') {
            for ($i = 0; $i < $upload->total_chunks; $i++) {
                $path = $this->chunkPath($upload, $i);
                if ($disk->exists($path)) {
                    $expected = min($upload->chunk_bytes, $upload->size_bytes - $i * $upload->chunk_bytes);
                    if ($disk->size($path) === $expected) {
                        $received[] = $i;
                    }
                }
            }
        }

        return response()->json([
            'upload_id' => $upload->id,
            'status' => $upload->status,
            'chunk_bytes' => $upload->chunk_bytes,
            'total_chunks' => $upload->total_chunks,
            'received' => $received,
            'urls' => [
                'status' => route('page-checks.control.focus-v2.media.status', $upload->id),
                'chunk_base' => route('page-checks.control.focus-v2.media.status', $upload->id).'/chunks',
                'complete' => route('page-checks.control.focus-v2.media.complete', $upload->id),
                'attach' => route('page-checks.control.focus-v2.media.attach', $upload->id),
                'cancel' => route('page-checks.control.focus-v2.media.cancel', $upload->id),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function tempDirectory(FocusV2MediaUpload $upload): string
    {
        return 'focus-v2/uploads/'.$upload->id;
    }

    private function chunkPath(FocusV2MediaUpload $upload, int $index): string
    {
        return $this->tempDirectory($upload).'/chunk-'.$index;
    }
}
