<?php

namespace App\Services\Forms;

class ScheduleQuestionDecisionService extends StaffTextQuestionDecisionService
{
    protected const KIND = 'schedule';
    protected const PANEL = 'education';
    protected const MODEL = \App\Models\ScheduleQuestion::class;
}
