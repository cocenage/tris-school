<?php

use App\Models\DeviceLinkRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not authenticate a requester just for knowing a TRIS ID', function () {
    $user = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $this->post(route('access.connect.start'), ['tris_id' => $user->tris_id])
        ->assertRedirect(route('access.connect'));
    $this->post(route('access.connect.claim'))->assertRedirect(route('access.connect'));
    $this->assertGuest();
});

it('requires ownership and the comparison code for approval', function () {
    $owner = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $other = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $link = DeviceLinkRequest::create([
        'user_id' => $owner->id, 'secret_hash' => hash('sha256', 'test-secret'),
        'comparison_code' => '123456', 'status' => 'pending', 'expires_at' => now()->addMinutes(5),
    ]);
    $this->actingAs($other)->post(route('profile.devices.decision', $link), ['decision' => 'approve', 'comparison_code' => '123456'])->assertNotFound();
    $this->actingAs($owner)->post(route('profile.devices.decision', $link), ['decision' => 'approve', 'comparison_code' => '654321'])->assertStatus(422);
    $this->actingAs($owner)->post(route('profile.devices.decision', $link), ['decision' => 'approve', 'comparison_code' => '123456'])->assertRedirect(route('profile.devices'));
    expect($link->fresh()->status)->toBe('approved');
});

it('consumes only an approved challenge bound to the requesting session', function () {
    $user = User::factory()->create(['status' => 'approved', 'is_active' => true]);
    $link = DeviceLinkRequest::create([
        'user_id' => $user->id, 'secret_hash' => hash('sha256', 'test-secret'),
        'comparison_code' => '123456', 'status' => 'approved', 'expires_at' => now()->addMinutes(5),
    ]);
    $this->withSession(['tris_device_link' => ['id' => $link->id, 'secret' => 'wrong']])
        ->post(route('access.connect.claim'))->assertRedirect(route('access.connect'));
    $this->assertGuest();
    $this->withSession(['tris_device_link' => ['id' => $link->id, 'secret' => 'test-secret']])
        ->post(route('access.connect.claim'))->assertRedirect(route('page-home'));
    $this->assertAuthenticatedAs($user);
    expect($link->fresh()->status)->toBe('consumed');
});
