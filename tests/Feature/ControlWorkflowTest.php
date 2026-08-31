<?php

use App\Models\Control;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    foreach (['activity_log', 'control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->string('telegram_avatar_path')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    Schema::create('controls', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('name');
        $table->string('slug')->unique();
        $table->json('main')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('image')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
    });

    Schema::create('control_response_drafts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('control_id');
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->unsignedBigInteger('cleaner_id')->nullable();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->boolean('is_assigned')->default(false);
        $table->string('previous_cleaner')->nullable();
        $table->date('cleaning_date')->nullable();
        $table->date('inspection_date')->nullable();
        $table->text('comment')->nullable();
        $table->json('responses')->nullable();
        $table->json('schema_snapshot')->nullable();
        $table->timestamps();
    });

    Schema::create('control_responses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('control_id');
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->unsignedBigInteger('cleaner_id')->nullable();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->boolean('is_assigned')->default(false);
        $table->string('previous_cleaner')->nullable();
        $table->date('cleaning_date')->nullable();
        $table->date('inspection_date')->nullable();
        $table->text('comment')->nullable();
        $table->json('responses')->nullable();
        $table->json('schema_snapshot')->nullable();
        $table->unsignedInteger('total_points')->default(0);
        $table->unsignedInteger('max_points')->default(0);
        $table->unsignedTinyInteger('score_percent')->default(0);
        $table->unsignedInteger('penalty_points')->default(0);
        $table->unsignedInteger('errors_count')->default(0);
        $table->boolean('has_critical_failure')->default(false);
        $table->string('result_zone')->nullable();
        $table->text('result_zone_reason')->nullable();
        $table->string('status')->default('sent');
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();
    });

    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->unsignedBigInteger('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->string('event')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    foreach (['activity_log', 'control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function controlWorkflowSchema(): array
{
    return [[
        'title' => 'Вход',
        'items' => [[
            'question' => 'Ключи на месте?',
            'answer_type' => 'options',
            'answer_options_scored' => [
                ['value' => 'yes', 'label' => 'Да', 'points' => 1],
                ['value' => 'no', 'label' => 'Нет', 'points' => 0],
            ],
        ]],
    ]];
}

function mountControlWorkflow(): array
{
    $supervisor = User::create(['name' => 'Supervisor', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $cleaner = User::create(['name' => 'Cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $apartment = DB::table('apartments')->insertGetId(['name' => 'Apartment', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $control = Control::create(['name' => 'Control', 'slug' => 'control-workflow', 'main' => controlWorkflowSchema(), 'is_active' => true]);

    test()->actingAs($supervisor);

    return [$supervisor, $cleaner, $apartment, $control];
}

it('requires all corrective fields for a negative answer on the server', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'no')
        ->call('openReview')
        ->assertHasErrors('answers.0.0')
        ->assertSet('reviewSheetOpen', false);
});

it('persists corrective fields in a draft and restores them on reload', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'no')
        ->set('answers.0.0.corrective.repeats', true)
        ->set('answers.0.0.corrective.action', 'Проверить замок ещё раз')
        ->set('answers.0.0.corrective.recheck', false)
        ->call('saveDraft');

    expect(json_decode((string) DB::table('control_response_drafts')->value('responses'), true)[0][0]['corrective'])
        ->toMatchArray(['repeats' => true, 'action' => 'Проверить замок ещё раз', 'recheck' => false]);

    Livewire::test('forms.page-control')
        ->assertSet('answers.0.0.corrective.repeats', true)
        ->assertSet('answers.0.0.corrective.action', 'Проверить замок ещё раз')
        ->assertSet('answers.0.0.corrective.recheck', false);
});

it('saves corrective fields for the matching problem on submit', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'no')
        ->set('answers.0.0.corrective.repeats', false)
        ->set('answers.0.0.corrective.action', 'Исправить замок')
        ->set('answers.0.0.corrective.recheck', true)
        ->call('openReview')
        ->assertSet('reviewSheetOpen', true)
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $responses = json_decode((string) DB::table('control_responses')->value('responses'), true);

    expect($responses[0][0]['corrective'])
        ->toMatchArray(['repeats' => false, 'action' => 'Исправить замок', 'recheck' => true]);
});
