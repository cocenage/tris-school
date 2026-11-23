<?php

namespace App\Services\Controls;

use App\Models\ControlResponse;
use App\Models\FocusV2MediaUpload;

final class FocusV2MediaProgress
{
    /**
     * Completion of the inspection and readiness of evidence are distinct.
     * A cancelled transfer is never silently counted as successfully uploaded.
     */
    public function forResponse(ControlResponse $response, bool $includeUploads = true): array
    {
        $query = FocusV2MediaUpload::query()
            ->where('control_response_id', $response->getKey());

        $counts = [
            'pending' => 0, 'ready' => 0, 'attached' => 0, 'cancelled' => 0,
        ];
        $rows = $query->orderBy('created_at')->get();
        foreach ($rows as $upload) {
            if (array_key_exists($upload->status, $counts)) {
                $counts[$upload->status]++;
            }
        }

        $unresolved = $counts['pending'] + $counts['ready'];
        $state = $unresolved > 0
            ? 'awaiting_media'
            : ($counts['cancelled'] > 0 ? 'needs_review' : 'complete');

        return [
            'state' => $state,
            'total' => $rows->count(),
            'pending' => $counts['pending'],
            'ready_to_attach' => $counts['ready'],
            'attached' => $counts['attached'],
            'cancelled' => $counts['cancelled'],
            'uploads' => $includeUploads
                ? $rows->map(static fn (FocusV2MediaUpload $upload): array => [
                    'upload_id' => $upload->id,
                    'status' => $upload->status,
                    'room_index' => $upload->room_index,
                    'question_index' => $upload->question_index,
                    'original_name' => $upload->original_name,
                    'size' => $upload->size_bytes,
                    'mime' => $upload->declared_mime,
                    'control_id' => $upload->control_id,
                    'draft_id' => $upload->draft_id,
                    'run_id' => $upload->run_id,
                    'schema_version' => $upload->schema_version,
                ])->all()
                : [],
        ];
    }
}
