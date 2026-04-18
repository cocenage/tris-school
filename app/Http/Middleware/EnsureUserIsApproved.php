<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('landing.page');
        }

        if ($user->status !== 'approved') {
            return redirect()->route(match ($user->status) {
                'pending' => 'access.pending',
                'rejected' => 'access.rejected',
                default => 'landing.page',
            });
        }

        return $next($request);
    }
}