<?php

namespace App\Http\Controllers;

use App\Models\AntigocciaReport;
use App\Services\Antigoccia\AntigocciaAssessment;
use App\Services\Apartments\ApartmentAccessService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class AntigocciaPhotoController extends Controller
{
    public function __invoke(AntigocciaReport $report, string $surface, ApartmentAccessService $access)
    {
        abort_unless($access->canView(auth()->user(), $report->apartment), 403);
        abort_unless($surface === 'remaining' || array_key_exists($surface, AntigocciaAssessment::SURFACES), 404);
        $path = $report->photos[$surface] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'antigoccia/'), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
