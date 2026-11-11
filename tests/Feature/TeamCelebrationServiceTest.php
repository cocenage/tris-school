<?php

use App\Services\Calendar\CalendarEventsService;
use App\Services\Calendar\TeamCelebrationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'services.telegram.chat_id_celebrations' => 'celebrations-test-chat',
        'services.telegram.thread_id_celebrations' => '7',
    ]);

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status');
        $table->boolean('is_active');
        $table->string('telegram_id')->nullable();
        $table->string('telegram_username')->nullable();
        $table->date('birthday')->nullable();
        $table->date('work_started_at')->nullable();
        $table->boolean('dip')->default(false);
        $table->json('weekend_days')->nullable();
        $table->timestamps();
    });

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

    Schema::create('day_off_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });
    Schema::create('day_off_request_days', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('day_off_request_id');
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status');
        $table->timestamps();
    });
    Schema::create('vacation_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status');
        $table->text('reason')->nullable();
        $table->timestamps();
    });
    Schema::create('vacation_request_days', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('vacation_request_id');
        $table->unsignedBigInteger('user_id');
        $table->date('date');
        $table->string('status');
        $table->timestamps();
    });
});

afterEach(function () {
    foreach ([
        'vacation_request_days',
        'vacation_requests',
        'day_off_request_days',
        'day_off_requests',
        'calendar_events',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

function insertCelebrationUser(array $attributes): int
{
    return DB::table('users')->insertGetId(array_merge([
        'name' => 'Командный сотрудник',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'telegram_id' => null,
        'telegram_username' => null,
        'birthday' => null,
        'work_started_at' => null,
        'dip' => false,
        'weekend_days' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

it('greets birthdays of active approved non-admin users without exposing birth date or age', function () {
    $withId = insertCelebrationUser([
        'name' => 'Анна', 'telegram_id' => '123456789', 'birthday' => '1992-10-09',
    ]);
    $withUsername = insertCelebrationUser([
        'name' => 'Мария', 'telegram_username' => 'maria_team', 'birthday' => '1990-10-09',
    ]);
    insertCelebrationUser(['name' => 'Администратор', 'role' => 'admin', 'birthday' => '1980-10-09']);
    insertCelebrationUser(['name' => 'Неодобренный', 'status' => 'pending', 'birthday' => '1985-10-09']);
    insertCelebrationUser(['name' => 'Неактивный', 'is_active' => false, 'birthday' => '1988-10-09']);

    $events = app(TeamCelebrationService::class)->eventsForDate(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $byId = $events->keyBy('source_id');

    expect($events)->toHaveCount(2)
        ->and($byId->get((string) $withId))
        ->toMatchArray(['kind' => 'birthday', 'source_type' => 'User'])
        ->and($byId->get((string) $withId)['text'])->toContain('tg://user?id=123456789')
        ->and($byId->get((string) $withUsername)['text'])->toContain('https://t.me/maria_team')
        ->and($events->pluck('text')->implode(' '))->not->toContain('1992', '1990', '34 лет', '36 лет')
        ->and($events->pluck('operation_key')->unique())->toHaveCount(2)
        ->and($events->pluck('photo_path')->filter())->toBeEmpty();
});

it('announces only full work years and maps February twenty ninth to February twenty eighth', function () {
    $leapUserId = insertCelebrationUser([
        'name' => 'Иван', 'work_started_at' => '2020-02-29',
    ]);
    insertCelebrationUser(['name' => 'Сегодня первый день', 'work_started_at' => '2026-02-28']);

    $service = app(TeamCelebrationService::class);
    $nonLeap = $service->eventsForDate(CarbonImmutable::parse('2021-02-28', 'Europe/Rome'));
    $leap = $service->eventsForDate(CarbonImmutable::parse('2024-02-29', 'Europe/Rome'));
    $tooEarly = $service->eventsForDate(CarbonImmutable::parse('2020-02-28', 'Europe/Rome'));

    expect($nonLeap)->toHaveCount(1)
        ->and($nonLeap->first())
        ->toMatchArray(['kind' => 'work_anniversary', 'source_id' => (string) $leapUserId])
        ->and($nonLeap->first()['text'])->toContain('1 год')
        ->and($leap)->toHaveCount(1)
        ->and($leap->first()['text'])->toContain('4 года')
        ->and($tooEarly)->toBeEmpty();
});

it('includes a multi-day active calendar holiday on each covered date', function () {
    DB::table('calendar_events')->insert([
        'type' => 'holiday',
        'title' => 'Праздник команды',
        'description' => null,
        'start_date' => '2026-10-09',
        'end_date' => '2026-10-11',
        'repeat_type' => 'none',
        'repeat_until' => null,
        'is_active' => true,
        'priority' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('calendar_events')->insert([
        'type' => 'holiday',
        'title' => 'Выключенный праздник',
        'description' => null,
        'start_date' => '2026-10-09',
        'end_date' => null,
        'repeat_type' => 'none',
        'repeat_until' => null,
        'is_active' => false,
        'priority' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(TeamCelebrationService::class);
    $firstDay = $service->eventsForDate(CarbonImmutable::parse('2026-10-09', 'Europe/Rome'));
    $middleDay = $service->eventsForDate(CarbonImmutable::parse('2026-10-10', 'Europe/Rome'));

    expect($firstDay)->toHaveCount(1)
        ->and($firstDay->first())
        ->toMatchArray(['kind' => 'calendar_holiday', 'source_type' => 'CalendarEvent'])
        ->and($firstDay->first()['text'])->toContain('<b>Праздник команды</b>', '09.10–11.10')
        ->and($middleDay)->toHaveCount(1);
});

it('includes the configured route in the stable event key', function () {
    insertCelebrationUser(['birthday' => '1992-10-09']);
    $date = CarbonImmutable::parse('2026-10-09', 'Europe/Rome');
    $service = app(TeamCelebrationService::class);
    $firstKey = $service->eventsForDate($date)->first()['operation_key'];

    config(['services.telegram.thread_id_celebrations' => '8']);
    $secondKey = $service->eventsForDate($date)->first()['operation_key'];

    expect($secondKey)->not->toBe($firstKey);
});

function insertDigestCalendarEvent(
    string $type,
    string $title,
    string $start,
    ?string $end = null,
    ?string $description = null,
    string $repeatType = 'none',
    ?string $repeatUntil = null,
): int {
    return DB::table('calendar_events')->insertGetId([
        'type' => $type,
        'title' => $title,
        'description' => $description,
        'start_date' => $start,
        'end_date' => $end,
        'repeat_type' => $repeatType,
        'repeat_until' => $repeatUntil,
        'is_active' => true,
        'priority' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('includes recurring calendar events on their occurrence date', function () {
    insertDigestCalendarEvent(
        'holiday',
        'Ежегодный день Академии',
        '2020-11-02',
        repeatType: 'yearly',
        repeatUntil: '2030-11-02',
    );

    $date = CarbonImmutable::parse('2026-11-02', 'Europe/Rome');
    $events = app(TeamCelebrationService::class)->eventsForDate($date);

    expect($events)->toHaveCount(1)
        ->and($events->first()['kind'])->toBe('calendar_holiday')
        ->and($events->first()['text'])->toContain('<b>Ежегодный день Академии</b>');
});

it('formats a grouped digest with multiple birthday mentions and no empty sections', function () {
    insertCelebrationUser(['name' => 'Анна & друзья', 'telegram_id' => '123456789', 'birthday' => '1992-11-02']);
    insertCelebrationUser(['name' => 'Мария', 'telegram_username' => 'maria_team', 'birthday' => '1990-11-02']);

    $date = CarbonImmutable::parse('2026-11-02', 'Europe/Rome');
    $service = app(TeamCelebrationService::class);
    $events = $service->eventsForDate($date);
    $digest = $service->formatDigest($date, $events);

    expect($events)->toHaveCount(2)
        ->and($digest['text'])->toContain('🎂 <b>Дни рождения</b>', 'Анна &amp; друзья', 'tg://user?id=123456789', 'https://t.me/maria_team')
        ->not->toContain('🏆 <b>Годовщины работы</b>', '🎉 <b>Праздники</b>')
        ->and($digest['text'])->toContain("\n\n")
        ->and(substr_count($digest['text'], '<a '))->toBe(substr_count($digest['text'], '</a>'))
        ->and(substr_count($digest['text'], '<b>'))->toBe(substr_count($digest['text'], '</b>'));
});

it('groups birthdays, work anniversaries, and a holiday that share a date', function () {
    insertCelebrationUser(['name' => 'Анна', 'birthday' => '1992-11-02']);
    insertCelebrationUser(['name' => 'Иван', 'work_started_at' => '2021-11-02']);
    insertDigestCalendarEvent('holiday', 'День Академии', '2026-11-02');

    $date = CarbonImmutable::parse('2026-11-02', 'Europe/Rome');
    $service = app(TeamCelebrationService::class);
    $digest = $service->formatDigest($date, $service->eventsForDate($date));

    expect($digest['text'])->toContain('🎂 <b>Дни рождения</b>', '🏆 <b>Годовщины работы</b>', '🎉 <b>Праздники</b>', 'День Академии', '5 лет')
        ->and(strpos($digest['text'], '🎂 <b>Дни рождения</b>'))->toBeLessThan(strpos($digest['text'], '🏆 <b>Годовщины работы</b>'))
        ->and(strpos($digest['text'], '🏆 <b>Годовщины работы</b>'))->toBeLessThan(strpos($digest['text'], '🎉 <b>Праздники</b>'));
});

it('includes all active calendar types except vacations and excludes day-off records', function () {
    foreach (['workflow', 'finance', 'holiday', 'peak', 'strike', 'vacation'] as $type) {
        insertDigestCalendarEvent($type, 'Событие '.$type, '2026-11-02');
    }

    $dayOffRequestId = DB::table('day_off_requests')->insertGetId([
        'user_id' => 999,
        'status' => 'approved',
        'reason' => 'Не для сводки',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('day_off_request_days')->insert([
        'day_off_request_id' => $dayOffRequestId,
        'user_id' => 999,
        'date' => '2026-11-02',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $date = CarbonImmutable::parse('2026-11-02', 'Europe/Rome');
    $service = app(TeamCelebrationService::class);
    $events = $service->eventsForDate($date);

    expect($events->pluck('kind'))->toContain('workflow', 'finance', 'calendar_holiday', 'peak', 'strike')
        ->and($events->pluck('kind'))->not->toContain('vacation')
        ->and($events->pluck('text')->implode(' '))->not->toContain('Событие vacation', 'Не для сводки');

    $digest = $service->formatDigest($date, $events);
    expect($digest['text'])->toContain(
        '🧭 <b>Рабочие процессы</b>',
        '💶 <b>Финансы</b>',
        '📈 <b>Пиковая загрузка</b>',
        '🚧 <b>Забастовки</b>',
    )->not->toContain('Событие vacation');
});

it('shows a multi-day calendar event on every date it covers', function () {
    insertDigestCalendarEvent('workflow', 'Инвентаризация', '2026-11-02', '2026-11-04', 'Проверить склад');

    $service = app(TeamCelebrationService::class);
    $date = CarbonImmutable::parse('2026-11-03', 'Europe/Rome');
    $events = $service->eventsForDate($date);
    $digest = $service->formatDigest($date, $events);

    expect($events)->toHaveCount(1)
        ->and($digest['text'])->toContain('Инвентаризация', 'Проверить склад', '02.11–04.11');
});

it('keeps the year visible for a calendar event that crosses New Year', function () {
    insertDigestCalendarEvent('workflow', 'Новогодний переход', '2026-12-31', '2027-01-02');

    $service = app(TeamCelebrationService::class);
    $date = CarbonImmutable::parse('2027-01-01', 'Europe/Rome');
    $digest = $service->formatDigest($date, $service->eventsForDate($date));

    expect($digest['text'])->toContain('31.12.2026–02.01.2027');
});

it('returns no digest for an empty date and escapes calendar HTML', function () {
    $date = CarbonImmutable::parse('2026-11-02', 'Europe/Rome');
    $service = app(TeamCelebrationService::class);

    expect($service->formatDigest($date, collect()))->toBeNull();

    insertCelebrationUser(['name' => 'Анна <Академия>', 'telegram_id' => '123456789', 'birthday' => '1992-11-02']);
    insertDigestCalendarEvent('workflow', '<Встреча & план>', '2026-11-02', description: '<script>опасно</script>');
    $digest = $service->formatDigest($date, $service->eventsForDate($date));
    preg_match_all('~</?([a-z]+)(?:\s[^>]*)?>~i', $digest['text'], $tags);
    $tagNames = array_unique(array_map('strtolower', $tags[1]));

    expect($digest['text'])->toContain('&lt;Встреча &amp; план&gt;', '&lt;script&gt;опасно&lt;/script&gt;')
        ->not->toContain('<script>', '<Встреча', '<Академия>')
        ->and($tagNames)->toEqualCanonicalizing(['a', 'b'])
        ->and(substr_count($digest['text'], '<a '))->toBe(substr_count($digest['text'], '</a>'))
        ->and(substr_count($digest['text'], '<b>'))->toBe(substr_count($digest['text'], '</b>'));
});

it('selects the optional birthday card by the two-digit month', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tris-month-cards-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'10.jpg', 'october-card');
    insertCelebrationUser(['birthday' => '1992-11-02']);

    $service = new class(app(CalendarEventsService::class), $directory) extends TeamCelebrationService
    {
        public function __construct(CalendarEventsService $calendarEventsService, private string $directory)
        {
            parent::__construct($calendarEventsService);
        }

        protected function cardDirectory(): string
        {
            return $this->directory;
        }
    };

    try {
        $event = $service->eventsForDate(CarbonImmutable::parse('2026-11-02', 'Europe/Rome'))->first();
        expect($event['photo_path'])->toBeNull();

        file_put_contents($directory.DIRECTORY_SEPARATOR.'11.jpg', 'november-card');
        $eventWithCard = $service->eventsForDate(CarbonImmutable::parse('2026-11-02', 'Europe/Rome'))->first();
        expect(str_replace('\\', '/', $eventWithCard['photo_path']))->toBe(str_replace('\\', '/', $directory.'/11.jpg'));
    } finally {
        @unlink($directory.DIRECTORY_SEPARATOR.'11.jpg');
        @unlink($directory.DIRECTORY_SEPARATOR.'10.jpg');
        @rmdir($directory);
    }
});

it('respects Telegram caption and message limits without truncating visible text', function () {
    $service = app(TeamCelebrationService::class);
    $date = CarbonImmutable::parse('2026-11-02', 'Europe/Rome');
    $photoPath = tempnam(sys_get_temp_dir(), 'tris-digest-card-');
    file_put_contents($photoPath, 'fixture-image');

    try {
        $birthday = ['kind' => 'birthday', 'text' => '', 'photo_path' => $photoPath];
        $captionBase = $service->formatDigest($date, collect([$birthday]))['character_count'];
        $birthday['text'] = str_repeat('x', 1024 - $captionBase);
        $captionLimit = $service->formatDigest($date, collect([$birthday]));
        expect($captionLimit['character_count'])->toBe(1024)
            ->and($captionLimit['photo_path'])->toBe($photoPath)
            ->and($captionLimit['over_limit'])->toBeFalse();

        $birthday['text'] .= 'x';
        expect($service->formatDigest($date, collect([$birthday]))['photo_path'])->toBeNull();

        $calendarEvent = ['kind' => 'workflow', 'text' => '', 'photo_path' => null];
        $textBase = $service->formatDigest($date, collect([$calendarEvent]))['character_count'];
        $calendarEvent['text'] = str_repeat('y', 4096 - $textBase);
        $textLimit = $service->formatDigest($date, collect([$calendarEvent]));
        expect($textLimit['character_count'])->toBe(4096)
            ->and($textLimit['over_limit'])->toBeFalse()
            ->and($textLimit['text'])->toEndWith(str_repeat('y', 4096 - $textBase));

        $calendarEvent['text'] .= 'z';
        $overLimit = $service->formatDigest($date, collect([$calendarEvent]));
        expect($overLimit['over_limit'])->toBeTrue()
            ->and($overLimit['text'])->toEndWith('z');
    } finally {
        @unlink($photoPath);
    }
});
