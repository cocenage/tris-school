<?php

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Calendar\CalendarEventsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Schema::dropIfExists('day_off_request_days');
    Schema::dropIfExists('day_off_requests');
    Schema::dropIfExists('activity_log');
    Schema::dropIfExists('calendar_events');

    Schema::create('calendar_events', function (Blueprint $table) {
        $table->id();
        $table->string('type');
        $table->string('title');
        $table->text('description')->nullable();
        $table->date('start_date');
        $table->date('end_date')->nullable();
        $table->string('repeat_type')->default('none');
        $table->date('repeat_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('priority')->default(0);
        $table->timestamps();
    });

    Schema::create('day_off_request_days', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('day_off_request_id')->nullable();
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    Schema::create('day_off_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->text('reason');
        $table->string('status')->default('pending');
        $table->text('admin_comment')->nullable();
        $table->timestamp('submitted_at')->nullable();
        $table->timestamp('reviewed_at')->nullable();
        $table->unsignedBigInteger('reviewed_by')->nullable();
        $table->timestamp('notified_at')->nullable();
        $table->timestamps();
    });

    Schema::create('activity_log', function (Blueprint $table) {
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
    Schema::dropIfExists('day_off_request_days');
    Schema::dropIfExists('day_off_requests');
    Schema::dropIfExists('activity_log');
    Schema::dropIfExists('calendar_events');
});

function dayOffTestUser(): User
{
    $user = new User();
    $user->setAttribute('id', 1001);

    return $user;
}

it('expands active peak events and ignores inactive or non-peak events', function () {
    CalendarEvent::create([
        'type' => CalendarEvent::TYPE_PEAK,
        'title' => 'Peak range',
        'start_date' => '2026-08-25',
        'end_date' => '2026-08-26',
        'repeat_type' => CalendarEvent::REPEAT_NONE,
        'is_active' => true,
    ]);

    CalendarEvent::create([
        'type' => CalendarEvent::TYPE_PEAK,
        'title' => 'Inactive peak',
        'start_date' => '2026-08-27',
        'repeat_type' => CalendarEvent::REPEAT_NONE,
        'is_active' => false,
    ]);

    CalendarEvent::create([
        'type' => CalendarEvent::TYPE_WORKFLOW,
        'title' => 'Workflow',
        'start_date' => '2026-08-28',
        'repeat_type' => CalendarEvent::REPEAT_NONE,
        'is_active' => true,
    ]);

    $service = app(CalendarEventsService::class);

    expect($service->getPeakDatesForRange(
        now()->setDate(2026, 8, 24),
        now()->setDate(2026, 8, 29),
    )->all())->toBe(['2026-08-25', '2026-08-26']);
    expect($service->isPeakDay(now()->setDate(2026, 8, 25)))->toBeTrue();
    expect($service->isPeakDay(now()->setDate(2026, 8, 27)))->toBeFalse();
});

it('does not add a peak date when a user selects it', function () {
    $this->actingAs(dayOffTestUser());

    CalendarEvent::create([
        'type' => CalendarEvent::TYPE_PEAK,
        'title' => 'Peak day',
        'start_date' => '2026-08-25',
        'repeat_type' => CalendarEvent::REPEAT_NONE,
        'is_active' => true,
    ]);

    Livewire::test('forms.page-weekend')
        ->call('selectDate', '2026-08-25')
        ->assertSet('ranges', [])
        ->assertHasErrors('ranges');
});

it('rejects a peak date in a direct range submission', function () {
    $this->actingAs(dayOffTestUser());

    CalendarEvent::create([
        'type' => CalendarEvent::TYPE_PEAK,
        'title' => 'Peak day',
        'start_date' => '2026-08-26',
        'repeat_type' => CalendarEvent::REPEAT_NONE,
        'is_active' => true,
    ]);

    Livewire::test('forms.page-weekend')
        ->set('ranges', [[
            'start' => '2026-08-25',
            'end' => '2026-08-27',
        ]])
        ->set('comment', 'Проверка запрета пикового дня')
        ->call('submit')
        ->assertHasErrors('ranges')
        ->assertSet('successSheetOpen', false);
});

it('allows Sunday selection but asks for confirmation before submission', function () {
    $this->actingAs(dayOffTestUser());

    Livewire::test('forms.page-weekend')
        ->call('selectDate', '2026-08-23')
        ->assertSet('ranges', [[
            'start' => '2026-08-23',
            'end' => '2026-08-23',
        ]])
        ->set('comment', 'Проверка воскресной заявки')
        ->call('submit')
        ->assertSet('policyModalOpen', true)
        ->assertSet('successSheetOpen', false);
});

it('does not submit a Sunday request after returning from the warning', function () {
    $this->actingAs(dayOffTestUser());

    Livewire::test('forms.page-weekend')
        ->set('ranges', [[
            'start' => '2026-08-23',
            'end' => '2026-08-23',
        ]])
        ->set('comment', 'Проверка возврата из предупреждения')
        ->call('submit')
        ->call('closePolicyModal')
        ->assertSet('policyModalOpen', false)
        ->assertSet('successSheetOpen', false);
});

it('creates a Sunday request after explicit confirmation', function () {
    Http::fake();
    $this->actingAs(dayOffTestUser());

    Livewire::test('forms.page-weekend')
        ->set('ranges', [[
            'start' => '2026-08-23',
            'end' => '2026-08-23',
        ]])
        ->set('comment', 'Проверка подтверждения воскресной заявки')
        ->call('submit')
        ->assertSet('policyModalOpen', true)
        ->call('confirmSundaySubmission')
        ->assertSet('policyModalOpen', false)
        ->assertSet('successSheetOpen', true);

    expect(DB::table('day_off_requests')->count())->toBe(1)
        ->and(DB::table('day_off_request_days')->count())->toBe(1)
        ->and(Carbon::parse(DB::table('day_off_request_days')->value('date'))->toDateString())->toBe('2026-08-23');
});
