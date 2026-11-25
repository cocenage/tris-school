<?php

namespace App\Services\Forms;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;

class TelegramScheduleQuestionService extends TelegramStaffTextQuestionService
{
    protected const KIND = 'schedule';
    protected const KEY = 'schedule_question';
    protected const RESOURCE = 'schedule-questions';
    protected const TITLE = '📅 Вопрос по графику';
    protected const RESULT_TITLE = '📅 Ответ на вопрос по графику';

    public function __construct(TelegramBotService $telegram, TelegramOutboundMonitor $monitor, ScheduleQuestionDecisionService $decisions)
    {
        parent::__construct($telegram, $monitor, $decisions);
    }
}
