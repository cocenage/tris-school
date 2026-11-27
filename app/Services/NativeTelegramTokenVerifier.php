<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

class NativeTelegramTokenVerifier
{
    public const ISSUER = 'https://oauth.telegram.org';

    public function verify(string $token): array
    {
        $client = (string) config('native_telegram.client_id');
        if (! preg_match('/^[1-9][0-9]{0,19}$/D', $client)) {
            throw new RuntimeException('Telegram client is not configured.');
        }

        $autoload = base_path('tools/mobile/telegram-auth/vendor/autoload.php');
        if (! class_exists(JWT::class) && is_file($autoload)) {
            require_once $autoload;
        }
        if (! class_exists(JWT::class)) {
            throw new RuntimeException('Telegram verification library is missing.');
        }

        if (strlen($token) > 16384 || ! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token)) {
            throw new UnexpectedValueException('Invalid token.');
        }
        [$headerPart, $payloadPart] = explode('.', $token);
        $header = json_decode(JWT::urlsafeB64Decode($headerPart), true, 16, JSON_THROW_ON_ERROR);
        if (($header['alg'] ?? null) !== 'RS256'
            || ! is_string($header['kid'] ?? null)
            || strlen($header['kid']) > 200
            || isset($header['crit'])) {
            throw new UnexpectedValueException('Invalid token header.');
        }

        // Fixed issuer and JWKS URL: never follow a URL supplied by the token.
        $response = Http::acceptJson()->connectTimeout(5)->timeout(10)
            ->withoutRedirecting()->get(self::ISSUER.'/.well-known/jwks.json');
        if (! $response->successful()) {
            throw new RuntimeException('Telegram keys unavailable.');
        }
        $keys = $response->json('keys');
        if (! is_array($keys)) {
            throw new RuntimeException('Invalid Telegram keys.');
        }
        $matching = array_values(array_filter($keys, fn ($key) => is_array($key)
            && ($key['kid'] ?? null) === $header['kid']
            && ($key['kty'] ?? null) === 'RSA'
            && ($key['alg'] ?? 'RS256') === 'RS256'
            && ($key['use'] ?? 'sig') === 'sig'));
        if (count($matching) !== 1) {
            throw new UnexpectedValueException('Unknown signing key.');
        }

        $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $matching], 'RS256'));
        $now = now()->timestamp;
        if (($claims['iss'] ?? null) !== self::ISSUER
            || ! in_array($claims['aud'] ?? null, [$client, (int) $client], true)
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || ! is_int($claims['iat'] ?? null) || ! is_int($claims['exp'] ?? null)
            || $claims['iat'] > $now + 10 || $claims['iat'] < $now - 120
            || $claims['exp'] <= $now || $claims['exp'] <= $claims['iat']) {
            throw new UnexpectedValueException('Invalid token claims.');
        }

        // OIDC sub and Telegram's numeric user ID are NOT interchangeable.
        $id = $claims['id'] ?? null;
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id)) {
            throw new UnexpectedValueException('Telegram profile ID is missing.');
        }

        $profile = ['telegram_id' => (string) $id, 'source' => 'native_oidc'];
        foreach (['given_name' => 'first_name', 'family_name' => 'last_name', 'preferred_username' => 'username'] as $claim => $field) {
            if (is_string($claims[$claim] ?? null) && $claims[$claim] !== '') {
                $profile[$field] = mb_substr($claims[$claim], 0, 100);
            }
        }
        if (! isset($profile['first_name']) && is_string($claims['name'] ?? null)) {
            $profile['first_name'] = mb_substr($claims['name'], 0, 100);
        }

        return [
            'profile' => $profile,
            // Hash the signed input, not a potentially noncanonical signature encoding.
            'digest' => hash('sha256', $headerPart.'.'.$payloadPart),
            'expires_at' => $claims['exp'],
        ];
    }
}
