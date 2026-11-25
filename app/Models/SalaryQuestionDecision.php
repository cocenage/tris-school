<?php

namespace App\Models;

class SalaryQuestionDecision extends StaffTextQuestionDecision
{
    protected const QUESTION_MODEL = SalaryQuestion::class;
    protected const QUESTION_KEY = 'salary_question_id';
}
