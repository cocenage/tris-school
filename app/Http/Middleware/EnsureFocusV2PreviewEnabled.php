<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFocusV2PreviewEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('focus-v2.enabled', false), 404);

        return $next($request);
    }
}
