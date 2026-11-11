<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SafeguardRolePreviewData
{
    public function handle(Request $request, Closure $next): Response
    {
        // The authenticated user's existing policies and queries remain authoritative in preview.
        // This middleware must not replace real pages with a synthetic empty state.
        return $next($request);
    }
}
