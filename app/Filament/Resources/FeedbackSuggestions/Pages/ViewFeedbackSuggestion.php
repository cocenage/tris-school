<?php

namespace App\Filament\Resources\FeedbackSuggestions\Pages;

use App\Filament\Resources\FeedbackSuggestions\FeedbackSuggestionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFeedbackSuggestion extends ViewRecord
{
    protected static string $resource = FeedbackSuggestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
