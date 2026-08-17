<?php

namespace App\Filament\Resources\SyncJobs;

use App\Filament\Resources\SyncJobs\Pages\CreateSyncJob;
use App\Filament\Resources\SyncJobs\Pages\EditSyncJob;
use App\Filament\Resources\SyncJobs\Pages\ListSyncJobs;
use App\Filament\Resources\SyncJobs\Schemas\SyncJobForm;
use App\Filament\Resources\SyncJobs\Tables\SyncJobsTable;
use App\Models\SyncJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SyncJobResource extends Resource
{
    protected static ?string $model = SyncJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SyncJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SyncJobsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncJobs::route('/'),
            'create' => CreateSyncJob::route('/create'),
            'edit' => EditSyncJob::route('/{record}/edit'),
        ];
    }
}
