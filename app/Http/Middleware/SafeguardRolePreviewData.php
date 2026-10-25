<?php

namespace App\Http\Middleware;

use App\Services\RolePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\Response;

class SafeguardRolePreviewData
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(RolePreviewContext::class)->isActive()) {
            return $next($request);
        }

        $roleLabel = match (app(RolePreviewContext::class)->previewRole()) {
            'supervisor' => 'Супервайзер',
            'cleaner' => 'Клинер',
            default => 'сотрудника',
        };

        return response()->view('layouts.app', [
            'title' => 'Предпросмотр роли',
            'slot' => new HtmlString(view('components.role-preview.safe-state', [
                'roleLabel' => $roleLabel,
            ])->render()),
        ]);
    }
}
