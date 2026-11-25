<?php

namespace App\Services\Forms;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;

class TelegramFeedbackSuggestionService extends TelegramStaffTextQuestionService
{
    protected const KIND = 'feedback';
    protected const KEY = 'feedback_suggestion';
    protected const RESOURCE = 'feedback-suggestions';
    protected const TITLE = '💬 Обратная связь';
    protected const RESULT_TITLE = '💬 Ответ на обратную связь';

    public function __construct(TelegramBotService $telegram, TelegramOutboundMonitor $monitor, FeedbackSuggestionDecisionService $decisions)
    {
        parent::__construct($telegram, $monitor, $decisions);
    }
}
