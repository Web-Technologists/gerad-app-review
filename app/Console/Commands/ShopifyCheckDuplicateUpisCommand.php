<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\Shop;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Support\Facades\DB;

class ShopifyCheckDuplicateUpisCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:check-duplicate-upis
                            {--fix : Automatically fix duplicate UPIs by re-generating new unique codes}
                            {--push : Automatically push new UPIs to Shopify after fixing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect duplicate UPI codes across all products, display them on terminal, and optionally re-generate unique UPIs.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("=================================================");
        $this->info("      Shopify Product UPI Duplicate Audit        ");
        $this->info("=================================================");
        $this->line("");

        // 1. Find all duplicate UPI codes
        $duplicateGroups = DB::table('products')
            ->select('upi_code', DB::raw('COUNT(*) as total_count'))
            ->whereNotNull('upi_code')
            ->where('upi_code', '!=', '')
            ->groupBy('upi_code')
            ->having('total_count', '>', 1)
            ->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info("✅ SUCCESS: 0 duplicate UPI codes found! All product UPIs are 100% unique.");
            return Command::SUCCESS;
        }

        $totalDuplicateUpis = $duplicateGroups->count();
        $affectedProductsCount = $duplicateGroups->sum('total_count');

        $this->warn("⚠️  WARNING: Found {$totalDuplicateUpis} duplicate UPI code(s) affecting {$affectedProductsCount} products!");
        $this->line("");

        // 2. Display duplicate breakdown on terminal
        $tableData = [];
        $duplicateProductsList = [];

        foreach ($duplicateGroups as $group) {
            $upi = $group->upi_code;
            $products = Product::with('shop')
                ->where('upi_code', $upi)
                ->get();

            $duplicateProductsList[$upi] = $products;

            foreach ($products as $idx => $p) {
                $shopDomain = $p->shop ? $p->shop->shop_domain : "Shop #{$p->shop_id}";
                $tableData[] = [
                    'UPI Code' => $idx === 0 ? "<comment>{$upi}</comment>" : "",
                    'Dup #' => $idx + 1,
                    'Product ID' => $p->shopify_product_id ?: $p->id,
                    'Product Title' => mb_strimwidth($p->title, 0, 35, "..."),
                    'Store' => $shopDomain,
                ];
            }
        }

        $this->table(['UPI Code', 'Dup #', 'Product ID', 'Product Title', 'Store'], $tableData);
        $this->line("");

        // 3. Ask user for confirmation to fix/re-generate UPIs
        $autoFix = $this->option('fix');
        $shouldFix = $autoFix;

        if (!$autoFix) {
            $shouldFix = $this->confirm(
                "Do you want to re-generate new unique UPI codes for these duplicate products to ensure 100% uniqueness?",
                true
            );
        }

        if (!$shouldFix) {
            $this->warn("Cancelled. No changes were made to duplicate UPIs.");
            return Command::SUCCESS;
        }

        // 4. Load all existing UPI codes in memory to prevent collisions
        $existingUpis = Product::whereNotNull('upi_code')
            ->where('upi_code', '!=', '')
            ->pluck('upi_code')
            ->flip()
            ->toArray();

        $this->info("\nGenerating fresh unique UPI codes for duplicate products...");

        $updatedCount = 0;
        $updatesByShop = [];

        DB::transaction(function() use ($duplicateProductsList, &$existingUpis, &$updatedCount, &$updatesByShop) {
            foreach ($duplicateProductsList as $upi => $products) {
                // Keep the 1st product's UPI untouched, re-generate for all subsequent duplicates
                $isFirst = true;

                foreach ($products as $product) {
                    if ($isFirst) {
                        $isFirst = false;
                        continue; // Keep 1st product's UPI
                    }

                    // Generate new unique UPI code
                    $datePrefix = $product->created_at ? $product->created_at->format('ymd') : date('ymd');
                    $idSuffix = $product->shopify_product_id 
                        ? substr((string)$product->shopify_product_id, -4) 
                        : str_pad((string)$product->id, 4, '0', STR_PAD_LEFT);

                    $baseUpi = "UPI{$datePrefix}{$idSuffix}";
                    $newUpi = $baseUpi;
                    $counter = 1;

                    while (isset($existingUpis[$newUpi])) {
                        $counterStr = (string)$counter;
                        $newUpi = substr($baseUpi, 0, 15 - strlen($counterStr)) . $counterStr;
                        $counter++;
                    }

                    // Reserve new UPI in memory
                    $existingUpis[$newUpi] = true;

                    // Update database record
                    $product->update([
                        'upi_code' => $newUpi,
                        'upi_status' => 'Active',
                        'sync_status' => 'pending_push',
                        'last_updated_by' => 'UPI Duplicate Resolution Command',
                        'last_updated_at' => now(),
                    ]);

                    $updatedCount++;
                    $shopId = $product->shop_id;
                    $updatesByShop[$shopId][] = [
                        'shopify_product_id' => $product->shopify_product_id,
                        'upi_code' => $newUpi,
                        'upi_status' => 'Active',
                    ];

                    $this->line("  • Product #{$product->shopify_product_id} ('{$product->title}'): Changed UPI from {$upi} -> <info>{$newUpi}</info>");
                }
            }
        });

        $this->line("");
        $this->info("Re-generated {$updatedCount} unique UPI code(s).");

        // 5. Optionally push new UPIs to Shopify
        $shouldPush = $this->option('push');
        if (!$shouldPush && !$autoFix) {
            $shouldPush = $this->confirm("Do you want to queue/push these updated UPIs to Shopify now?", true);
        }

        if ($shouldPush && !empty($updatesByShop)) {
            $this->info("Pushing updated UPIs to Shopify...");
            foreach ($updatesByShop as $shopId => $payload) {
                BulkPushUpiToShopifyJob::dispatch($payload, 'UPI Duplicate Resolution Command');
            }
            $this->info("Queued push jobs for " . count($updatesByShop) . " store(s).");
        }

        // 6. Verify zero duplicates remaining
        $remainingDuplicates = DB::table('products')
            ->select('upi_code', DB::raw('COUNT(*) as total_count'))
            ->whereNotNull('upi_code')
            ->where('upi_code', '!=', '')
            ->groupBy('upi_code')
            ->having('total_count', '>', 1)
            ->count();

        $this->line("");
        if ($remainingDuplicates === 0) {
            $this->info("=================================================");
            $this->info("✅ AUDIT COMPLETE: 0 duplicate UPIs remaining!");
            $this->info("All products in database now have 100% unique UPIs.");
            $this->info("=================================================");
        } else {
            $this->warn("Warning: {$remainingDuplicates} duplicate UPI groups still remain.");
        }

        return Command::SUCCESS;
    }
}
