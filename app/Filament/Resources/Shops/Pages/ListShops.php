<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Filament\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Models\SyncJob;
use App\Jobs\StoreProvisioningJob;
use App\Jobs\BulkOnboardStoresJob;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => auth()->user()?->hasPermission('manage_stores')),
            Action::make('simulate_oauth')
                ->label('Simulate OAuth')
                ->icon('heroicon-o-cpu-chip')
                ->color('warning')
                ->visible(fn () => auth()->user()?->hasPermission('manage_stores'))
                ->form([
                    TextInput::make('shop_domain')
                        ->label('Shop Domain')
                        ->placeholder('store-name.myshopify.com')
                        ->required()
                        ->rules(['regex:/^[a-zA-Z0-9.-]+\.myshopify\.com$/']),
                ])
                ->action(function (array $data) {
                    $shopDomain = strtolower(trim($data['shop_domain']));
                    
                    $shop = Shop::updateOrCreate(
                        ['shop_domain' => $shopDomain],
                        [
                            'custom_domain' => "www." . str_replace('.myshopify.com', '.com', $shopDomain),
                            'access_token' => 'mock_access_token_123456789',
                            'scopes' => ['read_products', 'write_products'],
                            'status' => 'active',
                        ]
                    );

                    StoreProvisioningJob::dispatch($shop, true);

                    Notification::make()
                        ->title('Store Connected')
                        ->body("Simulated Store '{$shopDomain}' successfully connected and products are being synced.")
                        ->success()
                        ->send();
                }),
            Action::make('bulk_onboard')
                ->label('Onboard via CSV')
                ->icon('heroicon-o-document-arrow-up')
                ->color('info')
                ->visible(fn () => auth()->user()?->hasPermission('manage_stores'))
                ->form([
                    FileUpload::make('csv_file')
                        ->label('Store CSV File')
                        ->directory('store_imports')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $filePath = $data['csv_file'];

                    $syncJob = SyncJob::create([
                        'type' => 'store_import',
                        'status' => 'pending',
                        'file_path' => $filePath,
                    ]);

                    BulkOnboardStoresJob::dispatch($syncJob);

                    Notification::make()
                        ->title('Bulk Import Started')
                        ->body("Bulk onboarding for stores has been scheduled. Job ID: {$syncJob->id}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
