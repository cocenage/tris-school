<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TelegramUserNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessRegistrationController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->accessRouteName() !== 'access.registration') {
            return redirect()->route($user->accessRouteName());
        }

        return view('auth.registration', ['user' => $user]);
    }

    public function store(Request $request, TelegramUserNotificationService $notifications)
    {
        $user = $request->user();

        if ($user->accessRouteName() !== 'access.registration') {
            return redirect()->route($user->accessRouteName());
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birthday' => ['required', 'date_format:Y-m-d', 'before:today'],
            'phone' => ['required', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'requested_role' => ['required', 'in:cleaner,supervisor'],
            'city' => ['required', 'string', 'max:120'],
            'work_started_at' => ['required', 'date_format:Y-m-d'],
            'supervisor' => ['required', 'string', 'max:150'],
        ]);

        [$user, $submitted] = DB::transaction(function () use ($user, $data): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->accessRouteName() !== 'access.registration') {
                return [$locked, false];
            }

            $locked->forceFill([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'birthday' => $data['birthday'],
                'work_started_at' => $data['work_started_at'],
                'registration_data' => $data,
                'registration_submitted_at' => now(),
                'status' => 'pending',
            ])->save();

            // requested_role is application data, not a grant of permissions.
            return [$locked, true];
        });

        if ($submitted) {
            try {
                $notifications->accessRequested($user);
            } catch (\Throwable $exception) {
                report($exception);
                // Keep the persisted application. The existing Telegram login
                // path retries an unnotified pending application.
            }
        }

        return redirect()->route($user->accessRouteName());
    }
}
