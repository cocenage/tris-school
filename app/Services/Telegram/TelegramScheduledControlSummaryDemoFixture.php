<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

class TelegramScheduledControlSummaryDemoFixture
{
    /** A fully synthetic daily summary for a safe, database-free preview. */
    public function build(string $date): array
    {
        $day = CarbonImmutable::parse($date, 'Europe/Rome');
        $at = static fn (string $time): string => $day->setTimeFromTimeString($time)->toIso8601String();
        $district = static fn (string $key, string $label, string $status, array $timeline = [], ?string $firstProblemAt = null, ?string $resolvedAt = null, array $followups = []): array => [
            'district' => $label, 'district_key' => $key, 'status' => $status,
            'scheduled_at' => $at('14:00'), 'first_problem_at' => $firstProblemAt,
            'resolved_at' => $resolvedAt, 'time_uncertain' => false, 'timeline' => $timeline, 'followups' => $followups,
        ];
        $event = static function (string $status, string $time, string $messageId, string $text, ?string $author, ?string $responder, ?string $reason = null, ?string $apartment = null): array {
            return [
                'status' => $status, 'responded_at' => $time, 'source_message_id' => $messageId,
                'source_url' => 'https://t.me/c/100123/'.$messageId, 'response_id' => $messageId,
                'text' => $text, 'author_name' => $author, 'username' => null,
                'responder_key' => $responder, 'reason' => $reason, 'apartment' => $apartment,
                'is_repeat_check' => false,
            ];
        };
        $delivery = static function (int $id, string $type, string $time, array $history, ?int $trigger = null, array $responses = []): array {
            return [
                'delivery_id' => $id, 'trigger_delivery_id' => $trigger,
                'sequence_context' => $trigger === null ? [] : [
                    'pair' => $trigger === 801 ? 'first_cleanings' : 'second_cleanings',
                    'stage' => 'second', 'source_status' => 'sent',
                ],
                'control_type' => $type, 'name' => $type, 'scheduled_for' => $time, 'sent_at' => $time,
                'delivery_status' => 'sent', 'failure_reason' => null, 'source_url' => 'https://t.me/c/100123/'.$id,
                'eligible_for_statistics' => true, 'district_history' => $history, 'interpreted_responses' => $responses,
            ];
        };
        $response = static function (string $type, array $event, string $text, ?string $reason = null, ?string $apartment = null): array {
            return [
                'control_type' => $type, 'status' => $event['status'], 'districts' => [],
                'responded_at' => $event['responded_at'], 'source_message_id' => $event['source_message_id'],
                'source_url' => $event['source_url'], 'author_name' => $event['author_name'], 'username' => null,
                'text' => $text, 'full_text' => $text, 'reason' => $reason, 'apartment' => $apartment,
                'responder_key' => $event['responder_key'],
            ];
        };

        $lodiProblem = $event('problem', $at('10:28'), '710', 'Нет', null, 'lodi-staff');
        $lodiDone = $event('confirmed', $at('12:31'), '711', 'Да, завершили', 'Сотрудник тест', 'lodi-staff');
        $lambrateProblem = $event('problem', $at('14:02'), '720', 'Нет, вторые уборки ещё не завершены', 'Автор тест A', 'lambrate-a', 'Не завершено вовремя');
        $lambrateYes = $event('confirmed', $at('14:31'), '721', 'Да, завершено', 'Автор тест B', 'lambrate-b');
        $lambrateNo = $event('problem', $at('14:32'), '722', 'Нет, уборка не завершена', 'Автор тест C', 'lambrate-c', 'Не завершено вовремя');
        $certosaProblem = $event('problem', $at('17:33'), '730', 'Уборка не завершена', 'Автор тест D', 'certosa-d', 'Не завершено вовремя');
        $unknownFollowup = ['kind' => 'repeat', 'sequence' => 1, 'status' => 'uncertain', 'sent_at' => null, 'telegram_message_id' => null];

        return [
            'date' => $date, 'through_date' => $date, 'timezone' => 'Europe/Rome', 'configuration_history_uncertain' => false,
            'has_district_history' => true, 'expected_responders' => null, 'no_response_available' => false,
            'totals' => [
                'controls' => 10, 'expected' => 10, 'sent' => 10, 'responded' => 9, 'response_messages' => 12,
                'response_rate_percent' => 90, 'no_response' => 1, 'pending' => 0, 'not_delivered' => 0,
                'cancelled_deliveries' => 0, 'skipped_deliveries' => 0, 'uncertain_deliveries' => 0,
                'delays_identified' => 3, 'delays_resolved' => 1, 'delays_open' => 2,
                'districts_without_answer' => 1, 'districts_unclear' => 0, 'district_conflicts' => 1,
                'followup_delivery_failures' => 0, 'followup_delivery_uncertain' => 1,
            ],
            'missing_deliveries' => [], 'exceptions' => [], 'controls' => [], 'by_scheduled_message' => [],
            'deliveries' => [
                $delivery(801, 'first_cleanings_started', $at('10:25'), [
                    $district('lodi', 'Lodi', 'problem', [$lodiProblem], $lodiProblem['responded_at']),
                ], responses: [$response('first_cleanings_started', $lodiProblem, 'Нет')]),
                $delivery(802, 'first_cleanings_finishing', $at('12:30'), [
                    $district('lodi', 'Lodi', 'confirmed', [$lodiDone], $lodiProblem['responded_at'], $lodiDone['responded_at']),
                ], 801, [$response('first_cleanings_finishing', $lodiDone, 'Да, завершили')]),
                $delivery(803, 'second_cleanings_finishing', $at('14:00'), [
                    $district('lambrate', 'Lambrate', 'problem', [$lambrateProblem], $lambrateProblem['responded_at'], followups: [$unknownFollowup]),
                ], responses: [$response('second_cleanings_finishing', $lambrateProblem, $lambrateProblem['text'], $lambrateProblem['reason'])]),
                $delivery(804, 'second_cleanings_finishing', $at('14:30'), [
                    $district('lambrate', 'Lambrate', 'conflict', [$lambrateYes, $lambrateNo], $lambrateProblem['responded_at']),
                ], 803, [
                    $response('second_cleanings_finishing', $lambrateYes, $lambrateYes['text']),
                    $response('second_cleanings_finishing', $lambrateNo, $lambrateNo['text'], $lambrateNo['reason']),
                ]),
                $delivery(805, 'couriers_completed', $at('14:00'), [
                    $district('navigli', 'Navigli', 'confirmed'), $district('como', 'Como', 'confirmed'),
                ]),
                $delivery(806, 'schedule_checked', $at('09:00'), [
                    $district('navigli', 'Navigli', 'confirmed'),
                ]),
                $delivery(807, 'cleanings_completed', $at('17:30'), [
                    $district('certosa', 'Certosa', 'problem', [$certosaProblem], $certosaProblem['responded_at']),
                ], responses: [$response('cleanings_completed', $certosaProblem, $certosaProblem['text'], $certosaProblem['reason'])]),
                $delivery(808, 'schedule_checked', $at('08:30'), [
                    $district('certosa', 'Certosa', 'no_response'),
                ]),
                $delivery(809, 'extra_payments_completed', $at('18:00'), [
                    $district('como', 'Como', 'confirmed'), $district('navigli', 'Navigli', 'confirmed'),
                ]),
                $delivery(810, 'couriers_completed', $at('16:30'), [
                    $district('como', 'Como', 'confirmed'), $district('navigli', 'Navigli', 'confirmed'),
                ]),
            ],
        ];
    }
}
