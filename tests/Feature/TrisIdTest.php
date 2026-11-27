<?php

use App\Models\User;
use Tests\Support\TrisAuthTestDatabase;

beforeEach(function (): void {
    TrisAuthTestDatabase::migrate();
});

it('generates distinct public identifiers for newly created users', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    expect($first->tris_id)->toMatch('/^[1-9][0-9]{7}$/')
        ->and($second->tris_id)->toMatch('/^[1-9][0-9]{7}$/')
        ->and($first->tris_id)->not->toBe($second->tris_id);
});

it('preserves the identifier and primary key when an employee changes role', function () {
    $user = User::factory()->create(['role' => 'cleaner', 'status' => 'pending']);
    $id = $user->id;
    $trisId = $user->tris_id;

    $user->update(['role' => 'supervisor']);

    expect($user->fresh()->id)->toBe($id)
        ->and($user->fresh()->tris_id)->toBe($trisId);
});

it('rejects attempts to change a previously allocated identifier through the model', function () {
    $user = User::factory()->create();
    $user->forceFill(['tris_id' => $user->tris_id === '11111111' ? '22222222' : '11111111']);

    expect(fn () => $user->save())->toThrow(LogicException::class);
});
