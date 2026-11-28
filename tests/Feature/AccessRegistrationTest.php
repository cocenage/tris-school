<?php

use App\Models\User;
use App\Services\TelegramUserNotificationService;
use Tests\Support\TrisAuthTestDatabase;

beforeEach(function (): void {
    TrisAuthTestDatabase::migrate();
});

it('requires the full registration before sending an access request', function () {
    $user = User::factory()->create(['status' => 'new', 'role' => 'cleaner', 'is_active' => true]);
    $this->mock(TelegramUserNotificationService::class, function ($mock) {
        $mock->shouldNotReceive('accessRequested');
    });

    $this->actingAs($user)->post(route('access.registration.store'), [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'birthday', 'requested_role', 'city', 'work_started_at', 'supervisor']);

    expect($user->fresh()->status)->toBe('new');
});

it('submits one application without a phone and never grants the requested role', function () {
    $user = User::factory()->create(['status' => 'new', 'role' => 'cleaner', 'is_active' => true]);
    $this->mock(TelegramUserNotificationService::class, function ($mock) {
        $mock->shouldReceive('accessRequested')->once();
    });

    $data = [
        'first_name' => 'Anna', 'last_name' => 'Test', 'birthday' => '1998-05-12',
        'requested_role' => 'supervisor', 'city' => 'Milano',
        'work_started_at' => '2026-12-01', 'supervisor' => 'Maria',
    ];

    $this->actingAs($user)->post(route('access.registration.store'), $data)
        ->assertRedirect(route('access.pending'));

    $user = $user->fresh();
    expect($user->status)->toBe('pending')
        ->and($user->role)->toBe('cleaner')
        ->and($user->registration_data['requested_role'])->toBe('supervisor')
        ->and($user->registration_data)->not->toHaveKey('phone')
        ->and($user->registration_submitted_at)->not->toBeNull();

    $this->actingAs($user)->post(route('access.registration.store'), $data)
        ->assertRedirect(route('access.pending'));
});

it('does not show or collect a phone number in the registration form', function () {
    $user = User::factory()->create(['status' => 'new', 'role' => 'cleaner', 'is_active' => true]);
    $this->actingAs($user)->get(route('access.registration'))
        ->assertOk()->assertDontSee('name="phone"', false);
    $this->mock(TelegramUserNotificationService::class, function ($mock) {
        $mock->shouldReceive('accessRequested')->once();
    });
    $this->post(route('access.registration.store'), [
        'first_name' => 'Anna', 'last_name' => 'Test', 'birthday' => '1998-05-12',
        'phone' => 'ignored legacy field', 'requested_role' => 'cleaner', 'city' => 'Milano',
        'work_started_at' => '2026-12-01', 'supervisor' => 'Maria',
    ])->assertRedirect(route('access.pending'));
    expect($user->fresh()->registration_data)->not->toHaveKey('phone');
});
