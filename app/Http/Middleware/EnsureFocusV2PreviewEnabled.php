<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFocusV2PreviewEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // The experimental preview must never become public on production
        // because an environment flag was accidentally enabled.
        abort_unless(config('focus-v2.enabled', false) && ! app()->environment('production'), 404);

        return $next($request);
    }
}
