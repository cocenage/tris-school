<?php

namespace App\Filament\Resources\KnowledgeEntities\Pages;

use App\Filament\Resources\KnowledgeEntities\KnowledgeEntityResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeEntities extends ListRecords
{
    protected static string $resource = KnowledgeEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('roadmap')->label('Открыть Roadmap')->url(route('knowledge.roadmap')), CreateAction::make()];
    }
}
