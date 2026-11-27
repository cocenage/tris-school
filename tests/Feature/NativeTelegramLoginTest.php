<?php

use App\Models\User;
use App\Services\NativeTelegramTokenVerifier;
use App\Services\TelegramUserNotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\TrisAuthTestDatabase;

beforeEach(function (): void {
    TrisAuthTestDatabase::migrate();
    $migration = require database_path('migrations/2026_11_28_000300_create_native_telegram_token_uses_table.php');
    $migration->up();
    config(['native_telegram.enabled' => true, 'native_telegram.client_id' => '123456789']);
});

it('leaves native login disabled by default', function () {
    config(['native_telegram.enabled' => false]);
    $this->postJson(route('telegram.native.start'))->assertNotFound();
});

it('requires an unexpired attempt in the same guest session', function () {
    $this->mock(NativeTelegramTokenVerifier::class, fn ($mock) => $mock->shouldNotReceive('verify'));
    $this->postJson(route('telegram.native.complete'), [
        'attempt' => 'unissued',
        'id_token' => 'token',
    ])->assertForbidden();
    $attempt = $this->postJson(route('telegram.native.start'))->assertOk()->json('attempt');
    $this->travel(6)->minutes();
    $this->postJson(route('telegram.native.complete'), [
        'attempt' => $attempt,
        'id_token' => 'token',
    ])->assertForbidden();
    $this->assertGuest();
});

it('rejects an unverified id token without logging in', function () {
    $this->mock(NativeTelegramTokenVerifier::class, function ($mock) {
        $mock->shouldReceive('verify')->once()->with('bad-token')->andThrow(new UnexpectedValueException('Invalid signature'));
    });
    $attempt = $this->postJson(route('telegram.native.start'))->assertOk()->json('attempt');
    $this->postJson(route('telegram.native.complete'), [
        'attempt' => $attempt, 'id_token' => 'bad-token',
    ])->assertUnauthorized();
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('preserves the existing employee and rejects replay across login attempts', function () {
    $user = User::factory()->create(['telegram_id' => '987654321', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $verified = [
        'profile' => ['telegram_id' => '987654321', 'first_name' => 'Anna', 'source' => 'native_oidc'],
        'digest' => hash('sha256', 'signed-input'),
        'expires_at' => now()->addMinutes(5)->timestamp,
    ];
    $this->mock(NativeTelegramTokenVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->twice()->andReturn($verified));
    $attempt = $this->postJson(route('telegram.native.start'))->assertOk()->json('attempt');
    $this->postJson(route('telegram.native.complete'), [
        'attempt' => $attempt, 'id_token' => 'verified-token',
    ])->assertOk()->assertJsonPath('redirect', route('page-home', [], false));
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->tris_id)->toBe($user->tris_id)->and(User::query()->count())->toBe(1);
    $this->post(route('logout'))->assertRedirect();
    Auth::forgetGuards();
    $this->assertGuest();
    $second = $this->postJson(route('telegram.native.start'))->assertOk()->json('attempt');
    $this->postJson(route('telegram.native.complete'), [
        'attempt' => $second, 'id_token' => 'verified-token',
    ])->assertConflict();
    $this->assertGuest();
    expect(DB::table('native_telegram_token_uses')->count())->toBe(1);
});

it('creates a new employee without granting access or submitting their application', function () {
    $this->mock(TelegramUserNotificationService::class, fn ($mock) => $mock->shouldNotReceive('accessRequested'));
    $this->mock(NativeTelegramTokenVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn([
        'profile' => ['telegram_id' => '123456789', 'first_name' => 'Test', 'source' => 'native_oidc'],
        'digest' => hash('sha256', 'new-signed-input'),
        'expires_at' => now()->addMinutes(5)->timestamp,
    ]));
    $attempt = $this->postJson(route('telegram.native.start'))->assertOk()->json('attempt');
    $this->postJson(route('telegram.native.complete'), [
        'attempt' => $attempt, 'id_token' => 'verified-token',
    ])->assertOk()->assertJsonPath('redirect', route('access.registration', [], false));
    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->status)->toBe('new')->and($user->role)->toBe('cleaner')->and($user->tris_id)->toMatch('/^[1-9][0-9]{7}$/');
});
