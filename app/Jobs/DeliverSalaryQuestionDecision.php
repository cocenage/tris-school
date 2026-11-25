<?php

namespace App\Jobs;

use App\Models\SalaryQuestionDecision;
use App\Services\Forms\TelegramSalaryQuestionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeliverSalaryQuestionDecision implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 40;
    public int $uniqueFor = 600;

    public function __construct(public int $decisionId, public string $kind = 'salary') {}

    public function uniqueId(): string
    {
        return $this->kind.':'.$this->decisionId;
    }

    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(TelegramSalaryQuestionService $telegram): void
    {
        $model = match ($this->kind) {
            'salary' => SalaryQuestionDecision::class,
            'schedule' => \App\Models\ScheduleQuestionDecision::class,
            'feedback' => \App\Models\FeedbackSuggestionDecision::class,
        };
        $telegram = match ($this->kind) {
            'salary' => $telegram,
            'schedule' => app(\App\Services\Forms\TelegramScheduleQuestionService::class),
            'feedback' => app(\App\Services\Forms\TelegramFeedbackSuggestionService::class),
        };
        $decision = $model::query()->find($this->decisionId);
        if (! $decision) {
            return;
        }

        Cache::lock('staff-text-delivery:'.$this->kind.':'.$decision->question?->id, 60)->block(5, function () use ($decision, $telegram): void {
            $decision->refresh();
            $question = $decision->question;
            if (! $question) {
                return;
            }
            // Do not send the final status before an earlier queued "in work" update.
            if ($question->decisions()->where('version', '<', $decision->version)->where('delivery_status', 'pending')->exists()) {
                $this->release(15);
                return;
            }
            try {
                $telegram->deliverDecision($decision);
            } finally {
                $telegram->syncSource($question);
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('Salary decision job failed.', ['decision_id' => $this->decisionId, 'exception' => class_basename($exception)]);
    }
}
