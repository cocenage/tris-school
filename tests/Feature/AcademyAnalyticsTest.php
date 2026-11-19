<?php

use App\Models\User;
use App\Services\Analytics\AcademyAnalytics;
use App\Services\RolePreviewContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    Date::setTestNow(Date::parse('2026-11-16 15:00:00', 'Europe/Rome'));
    $telegramMigrations = collect(glob(database_path('migrations/2026_05_21_*.php')))
        ->reject(fn (string $path): bool => str_contains($path, 'instructions_table'))
        ->map(fn (string $path): string => 'database/migrations/'.basename($path))->all();
    expect(Artisan::call('migrate', ['--database' => 'analytics', '--path' => $telegramMigrations]))->toBe(0);
    expect(Artisan::call('migrate', ['--database' => 'sqlite']))->toBe(0);
    foreach (['admin', 'supervisor', 'cleaner'] as $role) {
        $id = DB::table('users')->insertGetId([
            'name' => ucfirst($role),
            'role' => $role,
            'status' => 'approved',
            'is_active' => true,
            'birthday' => '1990-01-01',
            'work_started_at' => '2020-01-01',
        ]);
        $this->{$role} = User::findOrFail($id);
    }
});

afterEach(function () {
    Date::setTestNow();
    DB::purge('analytics');
    DB::purge('sqlite');
});

function addAnalyticsControl(User $cleaner, ?User $supervisor, string $day, int $score, string $status = 'sent'): int
{
    $control = DB::table('controls')->insertGetId(['name' => 'Test control', 'slug' => (string) str()->uuid()]);

    return DB::table('control_responses')->insertGetId([
        'control_id' => $control,
        'cleaner_id' => $cleaner->id,
        'supervisor_id' => $supervisor?->id,
        'inspection_date' => $day,
        'max_points' => 100,
        'total_points' => $score,
        'score_percent' => $score,
        'result_zone' => $score === 100 ? 'green' : 'red',
        'status' => $status,
    ]);
}

it('charts completed control results for the selected period with a drilldown to the source', function () {
    $current = addAnalyticsControl($this->cleaner, $this->supervisor, '2026-11-15', 70);
    $past = addAnalyticsControl($this->cleaner, $this->supervisor, '2026-11-07', 100);
    $draft = addAnalyticsControl($this->cleaner, $this->supervisor, '2026-11-15', 50, 'draft');

    $this->actingAs($this->admin)->get('/analytics?tab=quality&day=2026-11-15')
        ->assertOk()->assertSee('Средняя оценка')->assertSee('70%')
        ->assertSee('−30 п. п.')->assertSee('15.11')
        ->assertSee(route('page-profile.checks.result', $current))
        ->assertDontSee(route('page-profile.checks.result', $past))
        ->assertDontSee(route('page-profile.checks.result', $draft));

    $this->get('/analytics?tab=quality&period=30&day=2026-11-07')
        ->assertOk()->assertSee('85%')
        ->assertSee(route('page-profile.checks.result', $past));
});

it('limits a supervisor to their own controls and denies team data to other roles and role preview', function () {
    $own = addAnalyticsControl($this->cleaner, $this->supervisor, '2026-11-15', 70);
    $other = addAnalyticsControl($this->cleaner, $this->admin, '2026-11-15', 100);

    $this->actingAs($this->supervisor)->get('/analytics?tab=districts&day=2026-11-15')
        ->assertOk()->assertSee('Мои проведённые контроли')->assertSee('Рабочие дни по графику')->assertDontSee('Районы');
    $this->get('/analytics?tab=quality&day=2026-11-15')
        ->assertOk()->assertSee(route('page-profile.checks.result', $own))
        ->assertDontSee(route('page-profile.checks.result', $other))
        ->assertDontSee('Рабочие дни по графику');
    $this->actingAs($this->cleaner)->get('/analytics')->assertForbidden();
    $this->actingAs($this->admin)->withSession([RolePreviewContext::SESSION_KEY => 'supervisor'])
        ->get('/analytics')->assertForbidden();
});

it('labels a missing source separately from zero recorded events', function () {
    $this->actingAs($this->admin)->get('/analytics?tab=districts')
        ->assertOk()->assertSee('Данные журнала ОКС недоступны');
    $this->get('/analytics')->assertOk()->assertSee('Рабочие дни по графику')
        ->assertSee('Контролей за этот период нет')->assertDontSee('Средняя оценка');
});

it('counts a repeated opening and a closure from the same district history', function () {
    config()->set('services.telegram.digest_districts', [
        'test' => ['label' => 'Test district', 'chat_id' => '-10000123', 'latitude' => 45, 'longitude' => 9],
        'missing' => ['label' => 'Missing district', 'chat_id' => '-10000789', 'latitude' => 45, 'longitude' => 9],
        'empty' => ['label' => 'Empty district', 'chat_id' => '-10000456', 'latitude' => 45, 'longitude' => 9],
    ]);
    $analytics = DB::connection('analytics');
    $chat = $analytics->table('telegram_chats')->insertGetId(['telegram_chat_id' => '-10000123']);
    $analytics->table('telegram_chats')->insert(['telegram_chat_id' => '-10000456']);
    $message = $analytics->table('telegram_messages')->insertGetId(['telegram_chat_id' => $chat, 'message_id' => '1']);
    $event = $analytics->table('telegram_operational_events')->insertGetId([
        'event_key' => 'test-problem',
        'root_message_id' => $message,
        'telegram_chat_id' => $chat,
        'primary_type' => 'problem',
        'types' => '["problem"]',
        'summary' => 'Test problem',
        'confidence' => 'high',
        'first_observed_at' => '2026-11-10 09:00:00',
        'last_observed_at' => '2026-11-14 09:00:00',
    ]);
    $observation = $analytics->table('telegram_operational_observations')->insertGetId([
        'telegram_message_id' => $message,
        'source_revision_hash' => str_repeat('a', 64),
    ]);
    foreach ([
        ['created', null, 'open', '2026-11-10 09:00:00', true],
        ['resolved', 'open', 'resolved', '2026-11-11 09:00:00', true],
        ['resolved', 'resolved', 'resolved', '2026-11-11 10:00:00', true],
        ['reopened', 'resolved', 'reopened', '2026-11-11 23:30:00', true],
        ['resolved', 'reopened', 'resolved', '2026-11-13 09:00:00', false],
    ] as $index => [$transition, $before, $after, $date, $current]) {
        $analytics->table('telegram_operational_event_evidence')->insert([
            'operational_event_id' => $event,
            'observation_id' => $observation,
            'role' => 'test-'.$index,
            'transition' => $transition,
            'status_before' => $before,
            'status_after' => $after,
            'occurred_at' => $date,
            'confidence' => 'high',
            'is_current_revision' => $current,
        ]);
    }

    $this->actingAs($this->admin)->get('/analytics?tab=districts')
        ->assertOk()->assertSee('Test district')->assertSee('Empty district')
        ->assertSee('За период нет записей об открытиях или закрытиях')
        ->assertSee('Источник района недоступен')->assertSee('Итоги по доступным районам')
        ->assertSeeInOrder(['Открытия, включая повторные', '>2</dd>', 'Закрытия', '>1</dd>'], false);
});

it('checks analytics authorization again when the component is hydrated', function () {
    $component = Livewire::actingAs($this->admin)->test('page-analytics')->assertOk();
    session()->put(RolePreviewContext::SESSION_KEY, 'cleaner');

    $component->call('$refresh')->assertForbidden();
});

it('shows planned days and regular days off separately for the calendar date selected on the chart', function () {
    DB::table('employee_work_schedule_versions')->insert([
        'user_id' => $this->cleaner->id,
        'effective_from' => '2026-11-10',
        'employment_start_date' => '2020-01-01',
        'weekend_days' => '[7]',
        'new_values' => '{}',
        'reason' => 'Test schedule',
    ]);

    $this->actingAs($this->admin)->get('/analytics?tab=staff&day=2026-11-15')
        ->assertOk()->assertSeeInOrder(['Рабочие дни по графику', '>6</dd>', 'Выходные по графику', '>1</dd>'], false)
        ->assertSee('По графику 0 · Выходные 1 · Отсутствия 0 · Без назначенной работы 0 · График неизвестен 1')
        ->assertSee(route('employees.calendar', ['employee' => $this->cleaner, 'month' => '2026-11', 'day' => '2026-11-15']));
});

it('counts only explicit cleaner no-work marks in schedule analytics', function () {
    $this->cleaner->forceFill(['weekend_days' => [7]])->save();

    $vacationId = DB::table('vacation_requests')->insertGetId([
        'user_id' => $this->cleaner->id,
        'start_date' => '2026-11-14',
        'end_date' => '2026-11-14',
        'days_count' => 1,
        'reason' => 'fixture',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vacation_request_days')->insert([
        'vacation_request_id' => $vacationId,
        'user_id' => $this->cleaner->id,
        'date' => '2026-11-14',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([[$this->cleaner, '2026-11-16'], [$this->supervisor, '2026-11-15']] as [$employee, $date]) {
        DB::table('employee_calendar_adjustments')->insert([
            'user_id' => $employee->id,
            'starts_on' => $date,
            'operation' => 'set_state',
            'category' => 'other',
            'previous_state' => json_encode(['state' => 'planned_work']),
            'new_state' => json_encode(['state' => 'no_work']),
            'actor_id' => $this->admin->id,
            'actor_name_snapshot' => $this->admin->name,
            'reason' => 'fixture no-work mark',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $schedule = app(AcademyAnalytics::class)->schedule(
        CarbonImmutable::parse('2026-11-10', 'Europe/Rome'),
        CarbonImmutable::parse('2026-11-16', 'Europe/Rome'),
    );

    expect($schedule['daily']->get('2026-11-16')['unassigned_work_days'])->toBe(1)
        ->and($schedule['daily']->get('2026-11-15')['unassigned_work_days'])->toBe(0)
        ->and($schedule['daily']->get('2026-11-14')['unassigned_work_days'])->toBe(0)
        ->and($schedule['daily']->sum('unassigned_work_days'))->toBe(1);

    $this->actingAs($this->admin)->get('/analytics?tab=staff&day=2026-11-16')
        ->assertOk()
        ->assertSee('Без назначенной работы')
        ->assertSee('Без назначенной работы 1');
});
