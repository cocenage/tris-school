<?php

namespace App\Services\Forms;

class SalaryQuestionDecisionService extends StaffTextQuestionDecisionService
{
    protected const KIND = 'salary';
    protected const PANEL = 'finance';
    protected const MODEL = \App\Models\SalaryQuestion::class;
}
