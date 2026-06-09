<?php

namespace App\Filament\Resources\ControlResponses\Pages;

use App\Filament\Resources\ControlResponses\ControlResponseResource;
use Filament\Resources\Pages\ViewRecord;

class ViewControlResponse extends ViewRecord
{
    protected static string $resource = ControlResponseResource::class;

    public function getTitle(): string
    {
        return 'Просмотр контроля';
    }

    public function getBreadcrumb(): string
    {
        return 'Просмотр';
    }

    public function getSubheading(): ?string
    {
        $cleaner = $this->record->cleaner?->name ?? '—';
        $apartment = $this->record->apartment?->name ?? '—';
        $date = $this->record->inspection_date?->format('d.m.Y') ?? '—';

        return "{$cleaner} · {$apartment} · {$date}";
    }
}