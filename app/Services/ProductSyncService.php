<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Jobs\PushUpiToShopifyJob;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    protected ProductRepositoryInterface $productRepository;

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * Sync Shopify product webhook changes into the local database.
     */
    public function syncFromWebhook(Shop $shop, array $payload, string $action): ?Product
    {
        $shopifyProductId = (int) $payload['id'];

        if ($action === 'delete') {
            $this->productRepository->deleteByShopifyId($shop->id, $shopifyProductId);
            Log::info("ProductSyncService: Deleted product {$shopifyProductId} from local database.");
            return null;
        }

        $title = $payload['title'] ?? 'Untitled';
        $handle = $payload['handle'] ?? null;
        $vendor = $payload['vendor'] ?? 'Generic';
        $productType = $payload['product_type'] ?? 'Default';
        $status = strtolower($payload['status'] ?? 'active');
        $mainImageUrl = $payload['image']['src'] ?? ($payload['images'][0]['src'] ?? null);

        // Resolve UPI and Metafield details from local DB
        $localProduct = $this->productRepository->findByShopifyId($shopifyProductId);
        $upiCode = $localProduct ? $localProduct->upi_code : null;
        $upiStatus = $localProduct ? $localProduct->upi_status : null;
        $itemCategory = $localProduct ? $localProduct->item_category : null;
        $primaryLicensor = $localProduct ? $localProduct->primary_licensor : null;
        $metafieldId = $localProduct ? $localProduct->metafield_id : null;
        $lastUpdatedBy = $localProduct ? $localProduct->last_updated_by : null;
        $lastUpdatedAt = $localProduct ? $localProduct->last_updated_at : null;

        $preserveLocalUpi = $localProduct && in_array($localProduct->sync_status, ['pending_push', 'failed']);

        // Fetch metafields from Shopify GraphQL unless we are in mock demo mode
        if ($shop->access_token !== 'mock_access_token_123456789') {
            try {
                $client = new ShopifyClient($shop);
                $query = <<<GQL
                query GetProductMetafields(\$id: ID!) {
                    product(id: \$id) {
                        upi: metafield(namespace: "custom", key: "upi") {
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
                    }
                }
                GQL;

                $response = $client->graph($query, [
                    'id' => "gid://shopify/Product/{$shopifyProductId}"
                ]);

                $upiCodeMetafield = $response['data']['product']['upi'] ?? null;
                $upiStatusMetafield = $response['data']['product']['upi_status'] ?? null;
                $itemCategoryMetafield = $response['data']['product']['item_category'] ?? null;
                $primaryLicensorMetafield = $response['data']['product']['primary_licensor'] ?? null;

                if (!$preserveLocalUpi) {
                    if ($upiCodeMetafield) {
                        $upiCode = $upiCodeMetafield['value'];
                        $metafieldId = (int) basename($upiCodeMetafield['id']);
                    } else {
                        $upiCode = null;
                    }

                    if ($upiStatusMetafield) {
                        $upiStatus = $upiStatusMetafield['value'];
                    } else {
                        $upiStatus = null;
                    }

                    if ($itemCategoryMetafield) {
                        $itemCategory = $itemCategoryMetafield['value'];
                    } else {
                        $itemCategory = null;
                    }

                    if ($primaryLicensorMetafield) {
                        $primaryLicensor = $primaryLicensorMetafield['value'];
                    } else {
                        $primaryLicensor = null;
                    }
                } else {
                    // Even if we preserve local UPI, if local fields are empty/null and Shopify has them, import them
                    if (empty($upiCode) && $upiCodeMetafield) {
                        $upiCode = $upiCodeMetafield['value'];
                        $metafieldId = (int) basename($upiCodeMetafield['id']);
                        $preserveLocalUpi = false;
                    }
                    if (empty($upiStatus) && $upiStatusMetafield) {
                        $upiStatus = $upiStatusMetafield['value'];
                    }
                    if (empty($itemCategory) && $itemCategoryMetafield) {
                        $itemCategory = $itemCategoryMetafield['value'];
                    }
                    if (empty($primaryLicensor) && $primaryLicensorMetafield) {
                        $primaryLicensor = $primaryLicensorMetafield['value'];
                    }
                }

                // If metafield values changed on Shopify, we mark updated_by as Shopify Webhook
                if (!$localProduct || $localProduct->upi_code !== $upiCode || $localProduct->upi_status !== $upiStatus || $localProduct->item_category !== $itemCategory || $localProduct->primary_licensor !== $primaryLicensor) {
                    $lastUpdatedBy = 'Shopify Webhook';
                    $lastUpdatedAt = now();
                }

            } catch (\Exception $e) {
                Log::error("ProductSyncService: Failed to fetch metafields from Shopify API: " . $e->getMessage());
            }
        } else {
            // For mock/simulator, pull direct values from payload if present
            $hasChanges = false;
            
            if (isset($payload['upi_code']) && (!$localProduct || $localProduct->upi_code !== $payload['upi_code'])) {
                $upiCode = $payload['upi_code'];
                $hasChanges = true;
            }
            if (isset($payload['upi_status']) && (!$localProduct || $localProduct->upi_status !== $payload['upi_status'])) {
                $upiStatus = $payload['upi_status'];
                $hasChanges = true;
            }
            if (isset($payload['item_category']) && (!$localProduct || $localProduct->item_category !== $payload['item_category'])) {
                $itemCategory = $payload['item_category'];
                $hasChanges = true;
            }

            if ($hasChanges) {
                $lastUpdatedBy = 'Shopify Webhook';
                $lastUpdatedAt = now();
            }
        }

        // Variant-aware loop protection check
        $variantsIdentical = true;
        if ($localProduct) {
            $localVariants = $localProduct->variants;
            $payloadVariants = $payload['variants'] ?? [];
            if (count($localVariants) !== count($payloadVariants)) {
                $variantsIdentical = false;
            } else {
                foreach ($payloadVariants as $pv) {
                    $matchingLocal = $localVariants->first(fn($lv) => (int)$lv->shopify_variant_id === (int)$pv['id']);
                    if (!$matchingLocal ||
                        (string)$matchingLocal->price !== (string)$pv['price'] ||
                        $matchingLocal->sku !== ($pv['sku'] ?? null)) {
                        $variantsIdentical = false;
                        break;
                    }
                }
            }
        } else {
            $variantsIdentical = false;
        }

        // Loop Protection Check: Skip updating if fields haven't changed
        if ($localProduct &&
            $localProduct->title === $title &&
            $localProduct->handle === $handle &&
            $localProduct->vendor === $vendor &&
            $localProduct->product_type === $productType &&
            $localProduct->status === $status &&
            $localProduct->upi_code === $upiCode &&
            $localProduct->upi_status === $upiStatus &&
            $localProduct->item_category === $itemCategory &&
            $localProduct->primary_licensor === $primaryLicensor &&
            $localProduct->main_image_url === $mainImageUrl &&
            $variantsIdentical) {
            
            Log::info("ProductSyncService: Product {$shopifyProductId} details are identical. Bypassing database save (Loop Protection).");
            return $localProduct;
        }

        $targetSyncStatus = $preserveLocalUpi ? $localProduct->sync_status : 'synced';
        $targetLastSyncedAt = $preserveLocalUpi ? $localProduct->last_synced_at : now();

        // Save local update using Repository
        $updatedProduct = $this->productRepository->updateOrCreate(
            [
                'shop_id' => $shop->id,
                'shopify_product_id' => $shopifyProductId
            ],
            [
                'title' => $title,
                'handle' => $handle,
                'vendor' => $vendor,
                'product_type' => $productType,
                'status' => $status,
                'upi_code' => $upiCode,
                'upi_status' => $upiStatus,
                'item_category' => $itemCategory,
                'primary_licensor' => $primaryLicensor,
                'main_image_url' => $mainImageUrl,
                'last_updated_by' => $lastUpdatedBy,
                'last_updated_at' => $lastUpdatedAt,
                'metafield_id' => $metafieldId,
                'sync_status' => $targetSyncStatus,
                'last_synced_at' => $targetLastSyncedAt,
            ]
        );

        if ($updatedProduct) {
            $payloadVariants = $payload['variants'] ?? [];
            foreach ($payloadVariants as $pv) {
                \App\Models\ProductVariant::updateOrCreate(
                    [
                        'product_id' => $updatedProduct->id,
                        'shopify_variant_id' => (int)$pv['id'],
                    ],
                    [
                        'title' => $pv['title'] ?? '',
                        'price' => $pv['price'] ?? 0.00,
                        'sku' => $pv['sku'] ?? null,
                    ]
                );
            }
        }

        Log::info("ProductSyncService: Synced product {$shopifyProductId} successfully.");
        return $updatedProduct;
    }

    /**
     * Start a local update transaction for the product's UPI and dispatch push job.
     */
    public function triggerLocalUpiUpdate(Product $product, ?string $upiCode, ?string $upiStatus = null, string $updatedBy = 'Laravel Dashboard', ?string $productType = null): void
    {
        $this->productRepository->updateOrCreate(
            ['id' => $product->id],
            [
                'upi_code' => $upiCode ?: null,
                'upi_status' => $upiStatus ?: null,
                'product_type' => $productType !== null ? ($productType ?: null) : $product->product_type,
                'last_updated_by' => $updatedBy,
                'last_updated_at' => now(),
                'sync_status' => 'pending_push',
            ]
        );

        PushUpiToShopifyJob::dispatch($product);
    }

    /**
     * Sync local UPI Code and Status back to Shopify's product database.
     */
    public function pushUpiToShopify(Product $product): bool
    {
        $shop = $product->shop;

        if ($shop->access_token === 'mock_access_token_123456789') {
            $this->productRepository->updateOrCreate(
                ['id' => $product->id],
                [
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]
            );
            return true;
        }

        $client = new ShopifyClient($shop);

        // Update standard product fields (specifically productType) on Shopify
        $updateSuccess = $client->updateProduct([
            'id' => "gid://shopify/Product/{$product->shopify_product_id}",
            'productType' => $product->product_type,
        ]);

        $success = $client->setProductUpi(
            $product->shopify_product_id,
            $product->upi_code,
            $product->upi_status,
            $product->item_category,
            $product->primary_licensor
        );

        if ($updateSuccess && $success) {
            $this->productRepository->updateOrCreate(
                ['id' => $product->id],
                [
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]
            );
            return true;
        }

        $this->productRepository->updateOrCreate(
            ['id' => $product->id],
            ['sync_status' => 'failed']
        );
        return false;
    }
}
