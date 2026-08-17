<?php

namespace App\Filament\Resources\SyncJobs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SyncJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shop_id')
                    ->relationship('shop', 'id'),
                TextInput::make('type')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('file_path'),
                TextInput::make('total_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('processed_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('failed_rows')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('error_log'),
            ]);
    }
}
