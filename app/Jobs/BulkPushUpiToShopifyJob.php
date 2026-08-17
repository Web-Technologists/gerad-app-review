<?php

namespace App\Jobs;

use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductSyncService;
use App\Services\ShopifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkPushUpiToShopifyJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected array $updates;
    protected string $updatedBy;

    /**
     * Create a new job instance.
     */
    public function __construct(array $updates, string $updatedBy)
    {
        $this->updates = $updates;
        $this->updatedBy = $updatedBy;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ProductRepositoryInterface $productRepository,
        ProductSyncService $syncService
    ): void {
        Log::info("BulkPushUpiToShopifyJob: Starting batch update of " . count($this->updates) . " items.");

        // Group updates by shop
        $updatesByShop = [];
        foreach ($this->updates as $update) {
            $shopifyProductId = (int) $update['shopify_product_id'];
            $upiCode = $update['upi_code'];
            $upiStatus = $update['upi_status'] ?? 'Active';

            $product = $productRepository->findByShopifyId($shopifyProductId);
            if (!$product) {
                Log::warning("BulkPushUpiToShopifyJob: Product with Shopify ID {$shopifyProductId} not found. Skipping.");
                continue;
            }
            $updatesByShop[$product->shop_id][] = [
                'product' => $product,
                'upi_code' => $upiCode,
                'upi_status' => $upiStatus,
            ];
        }

        foreach ($updatesByShop as $shopId => $items) {
            $shop = \App\Models\Shop::find($shopId);
            if (!$shop) {
                continue;
            }

            // First, update all products locally to pending_push (or sync immediately if mock)
            DB::transaction(function() use ($items) {
                foreach ($items as $item) {
                    $item['product']->update([
                        'upi_code' => $item['upi_code'] ?: null,
                        'upi_status' => $item['upi_status'] ?: null,
                        'last_updated_by' => $this->updatedBy,
                        'last_updated_at' => now(),
                        'sync_status' => 'pending_push',
                    ]);
                }
            });

            if ($shop->access_token === 'mock_access_token_123456789') {
                // For mock store, update to synced immediately
                DB::transaction(function() use ($items) {
                    foreach ($items as $item) {
                        $item['product']->update([
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]);
                    }
                });
                continue;
            }

            // Prepare bulk metafields list chunked by 6 products (max 24 metafields)
            $client = new ShopifyClient($shop);
            $chunks = array_chunk($items, 6);

            foreach ($chunks as $chunk) {
                $metafields = [];
                foreach ($chunk as $item) {
                    $prod = $item['product'];
                    $metafields[] = [
                        'ownerId' => "gid://shopify/Product/{$prod->shopify_product_id}",
                        'namespace' => 'custom',
                        'key' => 'upi',
                        'value' => $item['upi_code'] ?? '',
                        'type' => 'single_line_text_field',
                    ];
                    $metafields[] = [
                        'ownerId' => "gid://shopify/Product/{$prod->shopify_product_id}",
                        'namespace' => 'custom',
                        'key' => 'upi_status',
                        'value' => $item['upi_status'] ?? '',
                        'type' => 'single_line_text_field',
                    ];
                    if ($prod->item_category !== null) {
                        $metafields[] = [
                            'ownerId' => "gid://shopify/Product/{$prod->shopify_product_id}",
                            'namespace' => 'custom',
                            'key' => 'item_category',
                            'value' => $prod->item_category,
                            'type' => 'single_line_text_field',
                        ];
                    }
                    if ($prod->primary_licensor !== null) {
                        $metafields[] = [
                            'ownerId' => "gid://shopify/Product/{$prod->shopify_product_id}",
                            'namespace' => 'custom',
                            'key' => 'primary_licensor',
                            'value' => $prod->primary_licensor,
                            'type' => 'single_line_text_field',
                        ];
                    }
                }

                // Push standard productType to Shopify for each product in chunk
                foreach ($chunk as $item) {
                    $prod = $item['product'];
                    try {
                        $client->updateProduct([
                            'id' => "gid://shopify/Product/{$prod->shopify_product_id}",
                            'productType' => $prod->product_type,
                        ]);
                    } catch (\Exception $e) {
                        Log::error("BulkPushUpiToShopifyJob: Failed standard update for product {$prod->id}: " . $e->getMessage());
                    }
                }

                $success = false;
                try {
                    $success = $client->setBulkMetafields($metafields);
                } catch (\Exception $e) {
                    Log::error("BulkPushUpiToShopifyJob: Failed bulk push for shop {$shop->shop_domain}: " . $e->getMessage());
                }

                $status = $success ? 'synced' : 'failed';
                $syncedAt = $success ? now() : null;

                DB::transaction(function() use ($chunk, $status, $syncedAt) {
                    foreach ($chunk as $item) {
                        $item['product']->update([
                            'sync_status' => $status,
                            'last_synced_at' => $syncedAt,
                        ]);
                    }
                });
            }
        }

        Log::info("BulkPushUpiToShopifyJob: Batch dispatching complete.");
    }
}
