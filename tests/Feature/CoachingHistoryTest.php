<?php

use App\Models\Coaching;
use App\Models\User;
use App\Services\ProfileActivityTimeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.key' => str_repeat('t', 32)]);
    foreach (['coachings', 'control_responses', 'controls', 'apartments', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->date('work_started_at')->nullable();
        $table->timestamps();
    });
    foreach (['controls', 'apartments'] as $tableName) {
        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
    }
    Schema::create('control_responses', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('cleaner_id');
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->unsignedBigInteger('control_id')->nullable();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->date('inspection_date')->nullable();
        $table->date('cleaning_date')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->text('comment')->nullable();
        $table->integer('score_percent')->nullable();
        $table->integer('total_points')->default(0);
        $table->integer('max_points')->default(0);
        $table->string('result_zone')->nullable();
        $table->timestamps();
    });
    (require database_path('migrations/2026_10_24_000000_create_coachings_table.php'))->up();
    $this->cleaner = User::create(['name' => 'Timeline cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $this->supervisor = User::create(['name' => 'Timeline supervisor', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
});

afterEach(function () {
    foreach (['coachings', 'control_responses', 'controls', 'apartments', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function historyFixture(int $cleaner, int $supervisor, string $type, string $date, string $text): int
{
    return DB::table($type === 'control' ? 'control_responses' : 'coachings')->insertGetId([
        $type === 'control' ? 'cleaner_id' : 'user_id' => $cleaner,
        'supervisor_id' => $supervisor, 'inspection_date' => $date,
        $type === 'control' ? 'comment' : 'reason' => $text,
    ]);
}

it('merges control and coaching history with stable SQL pagination and type and date filters', function () {
    historyFixture($this->cleaner->id, $this->supervisor->id, 'control', '2026-10-22', 'Earlier');
    $control = historyFixture($this->cleaner->id, $this->supervisor->id, 'control', '2026-10-24', 'Latest control');
    $coaching = historyFixture($this->cleaner->id, $this->supervisor->id, 'coaching', '2026-10-24', 'Latest coaching');
    $timeline = app(ProfileActivityTimeline::class);
    $first = $timeline->paginateForCleaner($this->cleaner->id, perPage: 1);
    expect($first->total())->toBe(3)->and($first->first()->item_type)->toBe('coaching')->and($first->first()->id)->toBe($coaching);
    Paginator::currentPageResolver(fn () => 2);
    $second = $timeline->paginateForCleaner($this->cleaner->id, perPage: 1);
    expect($second->first()->item_type)->toBe('control')->and($second->first()->id)->toBe($control);
    Paginator::currentPageResolver(fn () => 1);
    expect($timeline->paginateForCleaner($this->cleaner->id, 'coaching')->total())->toBe(1)
        ->and($timeline->paginateForCleaner($this->cleaner->id, 'control')->total())->toBe(2)
        ->and($timeline->paginateForCleaner($this->cleaner->id, dateFrom: '2026-10-24', dateTo: '2026-10-24')->total())->toBe(2);
});

it('keeps the cleaner history scoped to the logged-in person and retains the conducted control tab', function () {
    $other = User::create(['name' => 'Other cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    historyFixture($this->cleaner->id, $this->supervisor->id, 'coaching', '2026-10-24', 'Visible coaching');
    historyFixture($other->id, $this->supervisor->id, 'coaching', '2026-10-24', 'Private coaching');
    historyFixture($this->cleaner->id, $this->supervisor->id, 'control', '2026-10-23', 'Visible control');
    Livewire::actingAs($this->cleaner)->test('profile.page-checks')
        ->assertSee('Visible coaching')->assertSee('Visible control')->assertDontSee('Private coaching')
        ->set('activityType', 'coaching')->assertSee('Visible coaching')->assertDontSee('Visible control');
    Livewire::actingAs($this->supervisor)->test('profile.page-checks')->set('tab', 'conducted')->assertSee('Visible control');
});

it('allows only administrators to open an employee combined history', function () {
    historyFixture($this->cleaner->id, $this->supervisor->id, 'coaching', '2026-10-24', 'Admin timeline coaching');
    $admin = User::create(['name' => 'Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    Livewire::actingAs($admin)->test('profile.page-employee-history', ['employee' => $this->cleaner])->assertSee('Admin timeline coaching');
    Livewire::actingAs($this->cleaner)->test('profile.page-employee-history', ['employee' => $this->cleaner])->assertForbidden();
    Livewire::actingAs($this->supervisor)->test('profile.page-employee-history', ['employee' => $this->cleaner])->assertForbidden();
});

it('includes coaching counts in the existing admin roster and preserves its activity filter', function () {
    historyFixture($this->cleaner->id, $this->supervisor->id, 'coaching', '2026-10-24', 'Roster coaching');
    $admin = User::create(['name' => 'Admin', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    Livewire::actingAs($admin)->test('profile.page-all-checks')
        ->assertSee('Timeline cleaner')->assertSee('Коучинги: 1')
        ->set('activityFilter', 'no_items')->assertDontSee('Timeline cleaner')->assertSee('Timeline supervisor')
        ->set('activityFilter', 'has_items')->assertSee('Timeline cleaner')->assertDontSee('Timeline supervisor');
    Livewire::actingAs($this->cleaner)->test('profile.page-all-checks')->assertForbidden();
});

it('immediately includes a newly saved coaching in the right cleaner history without changing controls', function () {
    $controlId = historyFixture($this->cleaner->id, $this->supervisor->id, 'control', '2026-10-23', 'Existing control remains');
    $before = DB::table('control_responses')->where('id', $controlId)->first();
    $admin = User::create(['name' => 'Admin author', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $other = User::create(['name' => 'Other cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);

    Livewire::actingAs($admin)->test('forms.page-coaching-create')
        ->set('cleanerId', $this->cleaner->id)
        ->set('conductedDate', '2026-10-20')
        ->set('description', 'New coaching in employee history')
        ->call('save')->assertHasNoErrors();

    $record = Coaching::query()->sole();
    Livewire::actingAs($admin)->test('profile.page-employee-history', ['employee' => $this->cleaner])
        ->assertSee('New coaching in employee history')->assertSee('Existing control remains')
        ->assertSee('20.10.2026')->assertSee('Admin author')
        ->assertSee(route('page-coachings.show', $record), false)
        ->set('activityType', 'coaching')->assertDontSee('Existing control remains')
        ->set('activityType', 'control')->assertSee('Existing control remains')->assertDontSee('New coaching in employee history');
    Livewire::actingAs($admin)->test('profile.page-employee-history', ['employee' => $other])
        ->assertDontSee('New coaching in employee history');
    expect(DB::table('control_responses')->where('id', $controlId)->first())->toEqual($before);
});
