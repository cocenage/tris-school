<?php

namespace App\Services\Forms;

use App\Jobs\DeliverSalaryQuestionDecision;
use App\Models\StaffTextQuestion;
use App\Models\StaffTextQuestionDecision;
use App\Models\User;
use App\Services\RolePreviewContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

abstract class StaffTextQuestionDecisionService
{
    protected const KIND = 'salary';
    protected const PANEL = 'finance';
    protected const MODEL = \App\Models\SalaryQuestion::class;

    public const ACTIONS = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'work' => 'in_progress',
    ];

    public function enabled(): bool
    {
        return (bool) config('services.staff_forms.'.static::KIND.'_telegram_decisions_enabled', false);
    }

    public function canReview(?User $user): bool
    {
        return $user !== null && $user->is_active === true && $user->isApproved()
            && ($user->isAdmin() || ($user->role === 'supervisor' && $user->hasPanelAccess(static::PANEL)));
    }

    public function canDecide(StaffTextQuestion $question, ?User $user): bool
    {
        return is_a($question, static::MODEL) && $this->canReview($user) && $question->usesDecisionWorkflow()
            && in_array($question->status, ['pending', 'in_progress'], true)
            && ($question->status !== 'in_progress' || ! $question->reviewed_by
                || (int) $question->reviewed_by === (int) $user->id || $user->isAdmin());
    }

    public function canOpenTelegram(StaffTextQuestion $question, ?User $user): bool
    {
        return $this->enabled() && $this->canDecide($question, $user) && $this->hasTelegramSource($question);
    }

    public function hasTelegramSource(StaffTextQuestion $question): bool
    {
        return filled($question->telegram_source_message_id)
            && filled($question->telegram_source_chat_id)
            && filled($question->telegram_source_thread_id)
            && (string) $question->telegram_source_chat_id === (string) config('services.staff_forms.chat_id')
            && (string) $question->telegram_source_thread_id === (string) config('services.staff_forms.'.static::KIND.'_thread_id');
    }

    public function decide(StaffTextQuestion $question, User $actor, string $status, string $comment, int $version, string $source): StaffTextQuestionDecision
    {
        abort_unless(is_a($question, static::MODEL) && $this->canReview($actor) && in_array($source, ['telegram', 'filament'], true), 403);
        app(RolePreviewContext::class)->assertWritable();

        $comment = trim($comment);
        if (! in_array($status, $question::DECISION_STATUSES, true) || $comment === '' || mb_strlen($comment) > 1800) {
            throw ValidationException::withMessages(['comment' => 'Укажите решение и комментарий (до 1800 символов).']);
        }

        $decision = DB::transaction(function () use ($question, $actor, $status, $comment, $version, $source): StaffTextQuestionDecision {
            $current = $question->newQuery()->lockForUpdate()->findOrFail($question->id);
            $freshActor = User::query()->find($actor->id);
            abort_unless($this->canReview($freshActor), 403);
            $existing = $current->decisions()->where('version', $version + 1)->first();
            if ($existing && (int) $existing->reviewer_id === (int) $actor->id
                && $existing->status === $status && $existing->comment === $comment
                && $existing->source === $source) {
                return $existing;
            }
            if ((int) $current->decision_version !== $version) {
                throw ValidationException::withMessages(['comment' => 'Заявка уже изменена. Откройте её заново.']);
            }

            if (! $this->canDecide($current, $freshActor)
                || ($source === 'telegram' && ! $this->canOpenTelegram($current, $freshActor))) {
                throw ValidationException::withMessages(['comment' => 'Заявка уже рассмотрена или закреплена за другим руководителем.']);
            }

            $now = now();
            $changed = $question->newQuery()->whereKey($current->id)
                ->where('decision_version', $version)->where('status', $current->status)
                ->update([
                    'status' => $status, 'admin_comment' => $comment,
                    'reviewed_by' => $actor->id, 'reviewed_at' => $now,
                    'answered_at' => $now, 'answer_seen_at' => null,
                    'decision_version' => $version + 1, 'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                throw ValidationException::withMessages(['comment' => 'Заявка уже изменена. Откройте её заново.']);
            }

            $current->decisions()->whereIn('card_sync_status', ['pending', 'failed'])->update(['card_sync_status' => 'superseded']);

            return $current->decisions()->create([
                'reviewer_id' => $actor->id, 'version' => $version + 1,
                'status' => $status, 'comment' => $comment, 'source' => $source,
            ]);
        }, 3);

        try {
            DeliverSalaryQuestionDecision::dispatch($decision->id, static::KIND)->afterCommit();
        } catch (\Throwable $exception) {
            // The durable decision is recovered by the scheduler even if dispatch fails.
            Log::warning('Salary decision delivery could not be queued.', ['decision_id' => $decision->id, 'exception' => class_basename($exception)]);
        }

        return $decision;
    }
}
