<?php

namespace App\Console\Commands;

use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramTopicTitleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TelegramTopicsBackfillTitlesCommand extends Command
{
    protected $signature = 'telegram:topics-backfill-titles
        {--apply : Persist deterministic titles instead of performing a dry-run}
        {--show-unresolved : Show unresolved topic records with compact recent context}';

    protected $description = 'Recover missing Telegram forum topic titles from stored analytics updates';

    public function handle(TelegramTopicTitleResolver $resolver): int
    {
        $counts = [
            'scanned' => 0,
            'recoverable' => 0,
            'direct' => 0,
            'reply' => 0,
            'meaningful' => 0,
            'unresolved' => 0,
            'conflicts' => 0,
            'would_update' => 0,
            'writes' => 0,
        ];
        $unresolved = [];
        $apply = (bool) $this->option('apply');

        $topics = TelegramTopic::query()
            ->with('chat')
            ->orderBy('id')
            ->get();
        $recoveries = $resolver->resolveMany(
            $topics->reject(fn (TelegramTopic $topic): bool => $resolver->isMeaningful($topic->title)),
        );

        $topics->each(function (TelegramTopic $topic) use ($resolver, $recoveries, &$counts, &$unresolved, $apply): void {
            $counts['scanned']++;

            if ($resolver->isMeaningful($topic->title)) {
                $counts['meaningful']++;

                return;
            }

            $result = $recoveries[$topic->id];

            if ($result['status'] === 'conflict') {
                $counts['conflicts']++;

                return;
            }

            if ($result['status'] !== 'resolved') {
                $counts['unresolved']++;
                $unresolved[] = $topic;

                return;
            }

            $counts['recoverable']++;
            $counts[$result['source']]++;
            $counts['would_update']++;

            if ($apply) {
                $topic->update(['title' => $result['title']]);
                $counts['writes']++;
            }
        });

        $this->table(['Metric', 'Count'], [
            ['Topics scanned', $counts['scanned']],
            ['Recoverable', $counts['recoverable']],
            ['Direct service event', $counts['direct']],
            ['Reply service evidence', $counts['reply']],
            ['Already meaningful / skipped', $counts['meaningful']],
            ['Unresolved', $counts['unresolved']],
            ['Conflicts', $counts['conflicts']],
            ['Would update', $counts['would_update']],
            ['Writes performed', $counts['writes']],
        ]);

        if ($this->option('show-unresolved') && $unresolved !== []) {
            $this->newLine();
            $this->warn('Unresolved topics (local inspection only):');

            foreach ($unresolved as $topic) {
                $context = $topic->messages()
                    ->latest('sent_at')
                    ->latest('id')
                    ->limit(2)
                    ->get()
                    ->map(fn ($message): string => Str::limit(
                        Str::squish((string) ($message->text ?: $message->caption)),
                        90,
                    ))
                    ->filter()
                    ->implode(' · ');

                $this->line(sprintf(
                    'record=%d chat=%s thread=%s context=%s',
                    $topic->id,
                    $topic->chat?->title ?: '—',
                    $topic->telegram_thread_id,
                    $context ?: '—',
                ));
            }
        }

        if (! $apply) {
            $this->info('Dry-run complete. No database writes were performed.');
        }

        return self::SUCCESS;
    }
}
