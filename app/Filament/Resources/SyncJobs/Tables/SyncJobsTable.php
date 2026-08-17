<?php

namespace App\Filament\Resources\SyncJobs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Storage;
use Filament\Tables\Table;

class SyncJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('shop.shop_domain')
                    ->label('Shop Domain')
                    ->searchable()
                    ->default('System Bulk'),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'csv_import' => 'info',
                        'csv_export' => 'success',
                        'licensing_export' => 'success',
                        'store_import' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_rows')
                    ->label('Total Rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('processed_rows')
                    ->label('Processed')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failed_rows')
                    ->label('Failed')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Started At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn ($record) => in_array($record->type, ['csv_export', 'licensing_export']) && $record->status === 'completed' && $record->file_path && Storage::exists($record->file_path))
                    ->action(fn ($record) => Storage::download($record->file_path, basename($record->file_path))),
                Action::make('view_errors')
                    ->label('View Errors')
                    ->icon('heroicon-o-exclamation-circle')
                    ->color('danger')
                    ->visible(fn ($record) => !empty($record->error_log))
                    ->form([
                        Textarea::make('error_log')
                            ->label('Error Details')
                            ->rows(10)
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn ($record) => is_array($record->error_log) ? json_encode($record->error_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $record->error_log)
                    ]),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
