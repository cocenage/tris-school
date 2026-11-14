<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    $baseMigrations = ['082853_create_telegram_chats', '082854_create_telegram_topics', '082855_create_telegram_users', '082857_create_telegram_messages', '082906_create_telegram_attachments', '101806_fix_telegram_chats'];
    Artisan::call('migrate', ['--database' => 'analytics', '--path' => array_map(fn ($name) => 'database/migrations/2026_05_21_'.$name.'_table.php', $baseMigrations)]);
    Artisan::call('migrate', ['--database' => 'sqlite']);
});

afterEach(function () {
    DB::purge('analytics');
});

it('shows the current role action and opens unread application answers from home', function () {
    $cleaner = User::factory()->create(['role' => 'cleaner', 'status' => 'approved', 'birthday' => '1990-02-03', 'work_started_at' => '2020-01-01']);
    DB::table('feedback_suggestions')->insert(['user_id' => $cleaner->id, 'comment' => 'Example', 'type' => 'suggestion', 'status' => 'reviewed']);

    $this->actingAs($cleaner)->get(route('page-home'))
        ->assertOk()
        ->assertSee('Создать заявку')
        ->assertSee('Ответы на заявки')
        ->assertSee(route('page-profile.applications'), false)
        ->assertSee(route('page-apartments'), false)
        ->assertDontSee('Начать контроль');

    $supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'approved', 'birthday' => '1990-02-03', 'work_started_at' => '2020-01-01']);
    $this->actingAs($supervisor)->get(route('page-home'))
        ->assertOk()
        ->assertSee('Начать контроль')
        ->assertSee(route('page-checks.control'), false)
        ->assertDontSee('Ответы на заявки');
});

it('distinguishes a day with no cleanings from an unavailable schedule', function () {
    $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 15)->setTime(9, 0));
    $employee = User::factory()->create(['role' => 'cleaner', 'status' => 'approved'])->refresh();
    $this->actingAs($employee);
    config(['services.cleaning_schedule.url' => null]);
    $this->get(route('page-home'))->assertSee('Расписание сейчас недоступно.')->assertDontSee('На сегодня уборок нет.');

    config(['services.cleaning_schedule.url' => 'https://schedule.example.test/assignments', 'services.cleaning_schedule.token' => 'fixture']);
    Http::preventStrayRequests();
    Http::fake(['https://schedule.example.test/*' => Http::sequence()
        ->push(['date' => '2026-11-15', 'timezone' => 'Europe/Rome', 'assignments' => []])
        ->pushStatus(503)]);
    $this->get(route('page-home'))->assertSee('На сегодня уборок нет.')->assertDontSee('Расписание сейчас недоступно.');
    $this->get(route('page-home'))->assertSee('Расписание сейчас недоступно.')->assertDontSee('На сегодня уборок нет.');
});

it('shows only today assignments for the current employee and links only visible apartments', function () {
    $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 15)->setTime(9, 0));
    $employee = User::factory()->create(['role' => 'cleaner', 'status' => 'approved', 'birthday' => '1990-02-03', 'work_started_at' => '2020-01-01']);
    $apartment = DB::table('apartments')->insertGetId(['name' => 'Объект с доступом', 'code' => 'A-01', 'information_status' => 'published']);
    DB::table('apartments')->insert(['name' => 'Закрытый объект', 'code' => 'A-02', 'information_status' => 'published']);
    DB::table('apartment_user_access')->insert(['apartment_id' => $apartment, 'user_id' => $employee->id]);
    config(['services.cleaning_schedule.url' => 'https://schedule.example.test/assignments', 'services.cleaning_schedule.token' => 'fixture']);
    Http::preventStrayRequests();
    Http::fake(['https://schedule.example.test/*' => Http::response([
        'date' => '2026-11-15',
        'timezone' => 'Europe/Rome',
        'assignments' => [
            ['id' => 'a1', 'employee_id' => $employee->id, 'apartment_code' => 'A-01', 'starts_at' => '2026-11-15T10:00:00+01:00', 'status' => 'scheduled'],
            ['id' => 'a2', 'employee_id' => $employee->id, 'apartment_code' => 'A-02', 'starts_at' => '2026-11-15T12:00:00+01:00', 'status' => 'scheduled'],
            ['id' => 'a3', 'employee_id' => $employee->id + 1, 'apartment_code' => 'A-02', 'starts_at' => '2026-11-15T13:00:00+01:00', 'status' => 'scheduled'],
            ['id' => 'a4', 'employee_id' => $employee->id, 'apartment_code' => 'A-02', 'starts_at' => '2026-11-16T14:00:00+01:00', 'status' => 'scheduled'],
        ],
    ])]);

    $this->actingAs($employee->refresh())->get(route('page-home'))
        ->assertOk()->assertSee('Объект с доступом')->assertSee(route('page-apartments.show', $apartment), false)
        ->assertSee('12:00')->assertDontSee('Закрытый объект')->assertDontSee('13:00')->assertDontSee('14:00');
});
