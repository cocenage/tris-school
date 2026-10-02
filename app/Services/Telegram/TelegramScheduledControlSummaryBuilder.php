<?php

namespace App\Services\Telegram;

use App\Models\TelegramScheduledMessageDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramScheduledControlSummaryBuilder
{
    public function __construct(private TelegramScheduledControlStatistics $statistics) {}

    public function build(string $date, ?int $scheduledMessageId = null, ?string $controlType = null, ?string $chatId = null, ?string $throughDate = null): array
    {
        $start = $this->day($date);
        $end = $this->day($throughDate ?? $date)->addDay();
        if ($end->lte($start)) {
            throw new InvalidArgumentException('Invalid summary date range.');
        }
        $timezone = config('app.timezone', 'Europe/Rome');
        $query = TelegramScheduledMessageDelivery::query()
            ->with(['responses', 'scheduledMessage' => fn ($query) => $query->withTrashed()])
            ->where('scheduled_for', '>=', $start->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->where('scheduled_for', '<', $end->setTimezone($timezone)->format('Y-m-d H:i:s'))
            ->when($scheduledMessageId !== null, fn ($query) => $query->where('scheduled_message_id', $scheduledMessageId))
            ->when(filled($controlType), fn ($query) => $query->where('control_type', $controlType))
            ->when(filled($chatId), fn ($query) => $query->where('chat_id', $chatId))
            ->orderBy('scheduled_for')->orderBy('id');
        $deliveries = $query->get()->map(function ($delivery) use ($timezone): array {
            $stats = $this->statistics->delivery($delivery);
            $exceptions = $delivery->responses->whereIn('classification', ['problem', 'partial'])->take(3)->map(fn ($response) => [
                'classification' => $response->classification,
                'author_name' => $response->author_name,
                'text' => Str::limit((string) preg_replace('/\s+/u', ' ', $response->text), 180),
            ])->values()->all();

            return $stats + [
                'delivery_id' => $delivery->id, 'scheduled_message_id' => $delivery->scheduled_message_id,
                'control_type' => $delivery->control_type, 'name' => $delivery->scheduledMessage?->name ?? 'Удалённое сообщение',
                'scheduled_for' => CarbonImmutable::parse($delivery->getRawOriginal('scheduled_for'), $timezone)->setTimezone('Europe/Rome')->toIso8601String(),
                'sent_at' => $delivery->sent_at ? CarbonImmutable::parse($delivery->getRawOriginal('sent_at'), $timezone)->setTimezone('Europe/Rome')->toIso8601String() : null,
                'delivery_status' => $delivery->status, 'exceptions' => $exceptions,
            ];
        })->all();
        $totals = $this->aggregate($deliveries);
        $groups = collect($deliveries)->groupBy('scheduled_message_id')->map(fn ($group) => $this->aggregate($group->all()))->all();

        return ['date' => $date, 'through_date' => $throughDate ?? $date, 'timezone' => 'Europe/Rome',
            'expected_responders' => null, 'totals' => $totals, 'by_scheduled_message' => $groups, 'deliveries' => $deliveries];
    }

    public function day(string $date): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Rome');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        return $day;
    }

    private function aggregate(array $deliveries): array
    {
        $rows = collect($deliveries);
        $totals = [
            'controls' => count($deliveries), 'responded' => $rows->where('response_count', '>', 0)->count(),
            'no_response' => $rows->where('result', 'no_response')->count(),
            'pending' => $rows->where('result', 'pending')->where('delivery_status', 'sent')->count(),
            'not_delivered' => $rows->where('delivery_status', '!=', 'sent')->count(),
            'response_messages' => $rows->sum('response_count'),
            'median_response_latency_seconds' => $this->statistics->median($rows->pluck('latency_samples')->flatten()->all()),
        ];
        foreach (['confirmed', 'problem', 'partial', 'unclear'] as $category) {
            $totals[$category] = $rows->where('result', $category)->count();
        }

        return $totals;
    }
}
