<?php

namespace App\Http\Controllers;

use App\Models\SalaryQuestion;
use App\Services\Forms\SalaryQuestionDecisionService;
use App\Services\Forms\TelegramSalaryQuestionService;
use App\Services\RolePreviewContext;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TelegramSalaryDecisionController extends Controller
{
    public function __construct(
        private readonly SalaryQuestionDecisionService $decisions,
        private readonly TelegramSalaryQuestionService $telegram,
    ) {}

    private function grant(Request $request, SalaryQuestion $question, string $action): array
    {
        app(RolePreviewContext::class)->assertWritable();
        abort_unless($this->decisions->enabled() && $this->decisions->canReview($request->user())
            && $this->decisions->hasTelegramSource($question), 403);
        $grant = $request->session()->get('salary_review_grant');
        abort_unless(is_array($grant)
            && (int) ($grant['question_id'] ?? 0) === (int) $question->id
            && (int) ($grant['actor_id'] ?? 0) === (int) $request->user()->id
            && ($grant['action'] ?? null) === $action
            && ($grant['expires_at'] ?? 0) >= now()->timestamp
            && isset(SalaryQuestionDecisionService::ACTIONS[$action]), 403, 'Откройте кнопку заявки в Telegram заново.');

        return $grant;
    }

    public function show(Request $request, SalaryQuestion $salaryQuestion, string $action): Response
    {
        $grant = $this->grant($request, $salaryQuestion, $action);
        abort_unless($this->decisions->canOpenTelegram($salaryQuestion, $request->user())
            && (int) $salaryQuestion->decision_version === (int) $grant['version'], 409, 'Заявка уже изменена. Откройте её заново.');

        return $this->page($salaryQuestion, $action, (int) $grant['version']);
    }

    public function store(Request $request, SalaryQuestion $salaryQuestion, string $action): Response
    {
        $grant = $this->grant($request, $salaryQuestion, $action);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1800'], 'version' => ['required', 'integer', 'min:0']]);
        if ((int) $validated['version'] !== (int) $grant['version']) {
            throw ValidationException::withMessages(['comment' => 'Откройте заявку заново.']);
        }
        $decision = $this->decisions->decide(
            $salaryQuestion, $request->user(), SalaryQuestionDecisionService::ACTIONS[$action],
            $validated['comment'], (int) $grant['version'], 'telegram',
        );

        return $this->page($salaryQuestion->fresh(), $action, (int) $grant['version'], $decision->comment);
    }

    private function page(SalaryQuestion $question, string $action, int $version, ?string $savedComment = null): Response
    {
        $question->loadMissing('user');

        return response()->view('telegram.salary-decision', [
            'question' => $question,
            'requestLabel' => 'Вопрос по зарплате',
            'formAction' => route('salary.decision.store', [$question, $action]),
            'employeeName' => $this->telegram->employeeName($question->user),
            'decisionAction' => $action,
            'version' => $version,
            'savedComment' => $savedComment,
            'title' => match ($action) {
                'approve' => 'Одобрение заявки', 'reject' => 'Отказ по заявке', default => 'Принятие в работу',
            },
            'prompt' => match ($action) {
                'approve' => 'Что будет сделано?', 'reject' => 'Почему отказано?', default => 'Что сейчас проверяем?',
            },
            'button' => match ($action) {
                'approve' => 'Одобрить заявку', 'reject' => 'Отказать', default => 'Принять в работу',
            },
        ])->header('Cache-Control', 'private, no-store');
    }
}
