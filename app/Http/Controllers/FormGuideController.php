<?php

namespace App\Http\Controllers;

use App\Models\UserFormGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormGuideController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guide_key' => ['required', 'string', 'max:100'],
        ]);

        UserFormGuide::updateOrCreate(
            [
                'user_id' => $request->user()->getAuthIdentifier(),
                'form_key' => $data['guide_key'],
            ],
            [
                'seen_at' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }
}
