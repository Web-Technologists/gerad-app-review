<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Models\SyncJob;
use App\Models\Shop;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\BulkAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop.shop_domain')
                    ->label('Shop Domain')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('shopify_product_id')
                    ->label('Product ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vendor')
                    ->searchable()
                    ->sortable(),
                TextInputColumn::make('product_type')
                    ->label('Product Type')
                    ->searchable()
                    ->sortable()
                    ->disabled(fn () => !auth()->user()?->hasPermission('edit_upi'))
                    ->updateStateUsing(function (Product $record, $state) {
                        $syncService = app(\App\Services\ProductSyncService::class);
                        $syncService->triggerLocalUpiUpdate(
                            $record,
                            $record->upi_code,
                            $record->upi_status,
                            auth()->user()?->name ?? 'Filament Admin',
                            $state
                        );
                        return $state;
                    }),
                TextInputColumn::make('upi_code')
                    ->label('UPI Code')
                    ->searchable()
                    ->rules(['nullable', 'min:4', 'max:15', 'regex:/^[a-zA-Z0-9]+$/'])
                    ->disabled(fn () => !auth()->user()?->hasPermission('edit_upi'))
                    ->updateStateUsing(function (Product $record, $state) {
                        $syncService = app(\App\Services\ProductSyncService::class);
                        $syncService->triggerLocalUpiUpdate(
                            $record,
                            $state,
                            $record->upi_status,
                            auth()->user()?->name ?? 'Filament Admin',
                            $record->product_type
                        );
                        return $state;
                    }),
                SelectColumn::make('upi_status')
                    ->label('UPI Status')
                    ->disabled(fn () => !auth()->user()?->hasPermission('edit_upi'))
                    ->options([
                        'Active' => 'Active',
                        'Pending Review' => 'Pending Review',
                        'Deprecated' => 'Deprecated',
                    ])
                    ->updateStateUsing(function (Product $record, $state) {
                        $syncService = app(\App\Services\ProductSyncService::class);
                        $syncService->triggerLocalUpiUpdate(
                            $record,
                            $record->upi_code,
                            $state,
                            auth()->user()?->name ?? 'Filament Admin',
                            $record->product_type
                        );
                        return $state;
                    }),
                TextColumn::make('item_category')
                    ->label('Item Category')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sync_status')
                    ->label('Sync Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'synced' => 'success',
                        'pending_push' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_updated_by')
                    ->label('Updated By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label('Shop')
                    ->relationship('shop', 'shop_domain')
                    ->preload(),
                SelectFilter::make('vendor')
                    ->options(fn () => Product::distinct()->orderBy('vendor')->pluck('vendor', 'vendor')->filter()->toArray()),
                SelectFilter::make('product_type')
                    ->options(fn () => Product::distinct()->orderBy('product_type')->pluck('product_type', 'product_type')->filter()->toArray()),
                SelectFilter::make('sync_status')
                    ->options([
                        'synced' => 'Synced',
                        'pending_push' => 'Pending Push',
                        'failed' => 'Failed',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->hasPermission('edit_upi')),
                Action::make('sync_to_other_stores')
                    ->label('Sync to other Stores')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->hasPermission('edit_upi'))
                    ->form(fn (Product $record) => [
                        Select::make('target_shops')
                            ->label('Select target stores')
                            ->multiple()
                            ->options(
                                Shop::where('id', '!=', $record->shop_id)
                                    ->pluck('shop_domain', 'id')
                                    ->toArray()
                            )
                            ->required(),
                    ])
                    ->action(function (Product $record, array $data) {
                        $shopIds = $data['target_shops'] ?? [];
                        $syncedCount = 0;
                        
                        // Fetch full product details (description, options, variants) from the source store
                        $productDetails = null;
                        $sourceShop = $record->shop;
                        if ($sourceShop->access_token !== 'mock_access_token_123456789' && $record->shopify_product_id > 0) {
                            $sourceClient = new \App\Services\ShopifyClient($sourceShop);
                            $productDetails = $sourceClient->getProductDetails($record->shopify_product_id);

                            if ($productDetails) {
                                // Update source product's cached details in database with the freshly fetched Shopify values
                                $record->update([
                                    'upi_code' => $productDetails['upi_code'] ?? $record->upi_code,
                                    'upi_status' => $productDetails['upi_status'] ?? $record->upi_status,
                                    'item_category' => $productDetails['item_category'] ?? $record->item_category,
                                ]);
                            }
                        }

                        if (!$productDetails) {
                            // Fallback to local DB values (e.g. for mock stores or if Shopify fetch fails)
                            $localVariants = \App\Models\ProductVariant::where('product_id', $record->id)->get();
                            $variants = [];
                            foreach ($localVariants as $lv) {
                                $variants[] = [
                                    'title' => $lv->title,
                                    'price' => $lv->price,
                                    'sku' => $lv->sku,
                                ];
                            }
                            $productDetails = [
                                'title' => $record->title,
                                'vendor' => $record->vendor,
                                'product_type' => $record->product_type,
                                'handle' => $record->handle,
                                'status' => $record->status,
                                'descriptionHtml' => '',
                                'options' => [],
                                'upi_code' => $record->upi_code,
                                'upi_status' => $record->upi_status,
                                'item_category' => $record->item_category,
                                'all_metafields' => [
                                    [
                                        'namespace' => 'custom',
                                        'key' => 'upi',
                                        'value' => $record->upi_code,
                                        'type' => 'single_line_text_field',
                                    ],
                                    [
                                        'namespace' => 'custom',
                                        'key' => 'upi_status',
                                        'value' => $record->upi_status,
                                        'type' => 'single_line_text_field',
                                    ],
                                    [
                                        'namespace' => 'custom',
                                        'key' => 'item_category',
                                        'value' => $record->item_category,
                                        'type' => 'single_line_text_field',
                                    ]
                                ],
                                'variants' => $variants
                            ];
                        }

                        foreach ($shopIds as $shopId) {
                            $shop = Shop::find($shopId);
                            if (!$shop) continue;
                            
                            // Check if product with same handle/title already exists in this shop to avoid duplication
                            $existingProduct = Product::where('shop_id', $shop->id)
                                ->where(function ($q) use ($record) {
                                    $q->where('handle', $record->handle)
                                      ->orWhere('title', $record->title);
                                })
                                ->first();

                             $upiCode = $existingProduct ? $existingProduct->upi_code : null;
                             $upiStatus = $existingProduct ? $existingProduct->upi_status : null;

                            $shopifyProductId = null;
                            $createdVariants = [];
                            $syncStatus = 'synced';
                            
                            if ($shop->access_token === 'mock_access_token_123456789') {
                                $shopifyProductId = rand(1000000000, 9999999999);
                                foreach ($productDetails['variants'] as $v) {
                                    $createdVariants[] = [
                                        'shopify_variant_id' => rand(1000000000, 9999999999),
                                        'title' => $v['title'],
                                        'sku' => $v['sku'] ?? null,
                                        'price' => $v['price'] ?? 0.00,
                                    ];
                                }
                            } else {
                                $destClient = new \App\Services\ShopifyClient($shop);
                                
                                // Look up if the product already exists on target store on Shopify by handle
                                $existingShopifyProduct = $destClient->getProductByHandle($record->handle);
                                if ($existingShopifyProduct) {
                                    $existingShopifyId = (int) basename($existingShopifyProduct['id']);
                                    $destClient->deleteProduct($existingShopifyId);
                                } elseif ($existingProduct && $existingProduct->shopify_product_id) {
                                    // Or delete it using local DB's shopify_product_id if handle wasn't found
                                    $destClient->deleteProduct($existingProduct->shopify_product_id);
                                }

                                $syncResult = $destClient->createProductWithVariants($productDetails);
                                if ($syncResult) {
                                    $shopifyProductId = $syncResult['id'];
                                    $createdVariants = $syncResult['variants'];
                                                                        if (!empty($productDetails['all_metafields'])) {
                                         $metafieldsToSet = array_filter($productDetails['all_metafields'], fn($m) => !in_array($m['key'], ['upi', 'upi_status']));
                                         if (!empty($metafieldsToSet)) {
                                             $destClient->setProductMetafields($shopifyProductId, array_values($metafieldsToSet));
                                         }
                                     }
                                } else {
                                    $syncStatus = 'failed';
                                }
                            }

                            
                            $itemCategory = $productDetails['item_category'] ?? $record->item_category;

                            $productDataToSave = [
                                'shopify_product_id' => $shopifyProductId ?? null,
                                'title' => $record->title,
                                'vendor' => $record->vendor,
                                'product_type' => $record->product_type,
                                'handle' => $record->handle,
                                'status' => $record->status,
                                'upi_code' => $upiCode,
                                'upi_status' => $upiStatus,
                                'item_category' => $itemCategory,
                                'last_updated_by' => auth()->user()?->name ?? 'Filament Admin',
                                'last_updated_at' => now(),
                                'sync_status' => $syncStatus,
                                'last_synced_at' => now(),
                            ];

                            if ($existingProduct) {
                                $existingProduct->update($productDataToSave);
                                $newProduct = $existingProduct;
                                $newProduct->variants()->delete();
                            } else {
                                $newProduct = Product::create(array_merge(['shop_id' => $shop->id], $productDataToSave));
                            }

                            foreach ($createdVariants as $cv) {
                                \App\Models\ProductVariant::create([
                                    'product_id' => $newProduct->id,
                                    'shopify_variant_id' => $cv['shopify_variant_id'],
                                    'title' => $cv['title'],
                                    'sku' => $cv['sku'] ?? null,
                                    'price' => $cv['price'] ?? 0.00,
                                ]);
                            }
                            
                            $syncedCount++;
                        }
                        
                        Notification::make()
                            ->title('Sync to other stores')
                            ->body("Product successfully synced to {$syncedCount} stores.")
                            ->success()
                            ->send();
                    }),
                Action::make('sync_metafields')
                    ->label('Sync Metafields')
                    ->icon('heroicon-o-square-3-stack-3d')
                    ->color('info')
                    ->visible(fn () => auth()->user()?->hasPermission('edit_upi'))
                    ->form(fn (Product $record) => [
                        Select::make('target_shops')
                            ->label('Select target stores')
                            ->multiple()
                            ->options(
                                Shop::where('id', '!=', $record->shop_id)
                                    ->pluck('shop_domain', 'id')
                                    ->toArray()
                            )
                            ->required(),
                    ])
                    ->action(function (Product $record, array $data) {
                        $shopIds = $data['target_shops'] ?? [];
                        $syncedCount = 0;

                        // Fetch full product details (to get all custom metafields) from the source store
                        $productDetails = null;
                        $sourceShop = $record->shop;
                        if ($sourceShop->access_token !== 'mock_access_token_123456789' && $record->shopify_product_id > 0) {
                            $sourceClient = new \App\Services\ShopifyClient($sourceShop);
                            $productDetails = $sourceClient->getProductDetails($record->shopify_product_id);

                            if ($productDetails) {
                                // Update source product's cached details in database with the freshly fetched Shopify values
                                $record->update([
                                    'upi_code' => $productDetails['upi_code'] ?? $record->upi_code,
                                    'upi_status' => $productDetails['upi_status'] ?? $record->upi_status,
                                    'item_category' => $productDetails['item_category'] ?? $record->item_category,
                                ]);
                            }
                        }

                        if (!$productDetails) {
                            // Fallback to local DB values
                            $productDetails = [
                                'upi_code' => $record->upi_code,
                                'upi_status' => $record->upi_status,
                                'item_category' => $record->item_category,
                                'all_metafields' => [
                                    [
                                        'namespace' => 'custom',
                                        'key' => 'upi',
                                        'value' => $record->upi_code,
                                        'type' => 'single_line_text_field',
                                    ],
                                    [
                                        'namespace' => 'custom',
                                        'key' => 'upi_status',
                                        'value' => $record->upi_status,
                                        'type' => 'single_line_text_field',
                                    ],
                                    [
                                        'namespace' => 'custom',
                                        'key' => 'item_category',
                                        'value' => $record->item_category,
                                        'type' => 'single_line_text_field',
                                    ]
                                ]
                            ];
                        }

                        foreach ($shopIds as $shopId) {
                            $shop = Shop::find($shopId);
                            if (!$shop) continue;

                            // Find the product with the same handle in the target store
                            $existingProduct = Product::where('shop_id', $shop->id)
                                ->where('handle', $record->handle)
                                ->first();

                            if (!$existingProduct) {
                                continue;
                            }

                            if ($shop->access_token !== 'mock_access_token_123456789' && $existingProduct->shopify_product_id) {
                                $destClient = new \App\Services\ShopifyClient($shop);
                                 if (!empty($productDetails['all_metafields'])) {
                                     $metafieldsToSet = array_filter($productDetails['all_metafields'], fn($m) => !in_array($m['key'], ['upi', 'upi_status']));
                                     if (!empty($metafieldsToSet)) {
                                         $destClient->setProductMetafields($existingProduct->shopify_product_id, array_values($metafieldsToSet));
                                     }
                                 }
                            }

                            $existingProduct->update([
                                 'upi_code' => $existingProduct->upi_code,
                                 'upi_status' => $existingProduct->upi_status,
                                 'item_category' => $productDetails['item_category'] ?? $existingProduct->item_category,
                                'last_updated_by' => auth()->user()?->name ?? 'Filament Admin',
                                'last_updated_at' => now(),
                                'sync_status' => 'synced',
                                'last_synced_at' => now(),
                            ]);

                            $syncedCount++;
                        }

                        Notification::make()
                            ->title('Sync Metafields')
                            ->body("Metafields successfully synced to {$syncedCount} stores.")
                            ->success()
                            ->send();
                    }),
            ])
            ->headerActions([
                Action::make('csv_import')
                    ->label('CSV Import')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->hasPermission('import_export'))
                    ->form([
                        FileUpload::make('csv_file')
                            ->label('Product CSV File')
                            ->directory('imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                            ->required(),
                        Select::make('shop_id')
                            ->label('Shop Context (Optional)')
                            ->options(Shop::pluck('shop_domain', 'id')->toArray())
                            ->placeholder('All Stores'),
                    ])
                    ->action(function (array $data) {
                        $filePath = $data['csv_file'];

                        $syncJob = SyncJob::create([
                            'shop_id' => $data['shop_id'] ?? null,
                            'type' => 'csv_import',
                            'status' => 'pending',
                            'file_path' => $filePath,
                        ]);

                        \App\Jobs\BulkImportCsvJob::dispatch($syncJob);

                        Notification::make()
                            ->title('CSV Import Started')
                            ->body("Bulk UPI code import has been scheduled. Job ID: {$syncJob->id}")
                            ->success()
                            ->send();
                    }),
                Action::make('csv_export')
                    ->label('CSV Export')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->visible(fn () => auth()->user()?->hasPermission('import_export'))
                    ->action(function (Table $table) {
                        $livewire = $table->getLivewire();
                        
                        $search = $livewire->tableSearch ?? null;
                        $filterData = $livewire->tableFilters ?? [];
                        
                        $shopId = $filterData['shop_id']['value'] ?? null;
                        $vendor = $filterData['vendor']['value'] ?? null;
                        $productType = $filterData['product_type']['value'] ?? null;
                        $syncStatus = $filterData['sync_status']['value'] ?? null;
                        
                        $syncJob = SyncJob::create([
                            'shop_id' => $shopId ?: null,
                            'type' => 'csv_export',
                            'status' => 'pending',
                        ]);
                        
                        $jobFilters = [
                            'shop_id' => $shopId,
                            'vendor' => $vendor,
                            'product_type' => $productType,
                            'status' => $syncStatus,
                            'search' => $search,
                        ];
                        
                        \App\Jobs\BulkExportCsvJob::dispatch($syncJob, $jobFilters);
                        
                        Notification::make()
                            ->title('CSV Export Started')
                            ->body("Bulk UPI code export has been scheduled. Job ID: {$syncJob->id}")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_set_upi')
                        ->label('Set UPI / Category')
                        ->icon('heroicon-o-pencil')
                        ->visible(fn () => auth()->user()?->hasPermission('edit_upi'))
                        ->form([
                            TextInput::make('upi_code')
                                ->label('UPI Code')
                                ->placeholder('e.g. UPI9999')
                                ->rules(['nullable', 'min:4', 'max:15', 'regex:/^[a-zA-Z0-9]+$/']),
                            Select::make('upi_status')
                                ->label('UPI Status')
                                ->options([
                                    'Active' => 'Active',
                                    'Pending Review' => 'Pending Review',
                                    'Deprecated' => 'Deprecated',
                                ]),
                            TextInput::make('item_category')
                                ->label('Item Category')
                                ->placeholder('e.g. Shirts'),
                        ])
                        ->action(function (\Illuminate\Support\Collection $records, array $data) {
                            $syncService = app(\App\Services\ProductSyncService::class);
                            foreach ($records as $record) {
                                $syncService->triggerLocalUpiUpdate(
                                    $record,
                                    $data['upi_code'] ?: $record->upi_code,
                                    $data['upi_status'] ?: $record->upi_status,
                                    $data['item_category'] ?: $record->item_category,
                                    auth()->user()?->name ?? 'Filament Admin'
                                );
                            }
                            
                            Notification::make()
                                ->title('Bulk UPI Update')
                                ->body('Selected products have been updated and are syncing with Shopify.')
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->hasPermission('edit_upi')),
                ]),
            ]);
    }
}
