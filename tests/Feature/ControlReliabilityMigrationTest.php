<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$migrationTestOwnsTables = false;

beforeEach(function () use (&$migrationTestOwnsTables): void {
    foreach (['control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        if (Schema::hasTable($table)) {
            throw new RuntimeException("Unexpected table exists in the isolated migration test database: {$table}");
        }
    }

    $migrationTestOwnsTables = true;

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('controls', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    foreach ([
        '2026_04_20_185541_create_control_response_drafts_table.php',
        '2026_04_20_185550_create_control_responses_table.php',
        '2026_06_09_215456_add_control_score_details_to_control_responses_table.php',
    ] as $migrationFile) {
        $migration = require base_path('database/migrations/'.$migrationFile);
        $migration->up();
    }
});

afterEach(function () use (&$migrationTestOwnsTables): void {
    if (! $migrationTestOwnsTables) {
        return;
    }

    foreach (['control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    $migrationTestOwnsTables = false;
});

it('adds and rolls back reliability columns on a clean legacy schema', function (): void {
    $migration = require base_path('database/migrations/2026_11_04_000001_add_focus_control_run_reliability_columns.php');
    $migration->up();

    expect(Schema::hasColumns('control_response_drafts', ['schema_version', 'run_id', 'revision', 'state']))
        ->toBeTrue()
        ->and(Schema::hasColumns('control_responses', ['schema_version', 'submission_token']))
        ->toBeTrue();

    $migration->down();

    expect(Schema::hasColumns('control_response_drafts', ['schema_version', 'run_id', 'revision', 'state']))
        ->toBeFalse()
        ->and(Schema::hasColumns('control_responses', ['schema_version', 'submission_token']))
        ->toBeFalse();
});

it('preserves legacy rows, applies safe defaults, enforces unique run keys, and rolls back without losing rows', function (): void {
    $userId = DB::table('users')->insertGetId(['name' => 'Test user']);
    $controlId = DB::table('controls')->insertGetId(['name' => 'Test control']);
    $apartmentId = DB::table('apartments')->insertGetId(['name' => 'Test apartment']);
    $responses = json_encode([['selected' => 'yes']], JSON_THROW_ON_ERROR);
    $schemaSnapshot = json_encode([['title' => 'Entry']], JSON_THROW_ON_ERROR);
    $timestamp = now();

    $draftId = DB::table('control_response_drafts')->insertGetId([
        'control_id' => $controlId,
        'supervisor_id' => $userId,
        'cleaner_id' => $userId,
        'apartment_id' => $apartmentId,
        'responses' => $responses,
        'schema_snapshot' => $schemaSnapshot,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $responseId = DB::table('control_responses')->insertGetId([
        'control_id' => $controlId,
        'supervisor_id' => $userId,
        'cleaner_id' => $userId,
        'apartment_id' => $apartmentId,
        'responses' => $responses,
        'schema_snapshot' => $schemaSnapshot,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $migration = require base_path('database/migrations/2026_11_04_000001_add_focus_control_run_reliability_columns.php');
    $migration->up();

    $draft = DB::table('control_response_drafts')->find($draftId);
    $response = DB::table('control_responses')->find($responseId);

    expect($draft->responses)->toBe($responses)
        ->and($draft->schema_snapshot)->toBe($schemaSnapshot)
        ->and($draft->schema_version)->toBeNull()
        ->and($draft->run_id)->toBeNull()
        ->and((int) $draft->revision)->toBe(0)
        ->and($draft->state)->toBe('active')
        ->and($response->responses)->toBe($responses)
        ->and($response->schema_snapshot)->toBe($schemaSnapshot)
        ->and($response->schema_version)->toBeNull()
        ->and($response->submission_token)->toBeNull();

    $runId = (string) Str::uuid();
    DB::table('control_response_drafts')->where('id', $draftId)->update(['run_id' => $runId]);
    expect(fn () => DB::table('control_response_drafts')->insert(['control_id' => $controlId, 'run_id' => $runId]))
        ->toThrow(QueryException::class);
    DB::table('control_response_drafts')->insert(['control_id' => $controlId, 'run_id' => null]);

    $submissionToken = (string) Str::uuid();
    DB::table('control_responses')->where('id', $responseId)->update(['submission_token' => $submissionToken]);
    expect(fn () => DB::table('control_responses')->insert(['control_id' => $controlId, 'submission_token' => $submissionToken]))
        ->toThrow(QueryException::class);
    DB::table('control_responses')->insert(['control_id' => $controlId, 'submission_token' => null]);

    $migration->down();

    expect(DB::table('control_response_drafts')->count())->toBe(2)
        ->and(DB::table('control_responses')->count())->toBe(2)
        ->and(DB::table('control_response_drafts')->where('id', $draftId)->value('responses'))->toBe($responses)
        ->and(DB::table('control_responses')->where('id', $responseId)->value('responses'))->toBe($responses)
        ->and(Schema::hasColumn('control_response_drafts', 'run_id'))->toBeFalse()
        ->and(Schema::hasColumn('control_responses', 'submission_token'))->toBeFalse();
});
