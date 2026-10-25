<?php

namespace App\Http\Controllers;

use App\Services\RolePreviewContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RolePreviewController extends Controller
{
    public function store(Request $request, RolePreviewContext $context): RedirectResponse
    {
        abort_unless($context->actualRole() === 'admin' && auth()->user()?->status === 'approved', 403);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'supervisor', 'cleaner'])],
        ]);

        $context->start($validated['role']);

        return redirect()->route('page-profile');
    }

    public function clear(RolePreviewContext $context): RedirectResponse
    {
        $context->clear();

        return redirect()->route('page-profile');
    }
}
