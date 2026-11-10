<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('control_completion_notifications');
    Schema::dropIfExists('control_responses');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('control_responses', function (Blueprint $table): void {
        $table->id();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('control_completion_notifications');
    Schema::dropIfExists('control_responses');
    Schema::dropIfExists('users');
});

it('creates one notification record per result and rolls back cleanly', function (): void {
    $migration = require base_path('database/migrations/2026_11_10_000001_create_control_completion_notifications_table.php');
    $migration->up();

    $responseId = DB::table('control_responses')->insertGetId([]);
    $notificationId = DB::table('control_completion_notifications')->insertGetId([
        'control_response_id' => $responseId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Schema::hasTable('control_completion_notifications'))->toBeTrue()
        ->and(DB::table('control_completion_notifications')->where('id', $notificationId)->value('status'))->toBe('pending')
        ->and(DB::table('control_completion_notifications')->where('id', $notificationId)->value('attempts'))->toBe(0);

    expect(fn () => DB::table('control_completion_notifications')->insert([
        'control_response_id' => $responseId,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $migration->down();

    expect(Schema::hasTable('control_completion_notifications'))->toBeFalse();
});
