<?php

namespace App\Http\Controllers;

use App\Models\DeviceLinkRequest;
use App\Models\User;
use App\Services\RolePreviewContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeviceLinkController extends Controller
{
    private const SESSION_KEY = 'tris_device_link';

    public function show(Request $request)
    {
        if ($request->user()) {
            return redirect()->route($request->user()->accessRouteName());
        }

        return response()->view('auth.connect', ['link' => $this->sessionLink($request)])
            ->header('Cache-Control', 'no-store');
    }

    public function start(Request $request)
    {
        abort_if($request->user(), 403);
        $data = $request->validate(['tris_id' => ['required', 'regex:/^[1-9][0-9]{7}$/']]);
        $secret = bin2hex(random_bytes(32));
        $user = User::query()->activeStaff()->where('tris_id', $data['tris_id'])->first();

        // An unknown or inactive ID receives the same response.
        if ($previous = $this->sessionLink($request)) {
            DeviceLinkRequest::query()->whereKey($previous->id)
                ->whereIn('status', ['pending', 'approved'])->update(['status' => 'rejected']);
        }

        $link = DeviceLinkRequest::create([
            'user_id' => $user?->id,
            'secret_hash' => hash('sha256', $secret),
            'comparison_code' => (string) random_int(100000, 999999),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
        ]);
        $request->session()->put(self::SESSION_KEY, ['id' => $link->id, 'secret' => $secret]);

        return redirect()->route('access.connect');
    }

    public function requests(Request $request)
    {
        app(RolePreviewContext::class)->assertWritable();
        $links = DeviceLinkRequest::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')->where('expires_at', '>', now())
            ->latest()->limit(20)->get();

        return response()->view('auth.devices', ['links' => $links, 'user' => $request->user()])
            ->header('Cache-Control', 'no-store');
    }

    public function decide(Request $request, DeviceLinkRequest $link)
    {
        app(RolePreviewContext::class)->assertWritable();
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comparison_code' => ['required_if:decision,approve', 'nullable', 'digits:6'],
        ]);

        DB::transaction(function () use ($request, $link, $data): void {
            $locked = DeviceLinkRequest::query()->lockForUpdate()->findOrFail($link->id);
            abort_unless((int) $locked->user_id === (int) $request->user()->id, 404);
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 409);
            $user = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            abort_unless($user->isApproved(), 403);

            if ($data['decision'] === 'approve') {
                abort_unless(hash_equals($locked->comparison_code, (string) $data['comparison_code']), 422, 'Код не совпадает.');
            }
            $locked->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected']);
        });

        return redirect()->route('profile.devices');
    }

    public function claim(Request $request)
    {
        abort_if($request->user(), 403);
        $user = DB::transaction(function () use ($request): ?User {
            $link = $this->sessionLink($request, true);
            if (! $link || $link->status !== 'approved' || ! $link->expires_at->isFuture()) {
                return null;
            }
            $user = User::query()->lockForUpdate()->find($link->user_id);
            if (! $user?->isApproved()) {
                $link->update(['status' => 'rejected']);
                return null;
            }
            $link->update(['status' => 'consumed']);
            return $user;
        });
        if (! $user) {
            return redirect()->route('access.connect');
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('page-home');
    }

    private function sessionLink(Request $request, bool $lock = false): ?DeviceLinkRequest
    {
        $session = $request->session()->get(self::SESSION_KEY);
        if (! is_array($session) || ! isset($session['id'], $session['secret'])) {
            return null;
        }
        $query = DeviceLinkRequest::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $link = $query->find($session['id']);
        if (! $link || ! hash_equals($link->secret_hash, hash('sha256', $session['secret']))) {
            return null;
        }

        return $link;
    }
}
