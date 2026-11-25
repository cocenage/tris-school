<?php

namespace App\Models;

class FeedbackSuggestion extends StaffTextQuestion
{
    protected const DECISION_MODEL = FeedbackSuggestionDecision::class;
    public const DECISION_STATUSES = ['reviewed'];
}
