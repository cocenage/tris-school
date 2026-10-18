<?php

use App\Models\TelegramOperationalEvent;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramLinenDefectReportBuilder;
use App\Services\Telegram\TelegramLinenDefectReportFormatter;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Europe/Rome'));
    TelegramOperationalTestDatabase::refresh();
    Cache::flush();
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => true,
        'services.telegram.evening_intelligence_delivery_mode' => 'centralized',
        'services.telegram.evening_intelligence_central_chat_id' => '-9000',
        'services.telegram.evening_intelligence_central_thread_id' => '99',
        'services.telegram.digest_districts' => [
            'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
            'certosa' => ['label' => 'Certosa', 'chat_id' => '-1002', 'duty_thread_id' => '22', 'latitude' => 45.48, 'longitude' => 9.14],
        ],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
});

function linenMessage(string $text, string $id, string $chat = '-1001', string $topic = '12'): void
{
    $message = TelegramOperationalTestDatabase::message($text, '2026-10-15 10:00:00', $id, chatId: $chat, threadId: $topic);
    $message->topic->update(['title' => 'Via Test 8']);
    app(TelegramOperationalEventObserver::class)->observe($message);
}

it('counts only explicit quantities in OI linen-defect events and keeps unknown quantities separate', function () {
    linenMessage('Нашла 3 простыни с пятнами, брак.', '101');
    linenMessage('Наволочка порвана.', '102', '-1002');
    linenMessage('Не хватает чистого белья.', '103');
    linenMessage('Курьер не забрал грязное бельё.', '104');
    linenMessage('Грязное бельё нужно постирать.', '105');
    linenMessage('На полотенце пятно, постираю его.', '106');

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');
    $text = app(TelegramLinenDefectReportFormatter::class)->format($report);

    expect($report['known_units'])->toBe(3)
        ->and($report['unknown_cases'])->toBe(1)
        ->and($report['cases'])->toHaveCount(2)
        ->and($report['districts']['navigli']['known_units'])->toBe(3)
        ->and($report['districts']['certosa']['known_units'])->toBe(0)
        ->and($text)->toContain('3 × простыня', 'наволочка — количество не указано', 'По районам:', 'https://t.me/c/');
});

it('counts one OI event only once after a correlated follow-up', function () {
    linenMessage('Брак 2 полотенца с пятнами.', '201');
    $event = TelegramOperationalEvent::query()->sole();
    $followUp = TelegramOperationalTestDatabase::message('Да, это те же 2 полотенца, заменили.', '2026-10-15 11:00:00', '202');
    $followUp->update(['telegram_topic_id' => $event->telegram_topic_id]);
    app(TelegramOperationalEventObserver::class)->observe($followUp->fresh(['chat', 'topic', 'telegramUser', 'attachments']));

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');

    expect($report['known_units'])->toBe(2)
        ->and($report['cases'])->toHaveCount(1);
});

it('does not use the delivered quantity as the number of defective sheets', function () {
    linenMessage('Курьер привёз 5 простыней, но две простыни с пятнами — брак.', '221');

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');

    expect($report['known_units'])->toBe(2)
        ->and($report['cases'])->toHaveCount(1)
        ->and($report['cases'][0]['details'][0]['quantity'])->toBe(2);
});

it('counts one source message once even if OI created two event rows', function () {
    linenMessage('Брак 2 полотенца с пятнами.', '231');
    $original = TelegramOperationalEvent::query()->sole();
    $duplicate = $original->replicate();
    $duplicate->event_key = 'duplicate-of-'.$original->event_key;
    $duplicate->save();
    $evidence = $original->evidence()->sole()->replicate();
    $evidence->operational_event_id = $duplicate->id;
    $evidence->save();

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');

    expect($report['known_units'])->toBe(2)
        ->and($report['cases'])->toHaveCount(1);
});

it('keeps matching cross-message defect reports out of the confirmed quantity', function () {
    linenMessage('Брак 2 полотенца с пятнами.', '241', topic: '12');
    linenMessage('Брак 2 полотенца с пятнами.', '242', topic: '13');

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');
    $text = app(TelegramLinenDefectReportFormatter::class)->format($report);

    expect($report['cases'])->toHaveCount(2)
        ->and($report['known_units'])->toBe(0)
        ->and($text)->toContain('возможный повтор')
        ->toContain('/241', '/242');
});

it('counts a defect reported as an early positive contribution', function () {
    linenMessage('Я заранее обнаружила брак 2 полотенец до заезда и сразу сообщила.', '251');

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');

    expect($report['known_units'])->toBe(2)
        ->and($report['cases'])->toHaveCount(1);
});

it('warns about missing or unfinished OI input instead of claiming a confirmed zero', function () {
    $empty = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');
    $emptyText = app(TelegramLinenDefectReportFormatter::class)->format($empty);
    $unobserved = TelegramOperationalTestDatabase::message('Брак простыни.', '2026-10-15 10:00:00', '301');
    $unobserved->topic->update(['title' => 'Via Test 8']);
    $pending = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');

    expect($empty['incomplete'])->toBeTrue()
        ->and($pending['incomplete'])->toBeTrue()
        ->and($emptyText)->toContain('Данные неполные')
        ->not->toContain('Подтверждённых сообщений о браке белья не найдено.');
});

it('shows a confirmed zero only when both district feeds have completed OI evaluation', function () {
    linenMessage('Всё готово, проблем нет.', '351');
    linenMessage('Уборка закончена.', '352', '-1002');

    $report = app(TelegramLinenDefectReportBuilder::class)->build('2026-10-15');
    $text = app(TelegramLinenDefectReportFormatter::class)->format($report);

    expect($report['incomplete'])->toBeFalse()
        ->and($report['known_units'])->toBe(0)
        ->and($text)->toContain('Подтверждённых сообщений о браке белья не найдено.')
        ->not->toContain('Данные неполные');
});

it('dry-runs without Telegram actions and sends only once through the evening target', function () {
    linenMessage('Брак 2 полотенца с пятнами.', '401');
    $sent = [];
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$sent): void {
        $mock->shouldReceive('sendMessage')->once()->andReturnUsing(function (string $chat, string $text, string $thread) use (&$sent): int {
            $sent = compact('chat', 'text', 'thread');

            return 123;
        });
    });

    expect(Artisan::call('telegram:linen-defects-report', ['--date' => '2026-10-15', '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Предпросмотр: отправка в Telegram отключена.')
        ->and($sent)->toBe([]);
    expect(Artisan::call('telegram:linen-defects-report', ['--date' => '2026-10-15', '--json' => true]))->toBe(0);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($first['results'][0]['status'])->toBe('sent')
        ->and($sent['chat'])->toBe('-9000')
        ->and($sent['thread'])->toBe('99');
    expect(Artisan::call('telegram:linen-defects-report', ['--date' => '2026-10-15', '--json' => true]))->toBe(0);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($second['results'][0]['status'])->toBe('skipped_duplicate');
});

it('keeps district reports on their configured routes in per-district mode', function () {
    config(['services.telegram.evening_intelligence_delivery_mode' => 'per-district']);
    linenMessage('Брак 2 полотенца с пятнами.', '451');
    linenMessage('Брак 3 простыни с пятнами.', '452', '-1002');
    $sent = [];
    $this->mock(TelegramBotService::class, function (MockInterface $mock) use (&$sent): void {
        $mock->shouldReceive('sendAnalyticsMessage')->twice()->andReturnUsing(function (string $chat, string $text, string $thread) use (&$sent): int {
            $sent[] = compact('chat', 'text', 'thread');

            return 123;
        });
    });

    expect(Artisan::call('telegram:linen-defects-report', ['--date' => '2026-10-15']))->toBe(0)
        ->and($sent)->toHaveCount(2)
        ->and($sent[0]['chat'])->toBe('-1001')
        ->and($sent[0]['thread'])->toBe('11')
        ->and($sent[0]['text'])->toContain('2 × полотенце')
        ->not->toContain('3 × простыня')
        ->and($sent[1]['chat'])->toBe('-1002')
        ->and($sent[1]['thread'])->toBe('22')
        ->and($sent[1]['text'])->toContain('3 × простыня')
        ->not->toContain('2 × полотенце');
});
