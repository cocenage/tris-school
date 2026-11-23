<?php

namespace App\Http\Controllers;

use App\Models\ControlResponse;
use App\Services\Controls\FocusV2MediaProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FocusV2MediaReviewController extends Controller
{
    public function __invoke(Request $request, ControlResponse $controlResponse, FocusV2MediaProgress $progress): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, ['admin', 'supervisor'], true), 403);
        abort_unless((int) $controlResponse->supervisor_id === (int) $user->getKey()
            || $user->role === 'admin', 403);

        return response()->json([
            'response_id' => $controlResponse->getKey(),
            'submitted' => true,
            'result_url' => route('page-profile.checks.result', $controlResponse),
            'total_points' => $controlResponse->total_points,
            'max_points' => $controlResponse->max_points,
            'score_percent' => $controlResponse->score_percent,
            'result_zone' => $controlResponse->result_zone,
            'result_zone_reason' => $controlResponse->result_zone_reason,
            'media' => $progress->forResponse($controlResponse),
        ])->header('Cache-Control', 'private, no-store');
    }
}
