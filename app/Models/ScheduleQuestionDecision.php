<?php

namespace App\Models;

class ScheduleQuestionDecision extends StaffTextQuestionDecision
{
    protected const QUESTION_MODEL = ScheduleQuestion::class;
    protected const QUESTION_KEY = 'schedule_question_id';
}
