<?php

namespace App\Http\Controllers;

use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Services\RolePreviewContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FocusV2ResetController extends Controller
{
    public function __invoke(Request $request, RolePreviewContext $preview): JsonResponse
    {
        $preview->assertWritable();
        $user = $request->user();
        abort_unless($user?->is_active && $user->isApproved() && in_array($user->role, ['admin', 'supervisor'], true), 403);

        $input = $request->validate([
            'control_id' => ['required', 'integer', 'min:1'],
            'run_id' => ['required', 'uuid'],
            'draft_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'test_mode' => ['sometimes', 'boolean'],
        ]);

        $sandbox = $request->boolean('test_mode');
        abort_if($sandbox && $user->role !== 'admin', 403);
        $activeState = $sandbox ? 'sandbox' : 'active';

        DB::transaction(function () use ($input, $user, $activeState): void {
            $owned = ControlResponseDraft::query()
                ->where('control_id', $input['control_id'])
                ->where('supervisor_id', $user->id);

            abort_if(ControlResponse::query()->where('submission_token', $input['run_id'])->exists(), 409, 'Контроль уже отправлен.');

            if ($input['draft_id'] === null) {
                abort_unless((int) $input['expected_revision'] === 0
                    && ! (clone $owned)->where('state', $activeState)->exists(), 409, 'Черновик изменился. Обновите страницу.');

                return;
            }

            $draft = (clone $owned)->whereKey($input['draft_id'])
                ->where('run_id', $input['run_id'])->lockForUpdate()->first();

            abort_unless($draft, 409, 'Черновик не найден. Обновите страницу.');
            if ($draft->state === 'discarded' && (int) $draft->revision === (int) $input['expected_revision'] + 1) {
                return; // Retry after a lost acknowledgement.
            }

            abort_unless($draft->state === $activeState
                && (int) $draft->revision === (int) $input['expected_revision'], 409, 'Черновик изменился. Обновите страницу.');

            // Preserve a tombstone and its files so that old requests cannot
            // resurrect this run or accidentally remove completed evidence.
            $draft->state = 'discarded';
            $draft->revision = (int) $draft->revision + 1;
            $draft->save();
        });

        return response()->json(['reset' => true])->header('Cache-Control', 'private, no-store');
    }
}
