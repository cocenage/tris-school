<?php

namespace App\Services\Forms;

class FeedbackSuggestionDecisionService extends StaffTextQuestionDecisionService
{
    protected const KIND = 'feedback';
    protected const PANEL = 'education';
    protected const MODEL = \App\Models\FeedbackSuggestion::class;
    public const ACTIONS = ['reply' => 'reviewed'];
}
