<?php

namespace App\Models;

class ScheduleQuestion extends StaffTextQuestion
{
    protected const DECISION_MODEL = ScheduleQuestionDecision::class;
}
