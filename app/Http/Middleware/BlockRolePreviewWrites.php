<?php

namespace App\Http\Middleware;

use App\Services\RolePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockRolePreviewWrites
{
    private const BLOCKED_ACTIONS = [
        'access.required' => ['save'],
        'forms.page-coaching-create' => ['save'],
        'forms.page-control' => [
            'saveDraft', 'saveDraftAuto', 'resetControl', 'setAnswer', 'setCorrectiveBoolean',
            'finishPhotoUpload', 'removeQueuedPhoto', 'confirmSubmit', 'submit',
            '_startUpload', '_finishUpload', '_removeUpload',
        ],
        'forms.page-control-focus' => [
            'saveDraft', 'start', 'setAnswer', 'setCorrectiveBoolean', 'beginMediaUpload',
            'failMediaUpload', 'cancelMediaUpload', 'attachMedia', 'removeMedia', 'submit',
            '_startUpload', '_finishUpload', '_removeUpload',
        ],
        'forms.page-feedback' => ['removeAttachment', 'resetForm', 'submit', '_startUpload', '_finishUpload', '_removeUpload'],
        'forms.page-inventory' => [
            'selectOne', 'increment', 'decrement', 'removeSelected', 'updatedSelected', 'resetForm', 'submit',
        ],
        'forms.page-salary' => ['removeAttachment', 'resetForm', 'submit', '_startUpload', '_finishUpload', '_removeUpload'],
        'forms.page-schedule' => ['removeAttachment', 'resetForm', 'submit', '_startUpload', '_finishUpload', '_removeUpload'],
        'forms.page-vacation' => ['updatedComment', 'resetForm', 'selectDate', 'submit'],
        'forms.page-weekend' => [
            'updatedComment', 'confirmSundaySubmission', 'resetForm', 'removeRange', 'selectDate', 'submit',
        ],
        'page-profile' => ['saveProfile'],
        'tasks.page-board' => ['createTask', 'moveTask'],
        'tasks.page-room' => ['createBoard', 'addMember', 'removeMember'],
        'tasks.page-rooms' => ['createRoom'],
        'tasks.page-task' => [
            'save', 'moveToColumn', 'addChecklistItem', 'toggleChecklistItem', 'deleteChecklistItem',
            'addComment', 'markDone',
        ],
        'tasks.page-tasks' => ['createTask'],
    ];

    private const BLOCKED_PROPERTY_UPDATES = [
        'forms.page-coaching-create',
        'forms.page-control',
        'forms.page-control-focus',
        'forms.page-feedback',
        'forms.page-inventory',
        'forms.page-salary',
        'forms.page-schedule',
        'forms.page-vacation',
        'forms.page-weekend',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $components = $request->input('components', []);

        if (app(RolePreviewContext::class)->isActive()
            && is_array($components)
            && $this->containsWriteOperation($components)) {
            abort(403, 'Действия недоступны в режиме предпросмотра.');
        }

        return $next($request);
    }

    public function containsWriteOperation(array $components): bool
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $snapshot = json_decode($component['snapshot'] ?? '', true);
            $name = data_get($snapshot, 'memo.name');

            if (! is_string($name)) {
                continue;
            }

            $updates = $component['updates'] ?? [];

            if (in_array($name, self::BLOCKED_PROPERTY_UPDATES, true)
                && is_array($updates)
                && $updates !== []) {
                return true;
            }

            $blockedActions = self::BLOCKED_ACTIONS[$name] ?? [];
            $calls = $component['calls'] ?? [];

            if (! is_array($calls)) {
                continue;
            }

            foreach ($calls as $call) {
                if (! is_array($call)) {
                    continue;
                }

                if (in_array($call['method'] ?? null, $blockedActions, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
