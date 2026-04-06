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
            return redirect()->route('landing');
        }

        if ($user->status === 'rejected') {
            return redirect()->route('access.rejected');
        }

        if ($user->status !== 'approved' || ! $user->role) {
            return redirect()->route('access.pending');
        }

        return $next($request);
    }
}