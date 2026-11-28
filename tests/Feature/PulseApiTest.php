<?php

use App\Models\User;
use App\Services\Pulse\PulseService;
use App\Services\RolePreviewContext;

it('returns only the signed-in user snapshot and disables HTTP caching', function () {
    $user = new User(['name' => 'Коке', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $user->id = 1;
    $this->mock(PulseService::class, function ($mock) use ($user): void {
        $mock->shouldReceive('snapshot')->once()->with($user)->andReturn(['segments' => [], 'schema_version' => 1]);
    });
    $this->actingAs($user)->getJson('/focus/pulse?user_id=999')
        ->assertOk()->assertJsonPath('schema_version', 1)->assertHeader('Cache-Control', 'no-store, private');
});

it('denies inactive accounts and role previews before collecting context', function () {
    $user = new User(['role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $user->id = 1;
    $this->actingAs($user)->withSession([RolePreviewContext::SESSION_KEY => 'cleaner'])
        ->getJson('/focus/pulse')->assertForbidden();
    $user->is_active = false;
    $this->getJson('/focus/pulse')->assertForbidden();
});
