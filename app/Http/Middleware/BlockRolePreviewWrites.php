<?php

namespace App\Http\Middleware;

use App\Services\RolePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockRolePreviewWrites
{
    private const SAFE_ACTIONS = [
        'page-coachings' => ['nextPage', 'previousPage', 'gotoPage', 'setPage'],
        'profile.page-checks' => ['nextPage', 'previousPage', 'gotoPage', 'setPage'],
        'profile.page-employee-history' => ['nextPage', 'previousPage', 'gotoPage', 'setPage'],
        'tasks.page-calendar' => ['openDay'],
    ];

    private const SAFE_UPDATES = [
        'page-coachings' => ['employeeFilter', 'dateFrom', 'dateTo', 'paginators.page'],
        'profile.page-checks' => ['tab', 'activityType', 'paginators.page'],
        'profile.page-all-checks' => ['search', 'activityPeriod', 'activityFilter', 'workPeriod'],
        'profile.page-employee-history' => ['activityType', 'dateFrom', 'dateTo', 'paginators.page'],
        'tasks.page-tasks' => ['boardFilterId'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app(RolePreviewContext::class)->isActive()) {
            return $next($request);
        }

        if ($request->routeIs('role-preview.store', 'role-preview.clear')
            || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (! $request->routeIs('role-preview.livewire.update')) {
            abort(403, 'Действия недоступны в режиме предпросмотра.');
        }

        $components = $request->input('components');

        if (! is_array($components) || $components === [] || $this->containsWriteOperation($components)) {
            abort(403, 'Действия недоступны в режиме предпросмотра.');
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

            if ($this->containsUnsafeUpdates($name, $updates)) {
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

    private function containsUnsafeUpdates(string $component, array $updates): bool
    {
        $safeProperties = self::SAFE_UPDATES[$component] ?? [];

        foreach (array_keys($updates) as $property) {
            if (! is_string($property) || ! in_array($property, $safeProperties, true)) {
                return true;
            }
        }

        return false;
    }
}
