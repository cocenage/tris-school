<?php

namespace App\Filament\Resources\DayOffRequests\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DayOffRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('admin_comment')
                    ->label('Комментарий администратора')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}