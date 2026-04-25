<?php

namespace App\Filament\Resources\ControlResponses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ControlResponseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('control_id')
                    ->required()
                    ->numeric(),
                TextInput::make('supervisor_id')
                    ->numeric(),
                TextInput::make('cleaner_id')
                    ->numeric(),
                TextInput::make('apartment_id')
                    ->numeric(),
                Toggle::make('is_assigned')
                    ->required(),
                TextInput::make('previous_cleaner'),
                DatePicker::make('cleaning_date'),
                DatePicker::make('inspection_date'),
                Textarea::make('comment')
                    ->columnSpanFull(),
                Textarea::make('responses')
                    ->columnSpanFull(),
                Textarea::make('schema_snapshot')
                    ->columnSpanFull(),
                TextInput::make('total_points')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('max_points')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('score_percent')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('has_critical_failure')
                    ->required(),
                TextInput::make('result_zone'),
                TextInput::make('status')
                    ->required()
                    ->default('submitted'),
                DateTimePicker::make('sent_at'),
            ]);
    }
}
