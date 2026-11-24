<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Services\Controls\ControlImageThumbnail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ControlMediaController
{
    public function draft(Request $request, Control $control, int $room, int $question, int $media): BinaryFileResponse
    {
        abort_unless($request->user()?->is_active
            && in_array($request->user()->role, ['admin', 'supervisor'], true), 403);
        $sandbox = $request->boolean('test_mode');
        if ($sandbox) {
            abort_unless($request->user()->role === 'admin', 403);
        }

        $draftQuery = ControlResponseDraft::query()
            ->where('control_id', $control->id)
            ->where('supervisor_id', $request->user()->id)
            ->where('state', $sandbox ? 'sandbox' : 'active');

        if ($request->filled('draft_id')) {
            $draftQuery->whereKey($request->integer('draft_id'));
        } else {
            $draftQuery->latest('updated_at');
        }

        $draft = $draftQuery->firstOrFail();

        $file = $draft->responses[$room][$question]['media'][$media] ?? null;

        return $this->serve($file, $control->id, $request->user()->id, $request->boolean('download'), $request->boolean('thumbnail'),
            $sandbox ? $draft->run_id : null);
    }

    public function result(Request $request, ControlResponse $controlResponse, int $room, int $question, int $media): BinaryFileResponse
    {
        abort_unless($request->user()?->canViewAllStaffActivity()
            || $controlResponse->supervisor_id === $request->user()?->id
            || $controlResponse->cleaner_id === $request->user()?->id, 403);

        $file = $controlResponse->responses[$room][$question]['media'][$media] ?? null;

        return $this->serve($file, $controlResponse->control_id, null, $request->boolean('download'), $request->boolean('thumbnail'));
    }

    private function serve(mixed $file, int $controlId, ?int $supervisorId = null, bool $download = false, bool $thumbnail = false, ?string $sandboxRunId = null): BinaryFileResponse
    {
        abort_unless(is_array($file), 404);

        $diskName = $file['disk'] ?? null;
        $path = (string) ($file['path'] ?? '');
        $allowed = (in_array($diskName, ['local', 'public'], true)
                && str_starts_with($path, 'controls/'.$controlId.'/'))
            || ($supervisorId !== null && $diskName === 'local'
                && str_starts_with($path, 'control-drafts/'.$controlId.'/'.$supervisorId.'/'))
            || ($supervisorId !== null && $sandboxRunId !== null && $diskName === 'local'
                && str_starts_with($path, 'focus-v2-sandbox/'.$supervisorId.'/'.$sandboxRunId.'/'));
        $allowed = $allowed && ! str_contains($path, '..') && ! str_contains($path, '\\');
        abort_unless($allowed, 404);

        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        $headers = [
            'Content-Type' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($thumbnail && ! $download) {
            $preview = app(ControlImageThumbnail::class)->path($disk->path($path), $headers['Content-Type']);
            if ($preview !== null) {
                return response()->file($preview, array_replace($headers, ['Content-Type' => 'image/jpeg']));
            }

            return response()->file(resource_path('images/control-media-placeholder.png'), array_replace($headers, ['Content-Type' => 'image/png']));
        }

        if ($download) {
            $name = basename(str_replace('\\', '/', (string) ($file['original_name'] ?? basename($path))));
            $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?: basename($path);

            return response()->download($disk->path($path), $name, $headers);
        }

        return response()->file($disk->path($path), $headers + ['Content-Disposition' => 'inline']);
    }
}
