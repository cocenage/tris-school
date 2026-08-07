<?php

namespace App\Services\Telegram;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds a compact, read-only evening view of Telegram work forums.
 *
 * This class deliberately reads the analytics store directly. Ingest,
 * webhooks, delivery and AI generation remain outside of the preview path.
 */
class TelegramForumDigestBuilder
{
    private const PROBLEM_TERMS = [
        'проблем', 'не работает', 'нет ключ', 'задерж', 'опозд', 'слом',
        'не успе', 'не могу', 'ошибк', 'не откры', 'боле', 'забастов',
    ];

    private const POSITIVE_TERMS = [
        'спасибо', 'молодец', 'помог', 'решено', 'готово', 'отлично',
        'разобрались', 'закрыли', 'супер',
    ];

    private const RESPONSE_TERMS = [
        'решено', 'готово', 'сделал', 'сделали', 'проверю', 'проверили',
        'исправил', 'исправили', 'закрыт', 'закрыли', 'ответил', 'ответили',
    ];

    public function build(Carbon|string|null $date = null, array $options = []): array
    {
        $timezone = config('app.timezone', 'Europe/Rome');
        $day = $date instanceof Carbon
            ? $date->copy()->setTimezone($timezone)->startOfDay()
            : Carbon::parse($date ?: now($timezone)->toDateString(), $timezone)->startOfDay();

        $route = $this->route($options);

        try {
            $analytics = DB::connection('analytics');
            $base = $this->baseQuery($analytics->table('telegram_messages as m'), $day, $options);

            // Keep the selected projection small: message text is used only for
            // deterministic signal detection and raw payloads are never loaded.
            $rows = (clone $base)
                ->select([
                    'm.telegram_chat_id as chat_pk',
                    'c.telegram_chat_id as chat_id',
                    'c.title as chat_title',
                    't.telegram_thread_id as message_thread_id',
                    't.title as topic_title',
                    'm.telegram_user_id as author_id',
                    'm.message_id',
                    'm.text',
                    'm.caption',
                    'm.sent_at',
                ])
                ->orderBy('m.sent_at')
                ->orderBy('m.id')
                ->get();

            $deduplicated = $rows->unique(fn ($row) => (string) $row->chat_pk . '|' . (string) $row->message_id)->values();
            $duplicateKeys = max(0, $rows->count() - $deduplicated->count());

            $attachmentCounts = $this->attachmentCounts($analytics, $day, $options);
            $forums = $this->aggregate($deduplicated, $attachmentCounts);

            $totals = [
                'forums' => count($forums),
                'active_topics' => collect($forums)->sum('active_topics'),
                'messages' => collect($forums)->sum('message_count'),
                'authors' => $deduplicated->pluck('author_id')->filter()->unique()->count(),
                'attachments' => collect($forums)->sum('attachment_count'),
            ];

            $problemSignals = collect($forums)->sum('problem_signals');
            $positiveSignals = collect($forums)->sum('positive_signals');
            $topicItems = collect($forums)->flatMap(fn (array $forum) => $forum['topics']);

            return [
                'date' => $day->toDateString(),
                'timezone' => $timezone,
                'route' => $route,
                'totals' => $totals,
                'signals' => [
                    'problems' => $problemSignals,
                    'positive' => $positiveSignals,
                    'possible_unanswered_topics' => $topicItems->where('possible_unanswered', true)->count(),
                    'possible_resolved_topics' => $topicItems->where('possible_resolved', true)->count(),
                    'repeated_problem_topics' => $topicItems->where('repeated_problem', true)->count(),
                ],
                'forums' => $forums,
                'attention_required' => $topicItems
                    ->filter(fn (array $topic) => $topic['possible_unanswered'] || $topic['repeated_problem'])
                    ->map(fn (array $topic) => [
                        'chat_id' => $topic['chat_id'],
                        'message_thread_id' => $topic['message_thread_id'],
                        'topic_title' => $topic['topic_title'],
                        'reason' => $topic['possible_unanswered'] ? 'possible_unanswered' : 'repeated_problem',
                    ])->values()->all(),
                'positive_signals' => $topicItems
                    ->filter(fn (array $topic) => $topic['positive_signals'] > 0)
                    ->map(fn (array $topic) => [
                        'chat_id' => $topic['chat_id'],
                        'message_thread_id' => $topic['message_thread_id'],
                        'topic_title' => $topic['topic_title'],
                        'count' => $topic['positive_signals'],
                    ])->values()->all(),
                'data_quality' => [
                    'analytics' => 'available',
                    'private_messages_excluded' => true,
                    'messages_without_thread_excluded' => true,
                    'duplicate_message_keys' => $duplicateKeys,
                    'raw_payload_loaded' => false,
                    'attachments_loaded' => false,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'date' => $day->toDateString(),
                'timezone' => $timezone,
                'route' => $route,
                'totals' => ['forums' => 0, 'active_topics' => 0, 'messages' => 0, 'authors' => 0, 'attachments' => 0],
                'signals' => ['problems' => 0, 'positive' => 0, 'possible_unanswered_topics' => 0, 'possible_resolved_topics' => 0, 'repeated_problem_topics' => 0],
                'forums' => [],
                'attention_required' => [],
                'positive_signals' => [],
                'data_quality' => [
                    'analytics' => 'unavailable',
                    'private_messages_excluded' => true,
                    'messages_without_thread_excluded' => true,
                    'raw_payload_loaded' => false,
                    'attachments_loaded' => false,
                    'reason' => 'analytics_source_unavailable',
                ],
            ];
        }
    }

    private function baseQuery(Builder $messages, Carbon $day, array $options): Builder
    {
        $query = $messages
            ->join('telegram_chats as c', 'c.id', '=', 'm.telegram_chat_id')
            ->join('telegram_topics as t', 't.id', '=', 'm.telegram_topic_id')
            ->where('c.type', 'supergroup')
            ->whereNotNull('t.telegram_thread_id')
            ->whereBetween('m.sent_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);

        if (filled($options['source_chat'] ?? null)) {
            $query->where('c.telegram_chat_id', (string) $options['source_chat']);
        }

        if (filled($options['source_thread'] ?? null)) {
            $query->where('t.telegram_thread_id', (string) $options['source_thread']);
        }

        return $query;
    }

    private function attachmentCounts($analytics, Carbon $day, array $options): array
    {
        $query = $this->baseQuery($analytics->table('telegram_messages as m'), $day, $options)
            ->join('telegram_attachments as a', 'a.telegram_message_id', '=', 'm.id')
            ->selectRaw("c.telegram_chat_id as chat_id, t.telegram_thread_id as message_thread_id, COUNT(DISTINCT a.id) as attachment_count")
            ->groupBy('c.telegram_chat_id', 't.telegram_thread_id');

        return $query->get()->mapWithKeys(fn ($row) => [
            (string) $row->chat_id . '|' . (string) $row->message_thread_id => (int) $row->attachment_count,
        ])->all();
    }

    private function aggregate($rows, array $attachmentCounts): array
    {
        $topics = [];

        foreach ($rows as $row) {
            $topicKey = (string) $row->chat_id . '|' . (string) $row->message_thread_id;
            $text = trim((string) ($row->text ?: $row->caption ?: ''));
            $isProblem = $this->containsAny($text, self::PROBLEM_TERMS);
            $isPositive = $this->containsAny($text, self::POSITIVE_TERMS);
            $isQuestion = str_contains($text, '?') || $this->containsAny($text, ['как ', 'когда ', 'кто ', 'почему ', 'можно ', 'есть ли', 'где ']);
            $isResponse = $this->containsAny($text, self::RESPONSE_TERMS);

            $topics[$topicKey] ??= [
                'chat_id' => (string) $row->chat_id,
                'chat_title' => $row->chat_title,
                'message_thread_id' => (string) $row->message_thread_id,
                'topic_title' => $row->topic_title ?: 'общая тема',
                'message_count' => 0,
                'authors' => [],
                'attachment_count' => $attachmentCounts[$topicKey] ?? 0,
                'problem_signals' => 0,
                'positive_signals' => 0,
                'first_message_at' => $row->sent_at,
                'last_message_at' => $row->sent_at,
                '_author_ids' => [],
                '_signals' => [],
            ];

            $topic =& $topics[$topicKey];
            $topic['message_count']++;
            if ($row->author_id !== null) {
                $topic['authors'][(string) $row->author_id] = true;
                $topic['_author_ids'][(string) $row->author_id] = true;
            }
            $topic['first_message_at'] = min((string) $topic['first_message_at'], (string) $row->sent_at);
            $topic['last_message_at'] = max((string) $topic['last_message_at'], (string) $row->sent_at);
            $topic['problem_signals'] += $isProblem ? 1 : 0;
            $topic['positive_signals'] += $isPositive ? 1 : 0;
            $topic['_signals'][] = compact('isProblem', 'isPositive', 'isQuestion', 'isResponse');
            unset($topic);
        }

        $topicValues = collect($topics)->map(function (array $topic): array {
            $signals = $topic['_signals'];
            $lastProblem = collect($signals)->filter(fn (array $s) => $s['isProblem'] || $s['isQuestion'])->keys()->last();
            $hasResponseAfter = $lastProblem !== null
                && collect(array_slice($signals, $lastProblem + 1))->contains(fn (array $s) => $s['isResponse'] || $s['isPositive']);
            $topic['authors'] = count($topic['authors']);
            $topic['possible_resolved'] = $lastProblem !== null && $hasResponseAfter;
            $topic['possible_unanswered'] = $lastProblem !== null && ! $hasResponseAfter;
            $topic['repeated_problem'] = $topic['problem_signals'] > 1;
            $topic['status'] = $topic['possible_unanswered']
                ? 'possible_unanswered'
                : ($topic['possible_resolved'] ? 'possible_resolved' : null);
            unset($topic['_signals']);

            return $topic;
        })->values();

        return $topicValues->groupBy('chat_id')->map(function ($chatTopics) {
            $first = $chatTopics->first();
            $forumAuthorIds = $chatTopics->flatMap(fn (array $topic) => array_keys($topic['_author_ids']))->unique();
            $topics = $chatTopics->map(function (array $topic) {
                unset($topic['_author_ids']);

                return $topic;
            })->values()->all();

            return [
                'chat_id' => $first['chat_id'],
                'chat_title' => $first['chat_title'],
                'active_topics' => $chatTopics->count(),
                'message_count' => $chatTopics->sum('message_count'),
                'authors' => $forumAuthorIds->count(),
                'attachment_count' => $chatTopics->sum('attachment_count'),
                'problem_signals' => $chatTopics->sum('problem_signals'),
                'positive_signals' => $chatTopics->sum('positive_signals'),
                'first_message_at' => $chatTopics->min('first_message_at'),
                'last_message_at' => $chatTopics->max('last_message_at'),
                'topics' => $topics,
            ];
        })->values()->all();
    }

    private function route(array $options): array
    {
        $chatId = $options['target_chat'] ?? config('services.telegram.digest_target_chat_id');
        $threadId = $options['target_thread'] ?? config('services.telegram.digest_target_thread_id');
        $configured = filled($chatId) && filled($threadId);
        $route = [
            'status' => $configured ? 'configured' : 'not_configured',
            'target_chat_id' => $configured ? (string) $chatId : null,
            'target_message_thread_id' => $configured ? (string) $threadId : null,
            'target_chat_title' => null,
            'target_topic_title' => null,
            'source' => filled($options['target_chat'] ?? null) || filled($options['target_thread'] ?? null)
                ? 'cli'
                : ($configured ? 'config' : 'not_configured'),
        ];

        if (! $configured) {
            return $route;
        }

        try {
            $analytics = DB::connection('analytics');
            $chat = $analytics->table('telegram_chats')->where('telegram_chat_id', (string) $chatId)->first();
            if ($chat) {
                $route['target_chat_title'] = $chat->title;
                $route['target_topic_title'] = $analytics->table('telegram_topics')
                    ->where('telegram_chat_id', $chat->id)
                    ->where('telegram_thread_id', (string) $threadId)
                    ->value('title');
            }
        } catch (Throwable) {
            // Route metadata is optional and never makes a read-only preview fail.
        }

        return $route;
    }

    private function containsAny(string $text, array $terms): bool
    {
        $text = mb_strtolower($text);

        foreach ($terms as $term) {
            if (str_contains($text, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }
}
