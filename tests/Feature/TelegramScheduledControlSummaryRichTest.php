<?php

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramRichMessageBuilder;
use App\Services\Telegram\TelegramScheduledControlSummaryDemoFixture;
use App\Services\Telegram\TelegramScheduledControlSummaryRichFormatter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.telegram.bot_token' => 'primary-test-token',
        'services.telegram.analytics_bot_token' => 'analytics-test-token',
        'services.telegram.rich_messages_enabled' => true,
    ]);
});

function richControlSummaryFixture(): array
{
    $district = static function (string $key, string $label, string $status, array $timeline = [], ?string $firstProblemAt = null, ?string $resolvedAt = null): array {
        return [
            'district' => $label,
            'district_key' => $key,
            'status' => $status,
            'scheduled_at' => '2026-11-08T14:00:00+01:00',
            'first_problem_at' => $firstProblemAt,
            'resolved_at' => $resolvedAt,
            'time_uncertain' => false,
            'timeline' => $timeline,
            'followups' => [],
        ];
    };

    $event = static function (string $status, string $at, string $text, ?string $author, ?string $source, ?string $reason = null, ?string $apartment = null, string $responder = 'staff-a'): array {
        return [
            'status' => $status,
            'responded_at' => $at,
            'source_message_id' => $source === null ? null : basename($source),
            'source_url' => $source,
            'text' => $text,
            'author_name' => $author,
            'reason' => $reason,
            'apartment' => $apartment,
            'responder_key' => $responder,
            'is_repeat_check' => false,
        ];
    };

    $delivery = static function (int $id, string $type, string $at, array $history, ?int $trigger = null, array $responses = [], string $status = 'sent'): array {
        return [
            'delivery_id' => $id,
            'trigger_delivery_id' => $trigger,
            'sequence_context' => $trigger === null ? [] : ['pair' => 'second_cleanings', 'stage' => 'second', 'source_status' => 'sent'],
            'control_type' => $type,
            'name' => $type,
            'scheduled_for' => $at,
            'sent_at' => $at,
            'delivery_status' => $status,
            'failure_reason' => $status === 'uncertain' ? 'transport_timeout' : null,
            'source_url' => 'https://t.me/c/100123/'.$id,
            'eligible_for_statistics' => $status === 'sent',
            'district_history' => $history,
            'interpreted_responses' => $responses,
        ];
    };

    $lambrateAt1400 = $event('problem', '2026-11-08T14:02:00+01:00', 'Нет, уборка не завершена', 'Тестовый автор', 'https://t.me/c/100123/701', 'не завершено вовремя', 'Не установлено');
    $lambrateAt1430Yes = $event('confirmed', '2026-11-08T14:31:00+01:00', 'Да, завершили', 'Тестовый автор 2', 'https://t.me/c/100123/702', null, null, 'staff-b');
    $lambrateAt1430No = $event('problem', '2026-11-08T14:32:00+01:00', 'Нет, ещё не завершили', 'Тестовый автор 3', 'https://t.me/c/100123/703', null, null, 'staff-c');

    return [
        'date' => '2026-11-08',
        'through_date' => '2026-11-08',
        'timezone' => 'Europe/Rome',
        'totals' => [
            'controls' => 10,
            'sent' => 10,
            'response_messages' => 12,
            'delays_identified' => 3,
            'delays_resolved' => 0,
            'delays_open' => 3,
            'districts_without_answer' => 1,
            'district_conflicts' => 1,
            'uncertain_deliveries' => 1,
            'not_delivered' => 0,
            'pending_deliveries' => 0,
            'cancelled_deliveries' => 0,
            'skipped_deliveries' => 0,
        ],
        'missing_deliveries' => [],
        'exceptions' => [],
        'deliveries' => [
            $delivery(101, 'second_cleanings_finishing', '2026-11-08T14:00:00+01:00', [
                $district('lambrate', 'Lambrate', 'problem', [$lambrateAt1400], '2026-11-08T14:02:00+01:00'),
                $district('lodi', 'Lodi', 'no_response'),
                $district('certosa', 'Certosa', 'no_response'),
                $district('navigli', 'Navigli', 'confirmed'),
                $district('como', 'Como', 'confirmed'),
            ], responses: [[
                'control_type' => 'second_cleanings_finishing', 'status' => 'problem', 'districts' => [],
                'responded_at' => '2026-11-08T14:02:00+01:00', 'source_message_id' => '701', 'source_url' => 'https://t.me/c/100123/701',
                'author_name' => 'Тестовый автор', 'text' => 'Нет, уборка не завершена', 'full_text' => 'Нет, уборка не завершена',
                'reason' => 'не завершено вовремя', 'apartment' => 'Не установлено', 'responder_key' => 'staff-a',
            ]]),
            $delivery(102, 'second_cleanings_finishing', '2026-11-08T14:30:00+01:00', [
                $district('lambrate', 'Lambrate', 'conflict', [$lambrateAt1430Yes, $lambrateAt1430No], '2026-11-08T14:02:00+01:00'),
            ], 101, responses: [
                ['control_type' => 'second_cleanings_finishing', 'status' => 'confirmed', 'districts' => [], 'responded_at' => '2026-11-08T14:31:00+01:00', 'source_message_id' => '702', 'source_url' => 'https://t.me/c/100123/702', 'author_name' => 'Тестовый автор 2', 'text' => 'Да, завершили', 'full_text' => 'Да, завершили', 'responder_key' => 'staff-b'],
                ['control_type' => 'second_cleanings_finishing', 'status' => 'problem', 'districts' => [], 'responded_at' => '2026-11-08T14:32:00+01:00', 'source_message_id' => '703', 'source_url' => 'https://t.me/c/100123/703', 'author_name' => 'Тестовый автор 3', 'text' => 'Нет, ещё не завершили', 'full_text' => 'Нет, ещё не завершили', 'responder_key' => 'staff-c'],
            ]),
            $delivery(103, 'first_cleanings_started', '2026-11-08T10:25:00+01:00', [
                $district('lodi', 'Lodi', 'problem', [$event('problem', '2026-11-08T10:28:00+01:00', 'Нет, задержка', null, 'https://t.me/c/100123/710')], '2026-11-08T10:28:00+01:00'),
            ], responses: [[
                'control_type' => 'first_cleanings_started', 'status' => 'problem', 'districts' => [], 'responded_at' => '2026-11-08T10:28:00+01:00',
                'source_message_id' => '710', 'source_url' => 'https://t.me/c/100123/710', 'author_name' => null, 'username' => null,
                'text' => 'Нет, задержка', 'full_text' => 'Нет, задержка', 'reason' => null, 'apartment' => null, 'responder_key' => null,
            ]]),
            $delivery(104, 'cleanings_completed', '2026-11-08T17:30:00+01:00', [
                $district('certosa', 'Certosa', 'problem', [$event('problem', '2026-11-08T17:33:00+01:00', 'Уборка не завершена', 'Тестовый автор 4', 'https://t.me/c/100123/720', 'не завершено вовремя')], '2026-11-08T17:33:00+01:00'),
            ], responses: [[
                'control_type' => 'cleanings_completed', 'status' => 'problem', 'districts' => [], 'responded_at' => '2026-11-08T17:33:00+01:00',
                'source_message_id' => '720', 'source_url' => 'https://t.me/c/100123/720', 'author_name' => 'Тестовый автор 4',
                'text' => 'Уборка не завершена', 'full_text' => 'Уборка не завершена', 'reason' => 'не завершено вовремя', 'responder_key' => 'staff-d',
            ]]),
            $delivery(105, 'couriers_completed', '2026-11-08T16:00:00+01:00', [
                $district('navigli', 'Navigli', 'confirmed'), $district('como', 'Como', 'confirmed'),
                $district('lambrate', 'Lambrate', 'no_response'),
            ]),
            $delivery(106, 'schedule_checked', '2026-11-08T09:00:00+01:00', [
                $district('navigli', 'Navigli', 'confirmed'), $district('como', 'Como', 'confirmed'),
            ]),
            $delivery(107, 'first_cleanings_finishing', '2026-11-08T12:30:00+01:00', []),
            $delivery(108, 'couriers_completed', '2026-11-08T16:30:00+01:00', []),
            $delivery(109, 'cleanings_completed', '2026-11-08T18:00:00+01:00', []),
            $delivery(110, 'extra_payments_completed', '2026-11-08T19:00:00+01:00', [], status: 'uncertain'),
        ],
    ];
}

it('groups explicitly linked repeats into one problem story and keeps operational results separate from delivery diagnostics', function (): void {
    $formatter = app(TelegramScheduledControlSummaryRichFormatter::class);
    $rich = $formatter->richMessage(richControlSummaryFixture());
    $markdown = $rich['markdown'];
    $main = explode('<details>', $markdown, 2)[0];

    expect(substr_count($main, 'Lambrate — вторые уборки'))->toBe(1)
        ->and($main)->toContain('10 проведённых контролей')
        ->and($main)->toContain('Противоречивые ответы')
        ->and($main)->toContain('Lodi')
        ->and($main)->toContain('Certosa')
        ->and($markdown)->toContain('Контрольных точек без ответа')
        ->and($main)->not->toContain('неизвестный результат отправки')
        ->and($markdown)->toContain('<details><summary>🔍 Полные ответы по контрольным точкам')
        ->and($markdown)->toContain('14:32')
        ->and($markdown)->toContain('результат отправки неизвестен')
        ->and($main)->toContain('контроль 14:30, ответ 14:31');
});

it('shows unknown reason and author explicitly and links problem sources', function (): void {
    $markdown = app(TelegramScheduledControlSummaryRichFormatter::class)
        ->richMessage(richControlSummaryFixture())['markdown'];

    expect($markdown)->toContain('Причина: Не установлено')
        ->and($markdown)->toContain('Автор: Не установлено')
        ->and($markdown)->toContain('[Исходное сообщение ↗](https://t.me/c/100123/710)');
});

it('keeps a No to Yes history as a confirmed resolution and provides a database-free demo preview', function (): void {
    $summary = app(TelegramScheduledControlSummaryDemoFixture::class)->build('2026-11-08');
    $markdown = app(TelegramScheduledControlSummaryRichFormatter::class)->richMessage($summary)['markdown'];
    Http::fake();

    expect($markdown)->toContain('Lodi — первые уборки')
        ->and($markdown)->toContain('устранено и подтверждено')
        ->and($markdown)->toContain('10 проведённых контролей')
        ->and($markdown)->toContain('12:30 — Lodi — устранение подтверждено')
        ->and(Artisan::call('telegram:control-summary-preview', ['--date' => '2026-11-08', '--demo' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('10:25 — Lodi — проблема обнаружена', 'контроль 14:30, ответ 14:31', 'Certosa')
        ->and(Http::recorded())->toBeEmpty();
});

it('escapes employee text and includes the same essential information in the HTML fallback', function (): void {
    $summary = richControlSummaryFixture();
    $summary['deliveries'][2]['district_history'][0]['timeline'][0]['text'] = '<details><summary>подмена</summary></details>';
    $summary['deliveries'][2]['interpreted_responses'][0]['full_text'] = '<details><summary>подмена</summary></details>';
    $formatter = app(TelegramScheduledControlSummaryRichFormatter::class);
    $markdown = $formatter->richMessage($summary)['markdown'];
    $fallback = $formatter->fallbackHtml($summary);

    expect($markdown)->toContain('&lt;details&gt;')
        ->and($markdown)->not->toContain('<details><summary>подмена')
        ->and($fallback)->toContain('Итоги дня')
        ->and($fallback)->toContain('Подробная история доступна отдельно')
        ->and($fallback)->not->toContain('подмена')
        ->and($fallback)->not->toContain('Тестовый автор')
        ->and($fallback)->not->toContain('<details>');
});

it('keeps a short fallback informative and counts Cyrillic and emoji conservatively', function (): void {
    $summary = richControlSummaryFixture();
    $summary['deliveries'][0]['district_history'][0]['district'] = '👩‍🔬 Москва';
    $formatter = app(TelegramScheduledControlSummaryRichFormatter::class);
    $html = $formatter->fallbackHtml($summary);
    $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $utf16Length = intdiv(strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')), 2);

    expect($html)->toContain('08.11.2026')
        ->and($html)->toContain('👩‍🔬 Москва')
        ->and($html)->toContain('Главные проблемы')
        ->and($html)->toContain('Ключевые итоги')
        ->and($html)->toContain('Подробная история доступна отдельно')
        ->and(TelegramRichMessageBuilder::htmlVisibleLength($html))->toBe($utf16Length)
        ->and($utf16Length)->toBeLessThanOrEqual(TelegramScheduledControlSummaryRichFormatter::FALLBACK_MAX_VISIBLE_CHARACTERS);
});

it('limits huge fallback reports without cutting HTML and preserves deterministic problem order', function (): void {
    $summary = richControlSummaryFixture();
    $longUrl = 'https://t.me/c/'.str_repeat('1', 5000).'/123';

    for ($index = 0; $index < 300; $index++) {
        $at = sprintf('2026-11-08T20:%02d:%02d+01:00', intdiv($index, 60), $index % 60);
        $summary['deliveries'][] = [
            'delivery_id' => 1000 + $index,
            'control_type' => 'cleanings_completed',
            'scheduled_for' => $at,
            'delivery_status' => 'sent',
            'source_url' => $longUrl,
            'district_history' => [[
                'district' => 'District-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'district_key' => 'district_'.$index,
                'status' => 'problem',
                'timeline' => [[
                    'status' => 'problem',
                    'responded_at' => $at,
                    'control_time' => $at,
                    'text' => 'Подробный ответ не должен попадать в краткий итог',
                    'source_url' => $longUrl,
                ]],
                'followups' => [],
            ]],
        ];
    }

    $formatter = app(TelegramScheduledControlSummaryRichFormatter::class);
    $html = $formatter->fallbackHtml($summary);
    $secondRender = $formatter->fallbackHtml($summary);
    preg_match('/… не показано проблемных записей: (\d+)/u', $html, $omittedMatch);
    preg_match_all('/District-\d{3}/u', $html, $includedMatches);

    $previousLibxmlState = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $validHtml = $document->loadHTML('<!doctype html><html><body>'.$html.'</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    $htmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    expect(TelegramRichMessageBuilder::htmlVisibleLength($html))->toBeLessThanOrEqual(TelegramScheduledControlSummaryRichFormatter::FALLBACK_MAX_VISIBLE_CHARACTERS)
        ->and($html)->toBe($secondRender)
        ->and((int) ($omittedMatch[1] ?? 0))->toBe(300 - count($includedMatches[0]))
        ->and($includedMatches[0][0] ?? null)->toBe('District-000')
        ->and($includedMatches[0][1] ?? null)->toBe('District-001')
        ->and($html)->not->toContain($longUrl)
        ->and($html)->not->toContain('Подробный ответ не должен попадать')
        ->and($validHtml)->toBeTrue()
        ->and($htmlErrors)->toBeEmpty();
});

it('sends the summary through analytics sendRichMessage when Rich Messages are enabled', function (): void {
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 501]], 200));

    $id = app(TelegramBotService::class)->sendAnalyticsRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Итоги'],
        fallbackHtml: '<b>Итоги</b>',
        threadId: '17',
    );

    expect(Http::recorded()->first()[1]->status())->toBe(200)
        ->and(Http::recorded()->first()[1]->json())->toMatchArray(['ok' => true, 'result' => ['message_id' => 501]])
        ->and(config('services.telegram.analytics_bot_token'))->toBe('analytics-test-token')
        ->and(Http::recorded())->toHaveCount(1)
        ->and($id)->toBe(501);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/botanalytics-test-token/sendRichMessage')
        && ($request['rich_message']['markdown'] ?? null) === '# Итоги'
        && ($request['message_thread_id'] ?? null) === 17);
});

it('uses the HTML fallback after an explicit Rich Message rejection', function (): void {
    Http::fakeSequence()
        ->push(['ok' => false, 'description' => 'unsupported'], 400)
        ->push(['ok' => true, 'result' => ['message_id' => 502]], 200);

    $id = app(TelegramBotService::class)->sendAnalyticsRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Итоги'],
        fallbackHtml: '<b>Итоги</b>',
    );

    expect($id)->toBe(502)->and(Http::recorded())->toHaveCount(2);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/botanalytics-test-token/sendMessage')
        && ($request['text'] ?? null) === '<b>Итоги</b>');
});

it('uses one analytics HTML message when Rich Messages are disabled', function (): void {
    config(['services.telegram.rich_messages_enabled' => false]);
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 503]], 200));

    $id = app(TelegramBotService::class)->sendAnalyticsRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Итоги'],
        fallbackHtml: '<b>Итоги</b>',
    );

    expect($id)->toBe(503)->and(Http::recorded())->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/botanalytics-test-token/sendMessage')
        && ($request['text'] ?? null) === '<b>Итоги</b>');
});

it('refuses an oversized analytics HTML fallback before making a Telegram request', function (): void {
    config(['services.telegram.rich_messages_enabled' => false]);
    Http::fake();
    $fallback = '<b>'.str_repeat('🌧️', 1751).'</b>';

    $id = app(TelegramBotService::class)->sendAnalyticsRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Итоги'],
        fallbackHtml: $fallback,
        maxFallbackVisibleLength: TelegramScheduledControlSummaryRichFormatter::FALLBACK_MAX_VISIBLE_CHARACTERS,
    );

    expect($id)->toBeNull()->and(Http::recorded())->toBeEmpty();
});

it('does not send an oversized fallback after an explicit Rich Message rejection', function (): void {
    Http::fakeSequence()->push(['ok' => false, 'description' => 'unsupported'], 400);

    $id = app(TelegramBotService::class)->sendAnalyticsRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Итоги'],
        fallbackHtml: '<b>'.str_repeat('Ж', 3501).'</b>',
        maxFallbackVisibleLength: TelegramScheduledControlSummaryRichFormatter::FALLBACK_MAX_VISIBLE_CHARACTERS,
    );

    expect($id)->toBeNull()->and(Http::recorded())->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
});

it('does not send a fallback after an uncertain Rich Message result', function (): void {
    Http::fake(fn () => Http::response(['ok' => false, 'error_code' => 500], 500));

    $id = app(TelegramBotService::class)->sendAnalyticsRichMessage(
        chatId: '-100123',
        richMessage: ['markdown' => '# Итоги'],
        fallbackHtml: '<b>Итоги</b>',
    );

    expect($id)->toBeNull()->and(Http::recorded())->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
});
