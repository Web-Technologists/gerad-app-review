<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $shopIds = $data['target_shops'] ?? [];
        unset($data['target_shops']);

        $lastProduct = null;

        foreach ($shopIds as $shopId) {
            $shop = \App\Models\Shop::find($shopId);
            if (!$shop) continue;

            $shopifyProductId = null;
            $syncStatus = 'synced';

            if ($shop->access_token === 'mock_access_token_123456789') {
                // Mock store generates a dummy Shopify Product ID
                $shopifyProductId = rand(1000000000, 9999999999);
            } else {
                // Real store pushes product to Shopify catalogue
                $client = new \App\Services\ShopifyClient($shop);
                $shopifyProductId = $client->createProduct([
                    'title' => $data['title'],
                    'vendor' => $data['vendor'] ?? '',
                    'product_type' => $data['product_type'] ?? '',
                    'handle' => $data['handle'] ?? null,
                    'status' => $data['status'] ?? 'active',
                ]);
                
                if ($shopifyProductId && (!empty($data['upi_code']) || !empty($data['item_category']))) {
                    // Set upi metafield immediately
                    $client->setProductUpi($shopifyProductId, $data['upi_code'] ?? null, $data['upi_status'] ?? 'Active', $data['item_category'] ?? null);
                } else if (!$shopifyProductId) {
                    $syncStatus = 'failed';
                }
            }

            $product = \App\Models\Product::create([
                'shop_id' => $shop->id,
                'shopify_product_id' => $shopifyProductId ?? null,
                'title' => $data['title'],
                'vendor' => $data['vendor'] ?? '',
                'product_type' => $data['product_type'] ?? '',
                'handle' => $data['handle'] ?? null,
                'status' => $data['status'] ?? 'active',
                'upi_code' => $data['upi_code'] ?? null,
                'upi_status' => $data['upi_status'] ?? null,
                'item_category' => $data['item_category'] ?? null,
                'last_updated_by' => auth()->user()?->name ?? 'Filament Admin',
                'last_updated_at' => now(),
                'sync_status' => $syncStatus,
                'last_synced_at' => now(),
            ]);

            $lastProduct = $product;
        }

        // Return the last created product to satisfy Filament's return expectations
        return $lastProduct ?: new \App\Models\Product();
    }
}
