<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ShopifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;

class StoreProvisioningJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public Shop $shop;
    public bool $isMock;

    /**
     * Create a new job instance.
     */
    public function __construct(Shop $shop, bool $isMock = false)
    {
        $this->shop = $shop;
        $this->isMock = $isMock;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting store provisioning for: {$this->shop->shop_domain}");

        // Fetch and save store name details
        $client = new ShopifyClient($this->shop);
        try {
            $details = $client->getShopDetails();
            if (!empty($details['name'])) {
                $this->shop->update(['shop_name' => $details['name']]);
                Log::info("StoreProvisioningJob: Saved shop name '{$details['name']}' for {$this->shop->shop_domain}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch shop name details for {$this->shop->shop_domain}: " . $e->getMessage());
        }

        if ($this->isMock) {
            $this->populateMockData();
            Log::info("Mock store provisioning complete for: {$this->shop->shop_domain}");
            return;
        }

        // Dispatch modular job chain for real installations
        Bus::chain([
            new RegisterWebhooksJob($this->shop),
            new CreateMetafieldDefinitionsJob($this->shop),
            new PushPendingUpisJob($this->shop),
            new SyncInitialProductsJob($this->shop),
        ])->dispatch();

        Log::info("StoreProvisioningJob: Modular onboarding chain dispatched for {$this->shop->shop_domain}");
    }

    /**
     * Sync initial product list from Shopify.
     */
    protected function runInitialProductSync(ShopifyClient $client): void
    {
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
                        featuredImage {
                            url
                        }
                    }
                }
            }
        }
        GQL;

        $hasNextPage = true;
        $cursor = null;

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

                    $upiCode = $upiCodeMeta['value'] ?? null;
                    $upiStatus = $upiStatusMeta['value'] ?? null;
                    $itemCategory = $itemCategoryMeta['value'] ?? null;
                    $mainImageUrl = $node['featuredImage']['url'] ?? null;
                    
                    $metafieldId = $upiCodeMeta['id'] ?? null;
                    if ($metafieldId) {
                        $metafieldId = (int) basename($metafieldId);
                    }

                    Product::updateOrCreate(
                        [
                            'shop_id' => $this->shop->id,
                            'shopify_product_id' => $shopifyId
                        ],
                        [
                            'handle' => $node['handle'] ?? null,
                            'title' => $node['title'],
                            'vendor' => $node['vendor'] ?: 'Generic',
                            'product_type' => $node['productType'] ?: 'Default',
                            'status' => strtolower($node['status']),
                            'upi_code' => $upiCode,
                            'upi_status' => $upiStatus,
                            'item_category' => $itemCategory,
                            'main_image_url' => $mainImageUrl,
                            'last_updated_by' => ($upiCode || $itemCategory) ? 'Shopify Sync' : null,
                            'last_updated_at' => ($upiCode || $itemCategory) ? now() : null,
                            'metafield_id' => $metafieldId,
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]
                    );
                }

                $pageInfo = $productsData['pageInfo'];
                $hasNextPage = $pageInfo['hasNextPage'];
                $cursor = $pageInfo['endCursor'];

            } catch (\Exception $e) {
                Log::error("Error syncing catalog during provisioning: " . $e->getMessage());
                break;
            }
        }
    }

    /**
     * Populate mock products for demonstration.
     */
    protected function populateMockData(): void
    {
        $mockProducts = [
            [
                'title' => 'Wireless Noise-Canceling Headphones WH-1000',
                'vendor' => 'Sony',
                'type' => 'Electronics',
                'status' => 'active',
                'upi' => 'UPI-SONY-WH1000',
                'upi_status' => 'Active',
            ],
            [
                'title' => 'iPhone 15 Pro Max Titanium',
                'vendor' => 'Apple',
                'type' => 'Electronics',
                'status' => 'active',
                'upi' => 'UPI-APPL-IP15P',
                'upi_status' => 'Active',
            ],
            [
                'title' => 'Air Zoom Pegasus Running Shoes',
                'vendor' => 'Nike',
                'type' => 'Apparel',
                'status' => 'active',
                'upi' => 'UPI-NIKE-AZRS',
                'upi_status' => 'Active',
            ],
            [
                'title' => 'Leather Bi-fold Wallet Vintage',
                'vendor' => 'Fossil',
                'type' => 'Accessories',
                'status' => 'active',
                'upi' => 'UPI-FOSL-LBW',
                'upi_status' => 'Pending Review',
            ],
            [
                'title' => 'Classic Heritage Trench Coat',
                'vendor' => 'Burberry',
                'type' => 'Apparel',
                'status' => 'draft',
                'upi' => 'UPI-BURB-CTC',
                'upi_status' => 'Deprecated',
            ],
            [
                'title' => 'Galactic Smartwatch Series 6',
                'vendor' => 'Samsung',
                'type' => 'Electronics',
                'status' => 'active',
                'upi' => null,
                'upi_status' => null,
            ],
            [
                'title' => 'Ultralight Hiker Backpack 45L',
                'vendor' => 'Osprey',
                'type' => 'Outdoor Gear',
                'status' => 'active',
                'upi' => null,
                'upi_status' => null,
            ],
            [
                'title' => 'Barista Express Espresso Machine',
                'vendor' => 'Breville',
                'type' => 'Appliances',
                'status' => 'active',
                'upi' => 'UPI-BREV-BEM',
                'upi_status' => 'Active',
            ],
            [
                'title' => 'Ergonomic Mesh Office Chair',
                'vendor' => 'Herman Miller',
                'type' => 'Furniture',
                'status' => 'archived',
                'upi' => 'UPI-HM-EMOC',
                'upi_status' => 'Active',
            ]
        ];

        foreach ($mockProducts as $index => $p) {
            $shopifyProductId = 8000000000 + $this->shop->id * 1000 + $index;
            $handle = \Illuminate\Support\Str::slug($p['title']);
            
            $product = Product::updateOrCreate(
                [
                    'shop_id' => $this->shop->id,
                    'shopify_product_id' => $shopifyProductId
                ],
                [
                    'handle' => $handle,
                    'title' => $p['title'],
                    'vendor' => $p['vendor'],
                    'product_type' => $p['type'],
                    'status' => $p['status'],
                    'upi_code' => $p['upi'],
                    'upi_status' => $p['upi_status'],
                    'last_updated_by' => $p['upi'] ? 'System Seed' : null,
                    'last_updated_at' => $p['upi'] ? now() : null,
                    'metafield_id' => 9000000000 + $shopifyProductId,
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]
            );

            $shopifyVariantId = 9000000000 + $shopifyProductId * 10;
            ProductVariant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'shopify_variant_id' => $shopifyVariantId
                ],
                [
                    'title' => 'Default Title',
                    'sku' => strtoupper(substr($p['vendor'], 0, 3)) . '-' . rand(1000, 9999),
                    'price' => rand(10, 1000) + 0.99,
                ]
            );
        }
    }
}
