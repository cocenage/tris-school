<?php

use App\Services\NativeTelegramTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

it('verifies a real RSA signature and rejects altered tokens and invalid claims', function () {
    $autoload = base_path('tools/mobile/telegram-auth/vendor/autoload.php');
    if (! class_exists(JWT::class) && is_file($autoload)) {
        require_once $autoload;
    }
    if (! class_exists(JWT::class)) {
        $this->markTestSkipped('Install the isolated telegram-auth Composer dependencies to run signature tests.');
    }
    config(['native_telegram.client_id' => '123456789']);
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($private)->not->toBeFalse();
    openssl_pkey_export($private, $pem);
    $rsa = openssl_pkey_get_details($private)['rsa'];
    $base64url = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $jwks = ['keys' => [[
        'kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
        'n' => $base64url($rsa['n']), 'e' => $base64url($rsa['e']),
    ]]];
    Http::preventStrayRequests();
    Http::fake(['https://oauth.telegram.org/.well-known/jwks.json' => Http::response($jwks)]);
    $claims = [
        'iss' => 'https://oauth.telegram.org',
        'aud' => '123456789',
        'sub' => '9876543210123456789',
        'id' => 987654321,
        'iat' => time(),
        'exp' => time() + 300,
        'name' => 'Test Employee',
    ];
    $sign = fn ($data) => JWT::encode($data, $pem, 'RS256', 'test-key');
    $verifier = app(NativeTelegramTokenVerifier::class);
    $token = $sign($claims);
    expect($verifier->verify($token)['profile']['telegram_id'])->toBe('987654321');
    $parts = explode('.', $token);
    $parts[1] = $base64url(json_encode(array_replace($claims, ['id' => 123])));
    expect(fn () => $verifier->verify(implode('.', $parts)))->toThrow(UnexpectedValueException::class);
    foreach ([
        ['aud' => '999999999'],
        ['iss' => 'https://example.invalid'],
        ['iat' => time() - 121],
        ['exp' => time() - 1],
        ['id' => null],
    ] as $invalid) {
        expect(fn () => $verifier->verify($sign(array_replace($claims, $invalid))))->toThrow(UnexpectedValueException::class);
    }
});
