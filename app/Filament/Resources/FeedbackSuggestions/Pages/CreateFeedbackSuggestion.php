<?php

namespace App\Filament\Resources\FeedbackSuggestions\Pages;

use App\Filament\Resources\FeedbackSuggestions\FeedbackSuggestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeedbackSuggestion extends CreateRecord
{
    protected static string $resource = FeedbackSuggestionResource::class;
}
