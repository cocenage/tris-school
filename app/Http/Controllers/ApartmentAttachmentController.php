<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\ApartmentInformationAttachment;
use App\Services\Apartments\ApartmentAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApartmentAttachmentController
{
    public function __invoke(
        Request $request,
        Apartment $apartment,
        ApartmentInformationAttachment $attachment,
        ApartmentAccessService $access,
    ): StreamedResponse {
        abort_unless($attachment->apartment_id === $apartment->id, 404);
        abort_unless($access->canView($request->user(), $apartment), 403);

        $disk = Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->path), 404);

        $stream = $disk->readStream($attachment->path);
        abort_unless(is_resource($stream), 404);

        $filename = addcslashes((string) ($attachment->original_name ?: basename($attachment->path)), "\\\"\r\n");
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) ($attachment->file_size ?: $disk->size($attachment->path)),
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
