<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponseDraft;
use App\Models\User;
use App\Services\Controls\ControlSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class FocusV2BootstrapController extends Controller
{
    /**
     * Read-only, role-guarded boot payload for the new Focus client.
     * The existing Livewire route, scoring and persistence remain unchanged.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($user->role, ['admin', 'supervisor'], true), 403);

        $control = Control::query()->where('is_active', true)->latest()->firstOrFail();
        $rooms = is_array($control->main) ? array_values($control->main) : [];
        $schema = new ControlSchema((int) $control->id, $rooms);
        $version = $schema->version();
        $draft = ControlResponseDraft::query()
            ->where('control_id', $control->id)
            ->where('supervisor_id', $user->getKey())
            ->where('state', 'active')
            ->latest('updated_at')
            ->first();

        $conflict = false;
        if ($draft) {
            $draftVersion = $draft->schema_version;
            if (! $draftVersion && is_array($draft->schema_snapshot)) {
                $draftVersion = (new ControlSchema((int) $control->id, $draft->schema_snapshot))->version();
            }
            $conflict = ! is_string($draftVersion) || ! hash_equals($version, $draftVersion);

            if (! $conflict) {
                foreach ($schema->questionIds() as $roomIndex => $questions) {
                    foreach ($questions as $questionIndex => $questionId) {
                        $storedId = $draft->responses[$roomIndex][$questionIndex]['question_id'] ?? null;
                        if (is_string($storedId) && ! hash_equals($questionId, $storedId)) {
                            $conflict = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $answers = [];
        $media = [];
        foreach ($rooms as $roomIndex => $room) {
            foreach (array_values($room['items'] ?? []) as $questionIndex => $question) {
                $stored = ! $conflict ? ($draft?->responses[$roomIndex][$questionIndex] ?? []) : [];
                $stored = is_array($stored) ? $stored : [];
                $answers[$roomIndex][$questionIndex] = [
                    'selected' => (string) ($stored['selected'] ?? ''),
                    'custom' => (string) ($stored['custom'] ?? ''),
                    'corrective' => [
                        'repeats' => $stored['corrective']['repeats'] ?? null,
                        'action' => (string) ($stored['corrective']['action'] ?? ''),
                        'recheck' => $stored['corrective']['recheck'] ?? null,
                    ],
                ];
                $media[$roomIndex][$questionIndex] = [];
                foreach (array_values((array) ($stored['media'] ?? [])) as $mediaIndex => $file) {
                    if (! is_array($file)) {
                        continue;
                    }
                    $disk = $file['disk'] ?? null;
                    $path = (string) ($file['path'] ?? '');
                    if (! in_array($disk, ['local', 'public'], true)
                        || ! str_starts_with($path, 'controls/'.$control->id.'/')
                        || str_contains($path, '..') || str_contains($path, '\\')) {
                        continue;
                    }
                    $params = [$control, $roomIndex, $questionIndex, $mediaIndex, 'draft_id' => $draft->id];
                    $media[$roomIndex][$questionIndex][] = [
                        'index' => $mediaIndex,
                        'name' => (string) ($file['original_name'] ?? ''),
                        'mime' => (string) ($file['mime'] ?? ''),
                        'size' => (int) ($file['size'] ?? 0),
                        'comment' => (string) ($file['comment'] ?? ''),
                        'url' => route('page-checks.control.draft-media', $params),
                        'thumbnail_url' => route('page-checks.control.draft-media', [...$params, 'thumbnail' => 1]),
                    ];
                }
            }
        }

        // Presentation URLs are client-only; they do not change the canonical
        // ControlSchema hash or the server-side snapshot used for validation.
        $clientRooms = $rooms;
        foreach ($clientRooms as &$clientRoom) {
            if (! is_array($clientRoom['items'] ?? null)) {
                continue;
            }
            foreach ($clientRoom['items'] as &$question) {
                if (is_array($question) && filled($question['question_image'] ?? null)) {
                    $question['question_image_url'] = Storage::disk('public')->url($question['question_image']);
                }
            }
            unset($question);
        }
        unset($clientRoom);

        return response()->json([
            'control' => [
                'id' => $control->id,
                'name' => $control->name,
                'schema_version' => $version,
            ],
            'rooms' => $clientRooms,
            'question_ids' => $schema->questionIds(),
            'run' => [
                'id' => ! $conflict && $draft?->run_id ? $draft->run_id : (string) Str::uuid(),
                'draft_id' => $conflict ? null : $draft?->id,
                'revision' => $conflict ? 0 : (int) ($draft?->revision ?? 0),
                'schema_conflict' => $conflict,
                'conflicted_draft_id' => $conflict ? $draft?->id : null,
            ],
            'setup' => [
                'cleaner_id' => $conflict ? null : $draft?->cleaner_id,
                'apartment_id' => $conflict ? null : $draft?->apartment_id,
                'is_assigned' => ! $conflict && (bool) $draft?->is_assigned,
                'previous_cleaner' => $conflict ? '' : (string) ($draft?->previous_cleaner ?? ''),
                'cleaning_date' => $conflict ? now()->toDateString() : ($draft?->cleaning_date?->toDateString() ?? now()->toDateString()),
                'inspection_date' => $conflict ? now()->toDateString() : ($draft?->inspection_date?->toDateString() ?? now()->toDateString()),
                'comment' => $conflict ? '' : (string) ($draft?->comment ?? ''),
            ],
            'answers' => $answers,
            'media' => $media,
            'people' => User::query()->activeStaff()->whereIn('role', ['cleaner', 'supervisor'])
                ->orderBy('name')->get(['id', 'name', 'telegram_avatar_path', 'telegram_photo_url'])
                ->map(fn (User $person): array => [
                    'id' => $person->id, 'name' => $person->name, 'thumbnail' => $person->avatar_url,
                ])->all(),
            'apartments' => Apartment::query()->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'image'])
                ->map(fn (Apartment $apartment): array => [
                    'id' => $apartment->id, 'name' => $apartment->name,
                    'thumbnail' => filled($apartment->image) ? Storage::disk('public')->url($apartment->image) : null,
                ])->all(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
