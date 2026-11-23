<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFocusV2PreviewEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // Rollout switch, including emergency rollback. Access is still
        // limited to authenticated active admins/supervisors by controllers
        // and route policies; this flag does not grant permissions.
        abort_unless(config('focus-v2.enabled', false), 404);

        return $next($request);
    }
}
