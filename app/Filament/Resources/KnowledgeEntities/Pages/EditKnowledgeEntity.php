<?php

namespace App\Filament\Resources\KnowledgeEntities\Pages;

use App\Filament\Resources\KnowledgeEntities\KnowledgeEntityResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeEntity extends EditRecord
{
    protected static string $resource = KnowledgeEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('article')->label('Открыть статью')->url(fn (): string => route('knowledge.show', $this->record)), DeleteAction::make()];
    }
}
