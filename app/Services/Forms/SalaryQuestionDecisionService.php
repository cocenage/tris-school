<?php

namespace App\Services\Forms;

use App\Jobs\DeliverSalaryQuestionDecision;
use App\Models\SalaryQuestion;
use App\Models\SalaryQuestionDecision;
use App\Models\User;
use App\Services\RolePreviewContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SalaryQuestionDecisionService
{
    public const ACTIONS = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'work' => 'in_progress',
    ];

    public function enabled(): bool
    {
        return (bool) config('services.staff_forms.salary_telegram_decisions_enabled', false);
    }

    public function canReview(?User $user): bool
    {
        return $user !== null && $user->is_active === true && $user->isApproved()
            && ($user->isAdmin() || ($user->role === 'supervisor' && $user->hasPanelAccess('finance')));
    }

    public function canDecide(SalaryQuestion $question, ?User $user): bool
    {
        return $this->canReview($user) && $question->usesDecisionWorkflow()
            && in_array($question->status, ['pending', 'in_progress'], true)
            && ($question->status !== 'in_progress' || ! $question->reviewed_by
                || (int) $question->reviewed_by === (int) $user->id || $user->isAdmin());
    }

    public function canOpenTelegram(SalaryQuestion $question, ?User $user): bool
    {
        return $this->enabled() && $this->canDecide($question, $user)
            && filled($question->telegram_source_message_id)
            && filled($question->telegram_source_chat_id)
            && filled($question->telegram_source_thread_id)
            && (string) $question->telegram_source_chat_id === (string) config('services.staff_forms.chat_id')
            && (string) $question->telegram_source_thread_id === (string) config('services.staff_forms.salary_thread_id');
    }

    public function decide(SalaryQuestion $question, User $actor, string $status, string $comment, int $version, string $source): SalaryQuestionDecision
    {
        abort_unless($this->canReview($actor), 403);
        app(RolePreviewContext::class)->assertWritable();

        $comment = trim($comment);
        if (! in_array($status, SalaryQuestion::DECISION_STATUSES, true) || $comment === '' || mb_strlen($comment) > 1800) {
            throw ValidationException::withMessages(['comment' => 'Укажите решение и комментарий (до 1800 символов).']);
        }

        $decision = DB::transaction(function () use ($question, $actor, $status, $comment, $version, $source): SalaryQuestionDecision {
            $current = SalaryQuestion::query()->lockForUpdate()->findOrFail($question->id);
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
            $changed = SalaryQuestion::query()->whereKey($current->id)
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
            DeliverSalaryQuestionDecision::dispatch($decision->id)->afterCommit();
        } catch (\Throwable $exception) {
            // The durable decision is recovered by the scheduler even if dispatch fails.
            Log::warning('Salary decision delivery could not be queued.', ['decision_id' => $decision->id, 'exception' => class_basename($exception)]);
        }

        return $decision;
    }
}
