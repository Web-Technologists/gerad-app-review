<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\Product;
use App\Services\ShopifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInitialProductsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 45, 90];

    public Shop $shop;

    /**
     * Create a new job instance.
     */
    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("SyncInitialProductsJob: Starting initial product sync for {$this->shop->shop_domain}");

        $client = new ShopifyClient($this->shop);
        
        $query = <<<GQL
        query GetProducts(\$first: Int!, \$after: String) {
            products(first: \$first, after: \$after) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                edges {
                    node {
                        id
                        handle
                        title
                        vendor
                        productType
                        status
                        upi_code: metafield(namespace: "custom", key: "upi") {
                            id
                            value
                        }
                        upi_status: metafield(namespace: "custom", key: "upi_status") {
                            id
                            value
                        }
                        item_category: metafield(namespace: "custom", key: "item_category") {
                            id
                            value
                        }
                        primary_licensor: metafield(namespace: "custom", key: "primary_licensor") {
                            id
                            value
                        }
                        featuredImage {
                            url
                        }
                        variants(first: 250) {
                            edges {
                                node {
                                    id
                                    title
                                    price
                                    sku
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;

        $hasNextPage = true;
        $cursor = null;
        $syncedCount = 0;

        while ($hasNextPage) {
            try {
                $response = $client->graph($query, [
                    'first' => 50,
                    'after' => $cursor,
                ]);

                $productsData = $response['data']['products'] ?? null;
                if (!$productsData) {
                    break;
                }

                foreach ($productsData['edges'] as $edge) {
                    $node = $edge['node'];
                    
                    $shopifyId = (int) basename($node['id']);
                    
                    $upiCodeMeta = $node['upi_code'] ?? null;
                    $upiStatusMeta = $node['upi_status'] ?? null;
                    $itemCategoryMeta = $node['item_category'] ?? null;
                    $primaryLicensorMeta = $node['primary_licensor'] ?? null;

                    $upiCode = $upiCodeMeta['value'] ?? null;
                    $upiStatus = $upiStatusMeta['value'] ?? null;
                    $itemCategory = $itemCategoryMeta['value'] ?? null;
                    $primaryLicensor = $primaryLicensorMeta['value'] ?? null;

                    if (empty($primaryLicensor)) {
                        $storeDefault = $this->shop->primary_licensor ?? '';
                        $detected = \App\Services\LicensorDetectionService::getBestLicensorMatch($node['title'] ?? '');
                        if (!empty($storeDefault) && strtolower($storeDefault) !== 'various') {
                            $primaryLicensor = ($detected !== 'Various') ? $detected : $storeDefault;
                        } else {
                            $primaryLicensor = $detected;
                        }
                    }

                    $mainImageUrl = $node['featuredImage']['url'] ?? null;
                    
                    $metafieldId = $upiCodeMeta['id'] ?? null;
                    if ($metafieldId) {
                        $metafieldId = (int) basename($metafieldId);
                    }

                    $existingProduct = Product::where('shop_id', $this->shop->id)
                        ->where('shopify_product_id', $shopifyId)
                        ->first();

                    $updateData = [
                        'handle' => $node['handle'] ?? null,
                        'title' => $node['title'],
                        'vendor' => $node['vendor'] ?: 'Generic',
                        'product_type' => $node['productType'] ?: 'Default',
                        'status' => strtolower($node['status']),
                        'primary_licensor' => $primaryLicensor,
                        'metafield_id' => $metafieldId,
                        'main_image_url' => $mainImageUrl,
                    ];

                    if (!$existingProduct || in_array($existingProduct->sync_status, ['synced', 'pending_pull'])) {
                        // If product doesn't exist, or is in synced/pending_pull state, take Shopify's values
                        $updateData['upi_code'] = $upiCode;
                        $updateData['upi_status'] = $upiStatus;
                        $updateData['item_category'] = $itemCategory;
                        $updateData['last_updated_by'] = ($upiCode || $itemCategory) ? 'Shopify Sync' : null;
                        $updateData['last_updated_at'] = ($upiCode || $itemCategory) ? now() : null;
                        $updateData['sync_status'] = 'synced';
                        $updateData['last_synced_at'] = now();
                    } else {
                        // Product exists and is pending_push or failed, preserve local UPI fields
                        // If local UPI fields are completely empty, but Shopify has them, populate them
                        if (empty($existingProduct->upi_code) && !empty($upiCode)) {
                            $updateData['upi_code'] = $upiCode;
                            $updateData['upi_status'] = $upiStatus;
                            $updateData['item_category'] = $itemCategory;
                            $updateData['last_updated_by'] = 'Shopify Sync';
                            $updateData['last_updated_at'] = now();
                            $updateData['sync_status'] = 'synced';
                            $updateData['last_synced_at'] = now();
                        }
                    }

                    if ($existingProduct) {
                        $existingProduct->update($updateData);
                        $product = $existingProduct;
                    } else {
                        $product = Product::create(array_merge([
                            'shop_id' => $this->shop->id,
                            'shopify_product_id' => $shopifyId
                        ], $updateData));
                    }

                    // Sync variants
                    $variantsData = $node['variants']['edges'] ?? [];
                    foreach ($variantsData as $variantEdge) {
                        $variantNode = $variantEdge['node'];
                        $shopifyVariantId = (int) basename($variantNode['id']);
                        
                        \App\Models\ProductVariant::updateOrCreate(
                            [
                                'product_id' => $product->id,
                                'shopify_variant_id' => $shopifyVariantId
                            ],
                            [
                                'title' => $variantNode['title'],
                                'sku' => $variantNode['sku'] ?? null,
                                'price' => $variantNode['price'] ?? 0.00,
                            ]
                        );
                    }

                    $syncedCount++;
                }

                $pageInfo = $productsData['pageInfo'];
                $hasNextPage = $pageInfo['hasNextPage'];
                $cursor = $pageInfo['endCursor'];

            } catch (\Exception $e) {
                Log::error("SyncInitialProductsJob: Error syncing catalog for {$this->shop->shop_domain}: " . $e->getMessage());
                throw $e; // Trigger job retry
            }
        }

        Log::info("SyncInitialProductsJob: Successfully synced {$syncedCount} products for {$this->shop->shop_domain}");
    }
}
