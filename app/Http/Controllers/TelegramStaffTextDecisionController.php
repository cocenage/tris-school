<?php

namespace App\Http\Controllers;

use App\Models\StaffTextQuestion;
use App\Services\Forms\StaffTextQuestionDecisionService;
use App\Services\Forms\TelegramStaffTextQuestionService;
use App\Services\RolePreviewContext;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TelegramStaffTextDecisionController extends Controller
{
    private StaffTextQuestionDecisionService $decisions;
    private TelegramStaffTextQuestionService $telegram;
    private string $kind;

    private function load(string $kind, int $id): StaffTextQuestion
    {
        [$model, $service, $telegram] = match ($kind) {
            'schedule' => [\App\Models\ScheduleQuestion::class, \App\Services\Forms\ScheduleQuestionDecisionService::class, \App\Services\Forms\TelegramScheduleQuestionService::class],
            'feedback' => [\App\Models\FeedbackSuggestion::class, \App\Services\Forms\FeedbackSuggestionDecisionService::class, \App\Services\Forms\TelegramFeedbackSuggestionService::class],
            default => abort(404),
        };
        $this->kind = $kind;
        $this->decisions = app($service);
        $this->telegram = app($telegram);
        return $model::query()->findOrFail($id);
    }

    private function grant(Request $request, StaffTextQuestion $question, string $action): array
    {
        app(RolePreviewContext::class)->assertWritable();
        abort_unless($this->decisions->enabled() && $this->decisions->canReview($request->user())
            && $this->decisions->hasTelegramSource($question), 403);
        $grant = $request->session()->get('staff_text_review_grant');
        abort_unless(is_array($grant)
            && ($grant['kind'] ?? null) === $this->kind
            && (int) ($grant['question_id'] ?? 0) === (int) $question->id
            && (int) ($grant['actor_id'] ?? 0) === (int) $request->user()->id
            && ($grant['action'] ?? null) === $action
            && ($grant['expires_at'] ?? 0) >= now()->timestamp
            && isset($this->decisions::ACTIONS[$action]), 403, 'Откройте кнопку заявки в Telegram заново.');

        return $grant;
    }

    public function show(Request $request, string $kind, int $questionId, string $action): Response
    {
        $salaryQuestion = $this->load($kind, $questionId);
        $grant = $this->grant($request, $salaryQuestion, $action);
        abort_unless($this->decisions->canOpenTelegram($salaryQuestion, $request->user())
            && (int) $salaryQuestion->decision_version === (int) $grant['version'], 409, 'Заявка уже изменена. Откройте её заново.');

        return $this->page($salaryQuestion, $action, (int) $grant['version']);
    }

    public function store(Request $request, string $kind, int $questionId, string $action): Response
    {
        $salaryQuestion = $this->load($kind, $questionId);
        $grant = $this->grant($request, $salaryQuestion, $action);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1800'], 'version' => ['required', 'integer', 'min:0']]);
        if ((int) $validated['version'] !== (int) $grant['version']) {
            throw ValidationException::withMessages(['comment' => 'Откройте заявку заново.']);
        }
        $decision = $this->decisions->decide(
            $salaryQuestion, $request->user(), $this->decisions::ACTIONS[$action],
            $validated['comment'], (int) $grant['version'], 'telegram',
        );

        return $this->page($salaryQuestion->fresh(), $action, (int) $grant['version'], $decision->comment);
    }

    private function page(StaffTextQuestion $question, string $action, int $version, ?string $savedComment = null): Response
    {
        $question->loadMissing('user');

        return response()->view('telegram.salary-decision', [
            'question' => $question,
            'requestLabel' => $this->kind === 'feedback' ? 'Обратная связь' : 'Вопрос по графику',
            'formAction' => route('staff-text.decision.store', [$this->kind, $question->id, $action]),
            'employeeName' => $this->telegram->employeeName($question->user),
            'decisionAction' => $action,
            'version' => $version,
            'savedComment' => $savedComment,
            'title' => match ($action) {
                'reply' => 'Ответ на обращение', 'approve' => 'Одобрение заявки', 'reject' => 'Отказ по заявке', default => 'Принятие в работу',
            },
            'prompt' => match ($action) {
                'reply' => 'Что ответим сотруднику?', 'approve' => 'Что будет сделано?', 'reject' => 'Почему отказано?', default => 'Что сейчас проверяем?',
            },
            'button' => match ($action) {
                'reply' => 'Отправить ответ', 'approve' => 'Одобрить заявку', 'reject' => 'Отказать', default => 'Принять в работу',
            },
        ])->header('Cache-Control', 'private, no-store');
    }
}
