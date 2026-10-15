<?php

use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramLinenDefectReportBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    TelegramOperationalTestDatabase::refresh();
    Carbon::setTestNow(Carbon::parse('2026-11-01 22:00:00', 'Europe/Rome'));
    config(['services.telegram.digest_districts' => [
        'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
    ]]);
});

afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
});

it('uses the same half-open Rome window for problems textile and input completeness', function (string $date, string $start, string $end) {
    $day = Carbon::parse($date, 'Europe/Rome');
    $times = [
        $day->copy()->subDay()->format('Y-m-d').' 23:59:59',
        $date.' 00:00:00',
        $date.' 20:29:59',
        $date.' 20:30:00',
        $date.' 23:59:59',
    ];
    foreach ($times as $index => $time) {
        $message = TelegramOperationalTestDatabase::message(
            'Брак 1 полотенце с дыркой.', $time, (string) (100 + $index), threadId: (string) (100 + $index),
        );
        $message->topic->update(['title' => 'Via Test '.(100 + $index)]);
        app(TelegramOperationalEventObserver::class)->observe($message);
    }

    $evening = app(TelegramEveningIntelligenceBuilder::class)->build($date);
    $linen = app(TelegramLinenDefectReportBuilder::class)->build($date);

    expect($evening['daily_problems'])->toHaveCount(2)
        ->and($linen['known_units'])->toBe(2)
        ->and($linen['cases'])->toHaveCount(2)
        ->and($evening['data_quality'])->toMatchArray(['source_messages' => 2, 'observed_messages' => 2, 'source_status' => 'available'])
        ->and($linen['districts']['navigli'])->toMatchArray(['source_messages' => 2, 'observed_messages' => 2, 'source_status' => 'available'])
        ->and($evening['reporting_window'])->toMatchArray(['start' => $start, 'end' => $end, 'preliminary' => false])
        ->and($linen['reporting_window'])->toBe($evening['reporting_window']);
})->with([
    'ordinary day' => ['2026-10-15', '2026-10-15T00:00:00+02:00', '2026-10-15T20:30:00+02:00'],
    'spring DST' => ['2026-03-29', '2026-03-29T00:00:00+01:00', '2026-03-29T20:30:00+02:00'],
    'autumn DST' => ['2026-10-25', '2026-10-25T00:00:00+02:00', '2026-10-25T20:30:00+01:00'],
]);

it('does not use a resolution at or after the reporting cutoff', function (string $resolutionTime, string $status) {
    $source = TelegramOperationalTestDatabase::message('Не работает замок в квартире.', '2026-10-15 10:00:00', '200');
    $source->topic->update(['title' => 'Via Test 20']);
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe($source);
    $answer = TelegramOperationalTestDatabase::message('Починили замок.', '2026-10-15 '.$resolutionTime, '201', [
        'message' => ['reply_to_message' => ['message_id' => 200]],
    ]);
    $observer->observe($answer);

    foreach (['2026-10-15 20:30:00', '2026-10-15 23:30:00', '2026-10-16 12:00:00'] as $clock) {
        Carbon::setTestNow(Carbon::parse($clock, 'Europe/Rome'));
        $report = app(TelegramEveningIntelligenceBuilder::class)->build('2026-10-15');
        expect($report['events'][0]['status'])->toBe($status)
            ->and($report['daily_problems'])->toHaveCount($status === 'open' ? 1 : 0);
    }
})->with([
    ['20:29:59', 'resolved'],
    ['20:30:00', 'open'],
    ['20:30:01', 'open'],
]);

it('counts pending pre-cutoff messages after catch-up without counting later messages', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 20:25:00', 'Europe/Rome'));
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message('Брак 2 полотенца с дырками.', '2026-10-15 20:24:00', '301'));
    $pending = TelegramOperationalTestDatabase::message('Брак 1 простыня с дыркой.', '2026-10-15 20:29:59', '302', threadId: '12');
    TelegramOperationalTestDatabase::message('Брак 8 простыней с дырками.', '2026-10-15 20:30:00', '303', threadId: '13');
    Carbon::setTestNow(Carbon::parse('2026-10-15 20:30:05', 'Europe/Rome'));

    $builder = app(TelegramEveningIntelligenceBuilder::class);
    expect($builder->build('2026-10-15')['data_quality'])->toMatchArray([
        'source_messages' => 2, 'observed_messages' => 1, 'source_status' => 'incomplete',
    ])->and(app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15')['districts']['navigli'])
        ->toMatchArray(['source_messages' => 2, 'observed_messages' => 1, 'source_status' => 'incomplete']);

    $observer->observe($pending);
    expect($builder->sourceDataQuality('2026-10-15'))->toMatchArray([
        'source_messages' => 2, 'observed_messages' => 2, 'source_status' => 'available',
    ])->and(app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15')['known_units'])->toBe(3);
});

it('marks an early preview preliminary and converts an instant to the Rome target date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Europe/Rome'));
    foreach (['11:00:00', '14:00:00'] as $index => $time) {
        $message = TelegramOperationalTestDatabase::message('Брак 1 полотенце с дыркой.', '2026-10-15 '.$time, (string) (400 + $index), threadId: (string) (400 + $index));
        app(TelegramOperationalEventObserver::class)->observe($message);
    }
    $date = Carbon::parse('2026-10-14 22:00:00', 'UTC');
    $evening = app(TelegramEveningIntelligenceBuilder::class)->build($date);
    $linen = app(TelegramLinenDefectReportBuilder::class)->build($date);
    expect($evening['date'])->toBe('2026-10-15')
        ->and($evening['data_quality']['source_messages'])->toBe(1)
        ->and($linen['known_units'])->toBe(1)
        ->and($evening['reporting_window'])->toMatchArray([
            'end' => '2026-10-15T20:30:00+02:00', 'evaluated_until' => '2026-10-15T12:00:00+02:00', 'preliminary' => true,
        ])
        ->and($linen['reporting_window'])->toBe($evening['reporting_window']);
});

it('labels human previews before the end of the reporting window', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'Europe/Rome'));
    expect(Artisan::call('telegram:evening-intelligence-preview', ['--date' => '2026-10-15']))->toBe(0)
        ->and(Artisan::output())->toContain('Предварительный просмотр: окно дня ещё не завершено');

    Carbon::setTestNow(Carbon::parse('2026-10-15 20:30:00', 'Europe/Rome'));
    expect(Artisan::call('telegram:evening-intelligence-preview', ['--date' => '2026-10-15']))->toBe(0)
        ->and(Artisan::output())->not->toContain('Предварительный просмотр: окно дня ещё не завершено');
});
