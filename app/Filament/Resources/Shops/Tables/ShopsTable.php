<?php

namespace App\Filament\Resources\Shops\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Models\Shop;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop_domain')
                    ->searchable(),
                TextColumn::make('custom_domain')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('sync_products')
                    ->label('Sync Products')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->action(function (Shop $record) {
                        $isMock = $record->access_token === 'mock_access_token_123456789' || ($record->access_token && str_starts_with($record->access_token, 'mock'));
                        \App\Jobs\StoreProvisioningJob::dispatch($record, $isMock);
                        
                        Notification::make()
                            ->title('Sync Started')
                            ->body("Product synchronization has been scheduled for {$record->shop_domain}.")
                            ->success()
                            ->send();
                    }),
                Action::make('sync_metafields')
                    ->label('Sync Metafields')
                    ->icon('heroicon-o-square-3-stack-3d')
                    ->color('info')
                    ->action(function (Shop $record) {
                        $isMock = $record->access_token === 'mock_access_token_123456789' || ($record->access_token && str_starts_with($record->access_token, 'mock'));
                        if ($isMock) {
                            Notification::make()
                                ->title('Sync Started')
                                ->body("Metafields sync simulated for mock store {$record->shop_domain}.")
                                ->success()
                                ->send();
                        } else {
                            \Illuminate\Support\Facades\Bus::chain([
                                new \App\Jobs\CreateMetafieldDefinitionsJob($record),
                                new \App\Jobs\SyncInitialProductsJob($record),
                            ])->dispatch();

                            Notification::make()
                                ->title('Sync Started')
                                ->body("Metafield definition and value sync has been scheduled for {$record->shop_domain}.")
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
