<?php

namespace App\Http\Controllers;

use App\Models\FeedbackSuggestion;
use App\Models\SalaryQuestion;
use App\Models\ScheduleQuestion;
use App\Services\RolePreviewContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class StaffRequestAttachmentController
{
    public function __invoke(Request $request, string $kind, int $requestId, int $index): BinaryFileResponse
    {
        abort_if(app(RolePreviewContext::class)->isActive(), 403);

        $model = match ($kind) {
            'salary' => SalaryQuestion::class,
            'schedule' => ScheduleQuestion::class,
            'feedback' => FeedbackSuggestion::class,
            default => abort(404),
        };

        $record = $model::query()->where('user_id', $request->user()->getKey())->findOrFail($requestId);
        $attachment = $record->attachments[$index] ?? null;
        abort_unless(is_array($attachment), 404);

        $path = $attachment['path'] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'staff-forms/'.$kind.'/'), 404);
        abort_if(str_contains($path, '..') || str_contains($path, chr(92)), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'video/mp4', 'video/webm', 'video/quicktime', 'video/ogg'], true);
        $name = basename(str_replace(chr(92), '/', (string) ($attachment['name'] ?? basename($path))));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'attachment';

        $response = response()->file($disk->path($path), [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition(
            $inline && ! $request->boolean('download') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            'attachment'.($inline ? '.'.pathinfo(basename($path), PATHINFO_EXTENSION) : ''),
        );

        return $response;
    }
}
