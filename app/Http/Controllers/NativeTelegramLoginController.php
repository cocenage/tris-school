<?php

namespace App\Http\Controllers;

use App\Services\NativeTelegramTokenVerifier;
use App\Services\TelegramAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NativeTelegramLoginController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless(app()->environment('mobiledev', 'testing') && config('native_telegram.enabled'), 404);
        abort_if($request->user(), 409);
        abort_unless($request->isJson(), 415);
    }

    public function start(Request $request)
    {
        $this->guard($request);
        $client = (string) config('native_telegram.client_id');
        abort_unless(preg_match('/^[1-9][0-9]{0,19}$/D', $client), 503);
        $attempt = bin2hex(random_bytes(32));
        $request->session()->put('native_telegram_attempt', [
            'secret' => $attempt,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);

        return response()->json(['attempt' => $attempt, 'client_id' => $client])
            ->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request, NativeTelegramTokenVerifier $verifier, TelegramAuthService $auth)
    {
        $this->guard($request);
        $attempt = $request->session()->pull('native_telegram_attempt');
        $secret = $request->input('attempt');
        $token = $request->input('id_token');
        abort_unless(is_array($attempt)
            && is_string($secret)
            && hash_equals($attempt['secret'], $secret)
            && $attempt['expires_at'] >= now()->timestamp, 403);
        abort_unless(is_string($token) && $token !== '' && strlen($token) <= 16384, 422);

        try {
            $verified = $verifier->verify($token);
        } catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException | \JsonException) {
            return response()->json(['message' => 'Telegram не подтвердил вход. Начните авторизацию заново.'], 401)
                ->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            // Do not report exceptions containing ID tokens or provider response bodies.
            return response()->json(['message' => 'Проверка Telegram временно недоступна.'], 503)
                ->header('Cache-Control', 'no-store');
        }

        // The current official Android SDK does not expose a nonce. This
        // short-lived, one-use bearer exchange is deliberately DEV-only.
        $inserted = DB::transaction(function () use ($verified) {
            DB::table('native_telegram_token_uses')->where('expires_at', '<=', now())->delete();
            return DB::table('native_telegram_token_uses')->insertOrIgnore([
                'digest' => $verified['digest'],
                'expires_at' => now()->setTimestamp($verified['expires_at']),
            ]);
        });
        abort_unless($inserted === 1, 409, 'Токен уже использован.');

        $user = $auth->loginOrCreate($verified['profile']);

        return response()->json([
            'ok' => true,
            'redirect' => route($user->accessRouteName(), [], false),
        ])->header('Cache-Control', 'no-store');
    }
}
