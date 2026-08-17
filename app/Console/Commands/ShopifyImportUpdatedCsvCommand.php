<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Shop;
use App\Models\Product;
use App\Services\LicensorDetectionService;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyImportUpdatedCsvCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:import-updated-csv 
                            {file=UPDATED.0722.csv : Path to the updated CSV file relative to project root or absolute path} 
                            {--dry-run : Only show diffs without updating database or pushing to Shopify}
                            {--sync : Push updates to Shopify synchronously instead of dispatching to queue}
                            {--resume : Resume mode: skip products that have already been updated and synced}
                            {--update-images : Update main_image_url field from CSV}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import updated product CSV (UPI, Category, Primary Licensor), update database records, and push metafields to Shopify.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $filePath = $this->argument('file');
        if (!str_starts_with($filePath, '/')) {
            $filePath = base_path($filePath);
        }

        if (!file_exists($filePath)) {
            $this->error("Error: CSV file not found at path: {$filePath}");
            return 1;
        }

        $isDryRun = $this->option('dry-run');
        $isSync = $this->option('sync');
        $isResume = $this->option('resume');
        $isUpdateImages = $this->option('update-images');

        $this->info("=================================================");
        $this->info("      Shopify Product CSV Importer & Sync        ");
        $this->info("=================================================");
        $this->info("CSV File: " . basename($filePath));
        $this->info("Mode:     " . ($isDryRun ? "[DRY RUN] (No changes will be saved)" : "[LIVE IMPORT]"));
        if ($isResume) {
            $this->info("Option:   [RESUME MODE ACTIVE] (Skipping already synced products)");
        }
        $this->line("");

        // Build strict shop map & product lookups from database
        $this->info("Loading existing product catalog from database...");
        $allShops = Shop::all();
        $shopMap = [];

        foreach ($allShops as $sObj) {
            $sId = (string)$sObj->id;
            $shopMap[$sId] = $sObj;

            $domain = strtolower(trim($sObj->shop_domain));
            $shopMap[$domain] = $sObj;

            $domainPrefix = explode('.', $domain)[0];
            if (!isset($shopMap[$domainPrefix])) {
                $shopMap[$domainPrefix] = $sObj;
            }

            if (!empty($sObj->shop_name)) {
                $sName = strtolower(trim($sObj->shop_name));
                $shopMap[$sName] = $sObj;
            }
        }

        $allProducts = Product::with('shop')->get();
        $byShopAndUpi = [];
        $byShopAndTitle = [];

        foreach ($allProducts as $p) {
            if (!$p->shop_id) continue;
            $sId = $p->shop_id;

            if (!empty($p->upi_code)) {
                $upi = trim($p->upi_code);
                $byShopAndUpi["{$sId}||{$upi}"] = $p;
            }
            if ($p->shopify_product_id) {
                $pid = (string)$p->shopify_product_id;
                $byShopAndUpi["{$sId}||{$pid}"] = $p;
            }

            if (!empty($p->title)) {
                $title = strtolower(trim($p->title));
                $byShopAndTitle["{$sId}||{$title}"] = $p;
            }
        }

        $this->info("Catalog loaded: " . $allProducts->count() . " products across " . count($allShops) . " connected store(s).");
        $this->line("");

        // Open CSV file
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error("Failed to open CSV file.");
            return 1;
        }

        $headers = fgetcsv($handle, 10000, ',');
        if (!$headers) {
            $this->error("CSV file is empty or header format is invalid.");
            fclose($handle);
            return 1;
        }

        $headerMap = array_flip(array_map('trim', $headers));

        $totalRows = 0;
        $matchedCount = 0;
        $unmatchedCount = 0;
        $explicitLicensorCount = 0;
        $variousLicensorCount = 0;
        $updatesByShop = [];
        $storeStats = [];

        while (($data = fgetcsv($handle, 10000, ',')) !== false) {
            $totalRows++;

            $storeName = trim($data[$headerMap['Store Name'] ?? 0] ?? '');
            $productName = trim($data[$headerMap['Product Name'] ?? 1] ?? '');
            $csvUpi = trim($data[$headerMap['UPI'] ?? 2] ?? '');
            $csvCategory = trim($data[$headerMap['Product Category'] ?? 3] ?? '');
            $csvImageUrl = trim($data[$headerMap['Product Main Image URL'] ?? 4] ?? '');
            $csvLicensor = trim($data[$headerMap['Primary Licensor'] ?? 5] ?? '');

            if (!empty($csvLicensor) && strtolower($csvLicensor) !== 'various') {
                $explicitLicensorCount++;
            } else {
                $variousLicensorCount++;
            }

            // Match shop strictly first
            $storeClean = strtolower($storeName);
            $targetShop = $shopMap[$storeClean] ?? null;

            if (!$targetShop && !empty($storeClean)) {
                // Fallback exact match on domain or name
                foreach ($allShops as $sObj) {
                    if (strtolower($sObj->shop_domain) === $storeClean || strtolower($sObj->shop_name) === $storeClean) {
                        $targetShop = $sObj;
                        break;
                    }
                }
            }

            if (!$targetShop) {
                $unmatchedCount++;
                continue;
            }

            // Match product strictly scoped to targetShop->id (No cross-store matching!)
            $targetShopId = $targetShop->id;
            $product = null;

            // 1. Match by UPI / Product ID + Store ID
            if (!empty($csvUpi)) {
                $upiKey = "{$targetShopId}||{$csvUpi}";
                if (isset($byShopAndUpi[$upiKey])) {
                    $product = $byShopAndUpi[$upiKey];
                }
            }

            // 2. Fallback: Match by Title + Store ID
            if (!$product && !empty($productName)) {
                $titleClean = strtolower($productName);
                $titleKey = "{$targetShopId}||{$titleClean}";
                if (isset($byShopAndTitle[$titleKey])) {
                    $product = $byShopAndTitle[$titleKey];
                }
            }

            if (!$product) {
                $unmatchedCount++;
                continue;
            }

            if ($isResume && $product->sync_status === 'synced' && !empty($product->primary_licensor) && ($product->upi_code ?? '') === $csvUpi) {
                continue;
            }

            $matchedCount++;

            // Detect field updates (Normalized, trimmed, case-insensitive comparison)
            $dbUpi = trim((string)($product->upi_code ?? ''));
            $dbCategory = trim((string)(!empty($product->item_category) ? $product->item_category : ($product->product_type ?? '')));
            $dbLicensor = trim((string)($product->primary_licensor ?? ''));
            $dbImageUrl = trim((string)($product->main_image_url ?? ''));

            $fieldChanges = [];
            if (!empty($csvUpi) && strtoupper($csvUpi) !== strtoupper($dbUpi)) {
                $fieldChanges['upi_code'] = ['old' => $dbUpi, 'new' => $csvUpi];
            }
            if (!empty($csvCategory) && strtolower($csvCategory) !== strtolower($dbCategory)) {
                $fieldChanges['product_type'] = ['old' => $dbCategory, 'new' => $csvCategory];
            }
            if (!empty($csvLicensor) && strtoupper($csvLicensor) !== strtoupper($dbLicensor)) {
                $fieldChanges['primary_licensor'] = ['old' => $dbLicensor, 'new' => $csvLicensor];
            }
            if ($isUpdateImages && !empty($csvImageUrl) && $csvImageUrl !== $dbImageUrl) {
                $fieldChanges['main_image_url'] = ['old' => $dbImageUrl, 'new' => $csvImageUrl];
            }

            if (!empty($fieldChanges)) {
                $shopId = $product->shop_id;
                $shopName = $product->shop ? ($product->shop->shop_name ?: $product->shop->shop_domain) : "Shop #{$shopId}";

                if (!isset($storeStats[$shopName])) {
                    $storeStats[$shopName] = 0;
                }
                $storeStats[$shopName]++;

                $updatesByShop[$shopId][] = [
                    'product' => $product,
                    'shopify_product_id' => $product->shopify_product_id,
                    'upi_code' => $fieldChanges['upi_code']['new'] ?? $dbUpi,
                    'product_type' => $fieldChanges['product_type']['new'] ?? $dbCategory,
                    'primary_licensor' => $fieldChanges['primary_licensor']['new'] ?? $dbLicensor,
                    'main_image_url' => $fieldChanges['main_image_url']['new'] ?? $dbImageUrl,
                    'upi_status' => $product->upi_status ?? 'Active',
                    'changes' => $fieldChanges
                ];
            }
        }
        fclose($handle);

        $totalUpdates = array_sum(array_map('count', $updatesByShop));

        $this->info("CSV Parse Results:");
        $this->info("  • Total CSV Rows:         {$totalRows}");
        $this->info("  • Matched Products:       {$matchedCount}");
        $this->info("  • Explicit CSV Licensors: {$explicitLicensorCount} (Assigned directly)");
        $this->info("  • Various/Empty Licensors:{$variousLicensorCount} (Requires manual review)");
        $this->info("  • Unmatched Rows:         {$unmatchedCount}");
        $this->info("  • Products To Update:     {$totalUpdates} across " . count($updatesByShop) . " store(s)");
        $this->line("");

        if ($totalUpdates === 0) {
            $this->info("No product changes detected. Catalog is up to date.");
            return 0;
        }

        $this->info("Store Modification Breakdown:");
        foreach ($storeStats as $sName => $cnt) {
            $this->line("  - {$sName}: {$cnt} product(s) modified");
        }
        $this->line("");

        // Write Audit Log CSV File (Before & After Values)
        $auditLogFile = storage_path("logs/import_audit_all_stores_" . date('Ymd_His') . ".csv");
        $auditDir = dirname($auditLogFile);
        if (!file_exists($auditDir)) {
            mkdir($auditDir, 0755, true);
        }

        $auditHandle = fopen($auditLogFile, 'w');
        if ($auditHandle) {
            fputcsv($auditHandle, [
                'Store Name',
                'Shopify Product ID',
                'Product Title',
                'Changed Fields',
                'UPI Code (Before)',
                'UPI Code (After)',
                'Category (Before)',
                'Category (After)',
                'Primary Licensor (Before)',
                'Primary Licensor (After)',
                'Timestamp'
            ]);

            foreach ($updatesByShop as $shopId => $items) {
                foreach ($items as $item) {
                    $pObj = $item['product'];
                    $changes = $item['changes'];
                    $changedKeys = array_keys($changes);

                    fputcsv($auditHandle, [
                        $pObj->shop ? ($pObj->shop->shop_name ?: $pObj->shop->shop_domain) : "Shop #{$shopId}",
                        $pObj->shopify_product_id,
                        $pObj->title,
                        implode(', ', $changedKeys),
                        $changes['upi_code']['old'] ?? $pObj->upi_code,
                        $changes['upi_code']['new'] ?? $pObj->upi_code,
                        $changes['product_type']['old'] ?? ($pObj->item_category ?: $pObj->product_type),
                        $changes['product_type']['new'] ?? ($pObj->item_category ?: $pObj->product_type),
                        $changes['primary_licensor']['old'] ?? $pObj->primary_licensor,
                        $changes['primary_licensor']['new'] ?? $pObj->primary_licensor,
                        now()->toIso8601String()
                    ]);
                }
            }
            fclose($auditHandle);
            $this->info("Audit Log File generated: {$auditLogFile}");
        }

        if ($isDryRun) {
            $this->warn("[DRY RUN COMPLETE] No updates were written to database or pushed to Shopify.");
            return 0;
        }

        // Live execution
        $this->info("Applying updates to database and queueing Shopify push...");
        $pushedCount = 0;

        foreach ($updatesByShop as $shopId => $items) {
            $shop = Shop::find($shopId);
            if (!$shop) continue;

            $totalStoreItems = count($items);
            $this->info("Processing store: {$shop->shop_domain} ({$shop->shop_name}) - {$totalStoreItems} products...");

            $itemChunks = array_chunk($items, 250);
            $storeProcessed = 0;

            foreach ($itemChunks as $chunk) {
                $bulkPayload = [];

                DB::transaction(function() use ($chunk, &$bulkPayload, $isUpdateImages) {
                    foreach ($chunk as $item) {
                        /** @var Product $prod */
                        $prod = $item['product'];
                        $updateData = [
                            'upi_code' => $item['upi_code'] ?: null,
                            'item_category' => $item['product_type'] ?: null,
                            'product_type' => $item['product_type'] ?: $prod->product_type,
                            'primary_licensor' => $item['primary_licensor'] ?: $prod->primary_licensor,
                            'sync_status' => 'pending_push',
                            'last_updated_by' => 'CSV Bulk Import Command',
                            'last_updated_at' => now(),
                        ];
                        if ($isUpdateImages && !empty($item['main_image_url'])) {
                            $updateData['main_image_url'] = $item['main_image_url'];
                        }
                        $prod->update($updateData);

                        $bulkPayload[] = [
                            'shopify_product_id' => $prod->shopify_product_id,
                            'upi_code' => $item['upi_code'],
                            'upi_status' => $item['upi_status'],
                        ];
                    }
                });

                if (!empty($bulkPayload)) {
                    if ($isSync) {
                        BulkPushUpiToShopifyJob::dispatchSync($bulkPayload, 'CSV Bulk Import Command');
                    } else {
                        BulkPushUpiToShopifyJob::dispatch($bulkPayload, 'CSV Bulk Import Command');
                    }
                }

                $storeProcessed += count($chunk);
                $this->info("  [✓] Processed {$storeProcessed} / {$totalStoreItems} products for {$shop->shop_domain}");
                gc_collect_cycles();
            }

            $pushedCount += $totalStoreItems;
        }

        $this->line("");
        $this->info("=================================================");
        $this->info("SUCCESS: Bulk CSV Import & Sync Completed!");
        $this->info("Total Products Updated: {$pushedCount}");
        $this->info("=================================================");

        return 0;
    }
}
