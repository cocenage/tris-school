<?php

namespace App\Services\Forms;

use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;

class TelegramSalaryQuestionService extends TelegramStaffTextQuestionService
{
    protected const KIND = 'salary';
    protected const KEY = 'salary_question';
    protected const RESOURCE = 'salary-questions';
    protected const TITLE = '💰 Вопрос по зарплате';
    protected const RESULT_TITLE = '💰 Ответ на вопрос по зарплате';

    public function __construct(TelegramBotService $telegram, TelegramOutboundMonitor $monitor, SalaryQuestionDecisionService $decisions)
    {
        parent::__construct($telegram, $monitor, $decisions);
    }
}
