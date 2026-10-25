<?php

namespace App\Http\Middleware;

use App\Services\RolePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockRolePreviewWrites
{
    private const SAFE_ACTIONS = [
        'tasks.page-calendar' => ['openDay'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (app(RolePreviewContext::class)->isActive()) {
            $components = $request->input('components');

            if (! is_array($components) || $components === [] || $this->containsWriteOperation($components)) {
                abort(403, 'Действия недоступны в режиме предпросмотра.');
            }
        }

        return $next($request);
    }

    public function containsWriteOperation(array $components): bool
    {
        foreach ($components as $component) {
            if (! is_array($component)) {
                return true;
            }

            $snapshot = json_decode($component['snapshot'] ?? '', true);
            $name = data_get($snapshot, 'memo.name');
            $updates = $component['updates'] ?? [];

            $calls = $component['calls'] ?? [];

            if (! is_array($snapshot) || ! is_array($updates) || ! is_array($calls)) {
                return true;
            }

            if (! is_string($name)) {
                return true;
            }

            // Fail closed for application components: a newly added action must not bypass preview safety.
            if ($updates !== []) {
                return true;
            }

            foreach ($calls as $call) {
                if (! is_array($call)
                    || ! in_array($call['method'] ?? null, self::SAFE_ACTIONS[$name] ?? [], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
