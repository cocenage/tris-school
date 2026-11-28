<?php

namespace App\Http\Controllers;

use App\Services\Pulse\PulseService;
use App\Services\RolePreviewContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PulseController extends Controller
{
    public function __invoke(Request $request, PulseService $pulse, RolePreviewContext $preview): JsonResponse
    {
        abort_unless($request->user()?->isApproved(), 403);
        abort_if($preview->isActive(), 403);
        abort_unless(in_array($request->user()->role, ['cleaner', 'supervisor', 'admin'], true), 403);

        return response()->json($pulse->snapshot($request->user()))
            ->header('Cache-Control', 'private, no-store');
    }
}
