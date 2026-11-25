<?php

namespace App\Models;

class FeedbackSuggestionDecision extends StaffTextQuestionDecision
{
    protected const QUESTION_MODEL = FeedbackSuggestion::class;
    protected const QUESTION_KEY = 'feedback_suggestion_id';
}
