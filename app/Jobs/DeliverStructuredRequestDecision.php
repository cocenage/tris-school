<?php

namespace App\Jobs;

use App\Models\StructuredRequestDecision;
use App\Services\Forms\TelegramStructuredRequestService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class DeliverStructuredRequestDecision implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 40;
    public int $uniqueFor = 600;

    public function __construct(public int $decisionId) {}

    public function uniqueId(): string
    {
        return (string) $this->decisionId;
    }

    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(TelegramStructuredRequestService $telegram): void
    {
        $decision = StructuredRequestDecision::query()->find($this->decisionId);
        if (! $decision) {
            return;
        }
        Cache::lock('structured-request-delivery:'.$decision->id, 60)->block(5, function () use ($decision, $telegram): void {
            $decision->refresh();
            try {
                $telegram->deliver($decision);
            } finally {
                $telegram->syncSource($decision);
            }
        });
    }
}
