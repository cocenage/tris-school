<?php

namespace App\Http\Middleware;

use App\Services\RolePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EndRolePreviewOnFilamentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(RolePreviewContext::class);

        if ($context->isActive()) {
            $context->clear();
        }

        return $next($request);
    }
}
