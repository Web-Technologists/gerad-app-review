<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\Product;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyGenerateUpisCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:generate-upis {shop_domain?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate unique UPI codes for products missing them, both locally and queueing a push to Shopify.';

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
            $this->info("Found " . $shops->count() . " shops. Generating UPIs for all stores...");
        }

        // Load all existing UPI codes in memory to prevent collisions
        $existingUpis = Product::whereNotNull('upi_code')
            ->where('upi_code', '!=', '')
            ->pluck('upi_code')
            ->flip()
            ->toArray();

        foreach ($shops as $shop) {
            $this->info("\nGenerating UPIs for store: {$shop->shop_domain}...");
            $this->generateStoreUpis($shop, $existingUpis);
        }

        $this->info("\nAll operations completed successfully.");
        return Command::SUCCESS;
    }

    /**
     * Generate UPIs for a single shop.
     */
    protected function generateStoreUpis(Shop $shop, array &$existingUpis): void
    {
        $productsMissingUpi = Product::where('shop_id', $shop->id)
            ->where(function($q) {
                $q->whereNull('upi_code')->orWhere('upi_code', '');
            })
            ->get();

        if ($productsMissingUpi->isEmpty()) {
            $this->info("All products in store {$shop->shop_domain} already have UPI codes.");
            return;
        }

        $this->info("Found " . $productsMissingUpi->count() . " products missing UPIs. Generating...");

        $updates = [];
        $jobUpdates = [];
        $generatedCount = 0;

        foreach ($productsMissingUpi as $product) {
            $datePrefix = $product->created_at ? $product->created_at->format('ymd') : date('ymd');
            $idSuffix = $product->shopify_product_id 
                ? substr((string)$product->shopify_product_id, -4) 
                : str_pad((string)$product->id, 4, '0', STR_PAD_LEFT);
            
            $baseUpiCode = "UPI{$datePrefix}{$idSuffix}";
            $upiCode = $baseUpiCode;
            $counter = 1;

            // Uniqueness check: verify the UPI code is not already in use in memory
            while (isset($existingUpis[$upiCode])) {
                $counterStr = (string)$counter;
                $upiCode = substr($baseUpiCode, 0, 15 - strlen($counterStr)) . $counterStr;
                $counter++;
            }

            // Reserve it in memory for subsequent iterations
            $existingUpis[$upiCode] = true;

            $updates[] = [
                'id' => $product->id,
                'upi_code' => $upiCode,
                'upi_status' => 'Active',
                'item_category' => $product->item_category,
            ];

            $jobUpdates[] = [
                'shopify_product_id' => $product->shopify_product_id,
                'upi_code' => $upiCode,
                'upi_status' => 'Active',
            ];

            $generatedCount++;
        }

        try {
            // Run local DB updates in a single transaction
            DB::transaction(function() use ($updates) {
                foreach ($updates as $update) {
                    Product::where('id', $update['id'])->update([
                        'upi_code' => $update['upi_code'],
                        'upi_status' => $update['upi_status'],
                        'item_category' => $update['item_category'],
                        'last_updated_by' => 'CLI Generator',
                        'last_updated_at' => now(),
                        'sync_status' => 'pending_push',
                    ]);
                }
            });

            // Dispatch bulk push job
            BulkPushUpiToShopifyJob::dispatch($jobUpdates, 'CLI Generator');
            $this->info("Successfully generated and queued/completed UPI codes for {$generatedCount} products in {$shop->shop_domain}.");
        } catch (\Exception $e) {
            Log::error("CLI UPI generation error for {$shop->shop_domain}: " . $e->getMessage());
            $this->error("UPI generation failed for {$shop->shop_domain}: " . $e->getMessage());
        }
    }
}
