<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToSite')
                ->label('На сайт')
                ->icon('heroicon-m-arrow-left')
                ->url(url('/'))
                ->color('gray')
                ->outlined(),
            CreateAction::make(),
        ];
    }
}
