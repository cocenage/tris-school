<?php

use App\Models\User;
use App\Services\MobilePush\FcmTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TrisAuthTestDatabase;

beforeEach(function (): void {
    TrisAuthTestDatabase::migrate();
    $migration = require database_path('migrations/2026_11_28_000200_create_mobile_push_tables.php');
    $migration->up();
    config(['mobile_push.enabled' => true]);
});

it('keeps push endpoints off until explicitly enabled', function () {
    config(['mobile_push.enabled' => false]);
    $user = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $this->actingAs($user)->postJson(route('mobile.push.register'), ['token' => str_repeat('a', 80)])->assertNotFound();
});

it('requires an approved active employee and encrypts tokens', function () {
    $token = str_repeat('a', 80);
    $pending = User::factory()->create(['status' => 'pending', 'is_active' => true]);
    $this->actingAs($pending)->postJson(route('mobile.push.register'), ['token' => $token])->assertForbidden();
    $user = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $this->actingAs($user)->postJson(route('mobile.push.register'), ['token' => $token, 'user_id' => $pending->id])->assertOk();
    $device = DB::table('mobile_push_devices')->first();
    expect((int) $device->user_id)->toBe($user->id)
        ->and($device->token)->not->toBe($token)
        ->and(Crypt::decryptString($device->token))->toBe($token);
    $this->postJson(route('mobile.push.register'), ['token' => $token])->assertOk();
    expect(DB::table('mobile_push_devices')->count())->toBe(1);
});

it('rotates tokens and revokes the session registration on logout', function () {
    $user = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $this->actingAs($user)->postJson(route('mobile.push.register'), ['token' => str_repeat('b', 80)])->assertOk();
    $this->postJson(route('mobile.push.register'), ['token' => str_repeat('c', 80)])->assertOk();
    expect(DB::table('mobile_push_devices')->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('mobile_push_devices')->count())->toBe(2);
    $this->post(route('logout'))->assertRedirect(route('landing.page'));
    expect(DB::table('mobile_push_devices')->whereNull('revoked_at')->count())->toBe(0);
});

it('limits test sends to administrators and records acceptance without claiming delivery', function () {
    $token = str_repeat('d', 80);
    $employee = User::factory()->create(['status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $this->actingAs($employee)->postJson(route('mobile.push.register'), ['token' => $token])->assertOk();
    $device = DB::table('mobile_push_devices')->first();
    $this->postJson(route('mobile.push.send'), ['device_id' => $device->id])->assertForbidden();

    $admin = User::factory()->create(['status' => 'approved', 'role' => 'admin', 'is_active' => true]);
    $this->mock(FcmTransport::class, function ($mock) use ($token) {
        $mock->shouldReceive('send')->once()->with($token, Mockery::on(fn ($id) => Str::isUuid($id)))
            ->andReturn(['status' => 'accepted', 'error' => null]);
    });
    $this->actingAs($admin)->from(route('mobile.push.admin'))->post(route('mobile.push.send'), ['device_id' => $device->id])
        ->assertRedirect(route('mobile.push.admin'));
    $notice = DB::table('mobile_push_notifications')->first();
    expect($notice->status)->toBe('accepted')->and($notice->received_at)->toBeNull()->and($notice->opened_at)->toBeNull();

    // Another employee cannot submit delivery telemetry for this notification.
    $this->postJson(route('mobile.push.event'), ['id' => $notice->id, 'event' => 'opened'])->assertOk();
    expect(DB::table('mobile_push_notifications')->where('id', $notice->id)->value('opened_at'))->toBeNull();
    $this->actingAs($employee)->postJson(route('mobile.push.event'), ['id' => $notice->id, 'event' => 'opened'])->assertOk();
    expect(DB::table('mobile_push_notifications')->where('id', $notice->id)->value('opened_at'))->not->toBeNull();
});

it('refuses sends to disabled employees', function () {
    $employee = User::factory()->create(['status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $this->actingAs($employee)->postJson(route('mobile.push.register'), ['token' => str_repeat('e', 80)])->assertOk();
    $device = DB::table('mobile_push_devices')->first();
    $employee->update(['is_active' => false]);
    $admin = User::factory()->create(['status' => 'approved', 'role' => 'admin', 'is_active' => true]);
    $this->mock(FcmTransport::class, fn ($mock) => $mock->shouldNotReceive('send'));
    $this->actingAs($admin)->postJson(route('mobile.push.send'), ['device_id' => $device->id])->assertStatus(409);
});
