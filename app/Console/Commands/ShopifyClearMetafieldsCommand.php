<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\Product;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyClearMetafieldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:clear-metafields {shop_domain?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all product UPI and status metafields both on Shopify and in the local database for a given store or all stores.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $shopDomain = $this->argument('shop_domain');

        if ($shopDomain) {
            $shops = Shop::where('shop_domain', $shopDomain)
                ->orWhere('shop_domain', 'like', "%{$shopDomain}%")
                ->get();
            if ($shops->isEmpty()) {
                $this->error("Shop with domain matching '{$shopDomain}' not found in the database.");
                return Command::FAILURE;
            }
        } else {
            $shops = Shop::all();
            if ($shops->isEmpty()) {
                $this->info("No shops found in the database.");
                return Command::SUCCESS;
            }
            $this->info("Found " . $shops->count() . " shops. Clearing metafields for all stores...");
        }

        foreach ($shops as $shop) {
            $this->info("\nClearing metafields for store: {$shop->shop_domain}...");
            $this->clearStoreMetafields($shop);
        }

        $this->info("\nAll operations completed successfully.");
        return Command::SUCCESS;
    }

    /**
     * Clear metafields for a single shop.
     */
    protected function clearStoreMetafields(Shop $shop): void
    {
        $this->info("Fetching products for store: {$shop->shop_domain}...");
        $products = Product::where('shop_id', $shop->id)
            ->whereNotNull('shopify_product_id')
            ->get();

        if ($products->isEmpty()) {
            $this->info("No products found with Shopify IDs in the local database for {$shop->shop_domain}.");
            return;
        }

        $this->info("Found " . $products->count() . " products. Clearing metafields...");

        $isMock = $shop->access_token === 'mock_access_token_123456789' || str_starts_with($shop->access_token ?? '', 'mock');

        if ($isMock) {
            $this->info("[Mock Path] Clearing local database records only...");
            DB::transaction(function () use ($products) {
                foreach ($products as $product) {
                    $product->update([
                        'upi_code' => null,
                        'upi_status' => null,
                        'sync_status' => 'synced',
                    ]);
                }
            });
            $this->info("Cleared local database values successfully.");
            return;
        }

        $client = new ShopifyClient($shop);
        $chunks = $products->chunk(25);

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        foreach ($chunks as $chunk) {
            $identifiers = [];
            foreach ($chunk as $product) {
                $identifiers[] = [
                    'ownerId' => "gid://shopify/Product/{$product->shopify_product_id}",
                    'namespace' => 'custom',
                    'key' => 'upi',
                ];
                $identifiers[] = [
                    'ownerId' => "gid://shopify/Product/{$product->shopify_product_id}",
                    'namespace' => 'custom',
                    'key' => 'upi_status',
                ];
            }

            // Delete metafields on Shopify
            $success = false;
            try {
                $success = $client->deleteBulkMetafields($identifiers);
            } catch (\Exception $e) {
                $this->error("\nFailed to delete metafields on Shopify: " . $e->getMessage());
            }

            // Update local DB records
            DB::transaction(function () use ($chunk, $success) {
                foreach ($chunk as $product) {
                    $product->update([
                        'upi_code' => null,
                        'upi_status' => null,
                        'sync_status' => $success ? 'synced' : 'failed',
                    ]);
                }
            });

            $bar->advance($chunk->count());
        }

        $bar->finish();
        $this->info("\nMetafields cleared successfully for {$shop->shop_domain}.");
    }
}
