<?php

namespace App\Services\MobilePush;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushService
{
    public static function enabled(): bool
    {
        return app()->environment('mobiledev', 'testing') && (bool) config('mobile_push.enabled');
    }

    public static function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    public function register(Request $request, string $token): void
    {
        $hash = hash('sha256', $token);
        $session = self::sessionHash($request);
        $user = $request->user();
        DB::transaction(function () use ($user, $hash, $session, $token): void {
            // A rotated token replaces the old token of the same app session.
            DB::table('mobile_push_devices')->where('user_id', $user->id)
                ->where('session_hash', $session)->where('token_hash', '!=', $hash)
                ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('mobile_push_devices')->upsert([[
                'user_id' => $user->id, 'token_hash' => $hash,
                'token' => Crypt::encryptString($token), 'session_hash' => $session,
                'last_seen_at' => now(), 'revoked_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['token_hash'], ['user_id', 'token', 'session_hash', 'last_seen_at', 'revoked_at', 'updated_at']);
        });
        $request->session()->put('mobile_push_opted_in', true);
    }

    public function revoke(Request $request): void
    {
        if (! self::enabled() || ! $request->user()) {
            return;
        }
        DB::table('mobile_push_devices')->where('user_id', $request->user()->id)
            ->where('session_hash', self::sessionHash($request))->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        $request->session()->forget('mobile_push_opted_in');
    }

    public function sendTest(int $deviceId, FcmTransport $transport): string
    {
        $device = DB::table('mobile_push_devices')->where('id', $deviceId)
            ->whereNull('revoked_at')->where('last_seen_at', '>=', now()->subDay())->first();
        abort_unless($device && User::query()->activeStaff()->whereKey($device->user_id)->exists(), 409, 'Устройство неактивно.');
        $id = (string) Str::uuid();
        DB::table('mobile_push_notifications')->insert([
            'id' => $id, 'user_id' => $device->user_id, 'device_id' => $device->id,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $result = $transport->send(Crypt::decryptString($device->token), $id);
        } catch (\Throwable) {
            $result = ['status' => 'failed', 'error' => 'FCM_TRANSPORT_FAILED'];
        }
        DB::table('mobile_push_notifications')->where('id', $id)->update([
            'status' => $result['status'], 'error_code' => $result['error'], 'updated_at' => now(),
        ]);
        if ($result['error'] === 'UNREGISTERED') {
            DB::table('mobile_push_devices')->where('id', $device->id)
                ->where('token', $device->token)->update(['revoked_at' => now(), 'updated_at' => now()]);
        }

        return $result['status'];
    }
}
