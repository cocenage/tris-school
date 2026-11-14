<?php

namespace App\Filament\Resources\ShiftSituations\Pages;

use App\Filament\Resources\ShiftSituations\ShiftSituationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShiftSituations extends ManageRecords
{
    protected static string $resource = ShiftSituationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data): array => array_replace($data, ['recorded_by' => auth()->id()]))];
    }
}
