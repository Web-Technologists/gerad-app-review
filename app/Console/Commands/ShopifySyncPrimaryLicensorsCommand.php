<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Shop;
use App\Models\Product;
use App\Services\ShopifyClient;
use App\Services\LicensorDetectionService;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifySyncPrimaryLicensorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync-primary-licensors 
                            {shop_domain? : Target Shopify shop domain (optional)}
                            {--queue : Dispatch push jobs to background queue workers instead of synchronous HTTP calls}
                            {--resume : Resume mode: skip products that have already been synced successfully}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register Primary Licensor metafield definition, populate primary_licensor for all products in DB across every store, and push metafields to Shopify.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $shopDomain = $this->argument('shop_domain');
        $isResume = $this->option('resume');

        if ($shopDomain) {
            $cleanDomain = trim(strtolower($shopDomain));
            $cleanDomain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $cleanDomain);
            $cleanDomain = rtrim($cleanDomain, '/');

            $shops = Shop::where('shop_domain', 'like', "%{$cleanDomain}%")->get();
            if ($shops->isEmpty()) {
                $this->error("No store found matching domain: {$shopDomain}");
                return 1;
            }
        } else {
            $shops = Shop::all();
            if ($shops->isEmpty()) {
                $this->error("No registered stores found in database.");
                return 1;
            }
        }

        $this->info("Starting Primary Licensor Sync across " . $shops->count() . " store(s)..." . ($isResume ? " [RESUME MODE ACTIVE]" : ""));

        $totalShopsProcessed = 0;
        $totalProductsUpdated = 0;

        foreach ($shops as $shop) {
            $this->line("");
            $this->info("=================================================");
            $this->info("Processing Store: {$shop->shop_domain} (ID: {$shop->id})");
            $this->info("=================================================");

            // 1. Ensure custom.primary_licensor metafield definition exists on Shopify
            if ($shop->access_token !== 'mock_access_token_123456789') {
                try {
                    $client = new ShopifyClient($shop);
                    $registered = $client->registerMetafieldDefinition();
                    if ($registered) {
                        $this->info("  [✓] Metafield definitions registered/verified on Shopify.");
                    } else {
                        $this->warn("  [!] Could not register metafield definitions on Shopify.");
                    }
                } catch (\Exception $e) {
                    $this->error("  [X] Failed to connect to Shopify: " . $e->getMessage());
                }
            } else {
                $this->info("  [✓] Mock store - skipping live Shopify API definition registration.");
            }

            // 2. Build product query (with resume support)
            $query = Product::where('shop_id', $shop->id);
            if ($isResume) {
                $query->where(function($q) {
                    $q->whereNull('primary_licensor')
                      ->orWhere('sync_status', '!=', 'synced');
                });
            }

            $totalStoreProducts = $query->count();
            if ($totalStoreProducts === 0) {
                $this->info("  [✓] All products for {$shop->shop_domain} are already synced. Skipping.");
                continue;
            }

            $this->info("  Found {$totalStoreProducts} remaining products to sync for {$shop->shop_domain}. Processing in chunks of 250...");

            $shopUpdatedCount = 0;

            $query->chunk(250, function ($chunkProducts) use ($shop, $totalStoreProducts, &$shopUpdatedCount) {
                $bulkUpdates = [];

                DB::transaction(function() use ($chunkProducts, &$bulkUpdates, &$shopUpdatedCount) {
                    foreach ($chunkProducts as $product) {
                        $licensor = LicensorDetectionService::resolveProductPrimaryLicensor($product);

                        $product->update([
                            'primary_licensor' => $licensor,
                            'sync_status' => 'pending_push',
                            'last_updated_by' => 'System Licensor Command',
                            'last_updated_at' => now(),
                        ]);

                        $bulkUpdates[] = [
                            'shopify_product_id' => $product->shopify_product_id,
                            'upi_code' => $product->upi_code,
                            'upi_status' => $product->upi_status ?? 'Active',
                        ];

                        $shopUpdatedCount++;
                    }
                });

                if (!empty($bulkUpdates)) {
                    if ($this->option('queue')) {
                        BulkPushUpiToShopifyJob::dispatch($bulkUpdates, 'System Licensor Command');
                    } else {
                        BulkPushUpiToShopifyJob::dispatchSync($bulkUpdates, 'System Licensor Command');
                    }
                }

                $this->info("  [✓] Processed {$shopUpdatedCount} / {$totalStoreProducts} products for {$shop->shop_domain}");
                gc_collect_cycles();
            });

            $totalShopsProcessed++;
            $totalProductsUpdated += $shopUpdatedCount;
        }

        $this->line("");
        $this->info("=================================================");
        $this->info("SUCCESS: Primary Licensor Sync Complete!");
        $this->info("Total Stores Processed:  {$totalShopsProcessed}");
        $this->info("Total Products Updated:  {$totalProductsUpdated}");
        $this->info("=================================================");

        return 0;
    }
}
