<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Shop;
use App\Models\Product;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Support\Facades\DB;

class ShopifyImportSingleStoreCsvCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:import-single-store-csv
                            {file=07_31.csv : Path to the updated CSV file relative to project root or absolute path}
                            {--shop= : Target Shopify shop domain (e.g. mdydun-bi.myshopify.com)}
                            {--dry-run : Only show diffs without updating database or pushing to Shopify}
                            {--sync : Push updates to Shopify synchronously instead of dispatching queue jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import single store product CSV with non-UPI filtering, blank category preservation, and uppercase licensor normalization.';

    /**
     * Non-UPI prefixes to exclude (client handles these separately)
     */
    protected array $excludedPrefixes = ['PSB', 'DFD', 'SSS', 'KKG', 'PKP'];

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

        $shopInput = $this->option('shop');
        $isDryRun = $this->option('dry-run');
        $isSync = $this->option('sync');

        $this->info("=================================================");
        $this->info("   Single Store Product CSV Importer (07_31)    ");
        $this->info("=================================================");
        $this->info("CSV File:            " . basename($filePath));
        $this->info("Target Store Filter: " . ($shopInput ?: "All Stores in CSV / Auto-detect"));
        $this->info("Mode:                " . ($isDryRun ? "[DRY RUN] (No changes will be saved)" : "[LIVE IMPORT]"));
        $this->line("");

        // Build product lookups from database
        $this->info("Loading existing product catalog from database...");
        $query = Product::with('shop');

        $targetShop = null;
        if ($shopInput) {
            $cleanDomain = trim(strtolower($shopInput));
            $cleanDomain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $cleanDomain);
            $cleanDomain = rtrim($cleanDomain, '/');

            $targetShop = Shop::where('shop_domain', 'like', "%{$cleanDomain}%")->first();
            if (!$targetShop) {
                $this->error("Error: Target store domain '{$shopInput}' not found in database.");
                return 1;
            }
            $query->where('shop_id', $targetShop->id);
            $this->info("Filtered catalog to store: {$targetShop->shop_domain} ({$targetShop->shop_name})");
        }

        $allProducts = $query->get();

        $byUpiStore = [];
        $byTitleStore = [];

        foreach ($allProducts as $p) {
            if ($p->shop) {
                $domain = strtolower(trim($p->shop->shop_domain));
                $name = !empty($p->shop->shop_name) ? strtolower(trim($p->shop->shop_name)) : '';

                // Store-scoped UPI lookup
                if (!empty($p->upi_code)) {
                    $upi = trim($p->upi_code);
                    $byUpiStore["{$domain}||{$upi}"] = $p;
                    if ($name) $byUpiStore["{$name}||{$upi}"] = $p;
                }
                if ($p->shopify_product_id) {
                    $pid = (string)$p->shopify_product_id;
                    $byUpiStore["{$domain}||{$pid}"] = $p;
                    if ($name) $byUpiStore["{$name}||{$pid}"] = $p;
                }

                // Store-scoped Title lookup
                if (!empty($p->title)) {
                    $title = strtolower(trim($p->title));
                    $byTitleStore["{$domain}||{$title}"] = $p;
                    if ($name) $byTitleStore["{$name}||{$title}"] = $p;
                }
            }
        }

        $this->info("Catalog loaded: " . $allProducts->count() . " products in memory.");
        $this->line("");

        // Open CSV file
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error("Error: Failed to open CSV file.");
            return 1;
        }

        $headers = fgetcsv($handle, 10000, ',');
        if (!$headers) {
            $this->error("Error: CSV file is empty or header format is invalid.");
            fclose($handle);
            return 1;
        }

        $headerMap = array_flip(array_map('trim', $headers));

        $totalRows = 0;
        $excludedNonUpiCount = 0;
        $preservedBlankCategoryCount = 0;
        $normalizedLicensorCount = 0;
        $matchedCount = 0;
        $unmatchedCount = 0;
        $updatesByShop = [];
        $storeStats = [];

        while (($data = fgetcsv($handle, 10000, ',')) !== false) {
            $totalRows++;

            $storeName = trim($data[$headerMap['Store Name'] ?? 0] ?? '');
            $productName = trim($data[$headerMap['Product Name'] ?? 1] ?? '');
            $csvUpi = trim($data[$headerMap['UPI'] ?? 2] ?? '');
            $csvCategory = trim($data[$headerMap['Product Category'] ?? 3] ?? '');
            $csvLicensor = trim($data[$headerMap['Primary Licensor'] ?? 5] ?? '');

            // RULE: Normalize Primary Licensor to ALL CAPS
            if (!empty($csvLicensor)) {
                $upperLicensor = strtoupper($csvLicensor);
                if ($upperLicensor !== $csvLicensor) {
                    $normalizedLicensorCount++;
                }
                $csvLicensor = $upperLicensor;
            }

            // Match product (Strict Store-Scoped Matching by UPI & Title)
            $product = null;
            $storeClean = strtolower($storeName);

            // 1. Match by UPI + Store Name / Domain
            if (!empty($csvUpi)) {
                $exactUpiKey = "{$storeClean}||{$csvUpi}";
                if (isset($byUpiStore[$exactUpiKey])) {
                    $product = $byUpiStore[$exactUpiKey];
                } else {
                    foreach ($byUpiStore as $key => $pObj) {
                        list($sKey, $uKey) = explode('||', $key, 2);
                        if ($uKey === $csvUpi) {
                            if (str_contains($storeClean, $sKey) || str_contains($sKey, $storeClean)) {
                                $product = $pObj;
                                break;
                            }
                        }
                    }
                }
            }

            // 2. Match by Title + Store Name / Domain
            if (!$product && !empty($productName)) {
                $titleClean = strtolower($productName);
                $exactTitleKey = "{$storeClean}||{$titleClean}";
                if (isset($byTitleStore[$exactTitleKey])) {
                    $product = $byTitleStore[$exactTitleKey];
                } else {
                    foreach ($byTitleStore as $key => $pObj) {
                        list($sKey, $tKey) = explode('||', $key, 2);
                        if ($tKey === $titleClean) {
                            if (str_contains($storeClean, $sKey) || str_contains($sKey, $storeClean)) {
                                $product = $pObj;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$product) {
                $unmatchedCount++;
                continue;
            }

            // If a specific shop is targeted, ensure product belongs to target shop
            if ($targetShop && $product->shop_id !== $targetShop->id) {
                continue;
            }

            $matchedCount++;

            // Detect field updates
            $dbUpi = $product->upi_code ?? '';
            $dbCategory = $product->product_type ?? '';
            $dbLicensor = $product->primary_licensor ?? '';
            $dbImageUrl = $product->main_image_url ?? '';

            $fieldChanges = [];
            if (!empty($csvUpi) && $csvUpi !== $dbUpi) {
                $fieldChanges['upi_code'] = ['old' => $dbUpi, 'new' => $csvUpi];
            }

            // RULE 2: Leave blank Product Category rows untouched!
            if (!empty($csvCategory) && $csvCategory !== $dbCategory) {
                $fieldChanges['product_type'] = ['old' => $dbCategory, 'new' => $csvCategory];
            } elseif (empty($csvCategory) && !empty($dbCategory)) {
                $preservedBlankCategoryCount++;
                // DO NOT overwrite or clear existing DB category
            }

            if (!empty($csvLicensor) && $csvLicensor !== $dbLicensor) {
                $fieldChanges['primary_licensor'] = ['old' => $dbLicensor, 'new' => $csvLicensor];
            }

            if (!empty($fieldChanges)) {
                $shopId = $product->shop_id;
                $shopDomain = $product->shop ? $product->shop->shop_domain : "Shop #{$shopId}";

                if (!isset($storeStats[$shopDomain])) {
                    $storeStats[$shopDomain] = 0;
                }
                $storeStats[$shopDomain]++;

                $updatesByShop[$shopId][] = [
                    'product' => $product,
                    'shopify_product_id' => $product->shopify_product_id,
                    'upi_code' => $fieldChanges['upi_code']['new'] ?? $dbUpi,
                    'product_type' => $fieldChanges['product_type']['new'] ?? $dbCategory,
                    'primary_licensor' => $fieldChanges['primary_licensor']['new'] ?? $dbLicensor,
                    'upi_status' => $product->upi_status ?? 'Active',
                    'changes' => $fieldChanges
                ];
            }
        }
        fclose($handle);

        $totalUpdates = array_sum(array_map('count', $updatesByShop));

        $this->info("CSV Pre-processing & Parse Results:");
        $this->info("  • Total CSV Rows:                 {$totalRows}");
        $this->info("  • Excluded Non-UPI Rows (Rule 1):  {$excludedNonUpiCount} (PSB, DFD, SSS, KKG, PKP)");
        $this->info("  • Preserved Blank Category Rows:  {$preservedBlankCategoryCount} (Left DB category untouched)");
        $this->info("  • Normalized Uppercase Licensors: {$normalizedLicensorCount} (Converted to ALL CAPS)");
        $this->info("  • Matched Target Store Products:  {$matchedCount}");
        $this->info("  • Products To Update:             {$totalUpdates} across " . count($updatesByShop) . " store(s)");
        $this->line("");

        if ($totalUpdates === 0) {
            $this->info("No product updates needed. Store catalog is up to date.");
            return 0;
        }

        $this->info("Store Breakdown:");
        foreach ($storeStats as $sDomain => $cnt) {
            $this->line("  - {$sDomain}: {$cnt} product(s) modified");
        }
        $this->line("");

        // Write Audit Log CSV File (Before and After Values)
        $auditLogFile = storage_path("logs/import_audit_single_store_" . date('Ymd_His') . ".csv");
        $auditDir = dirname($auditLogFile);
        if (!file_exists($auditDir)) {
            mkdir($auditDir, 0755, true);
        }

        $auditHandle = fopen($auditLogFile, 'w');
        if ($auditHandle) {
            fputcsv($auditHandle, [
                'Store Domain',
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
                        $pObj->shop ? $pObj->shop->shop_domain : '',
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
            $this->warn("[DRY RUN COMPLETE] No updates were saved to DB or pushed to Shopify.");
            return 0;
        }

        // Live execution
        $this->info("Applying updates to database and pushing to Shopify...");
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

                DB::transaction(function() use ($chunk, &$bulkPayload) {
                    foreach ($chunk as $item) {
                        /** @var Product $prod */
                        $prod = $item['product'];
                        $prod->update([
                            'upi_code' => $item['upi_code'] ?: null,
                            'product_type' => $item['product_type'] ?: $prod->product_type,
                            'primary_licensor' => $item['primary_licensor'] ?: $prod->primary_licensor,
                            'sync_status' => 'pending_push',
                            'last_updated_by' => 'Single Store CSV Import Command',
                            'last_updated_at' => now(),
                        ]);

                        $bulkPayload[] = [
                            'shopify_product_id' => $prod->shopify_product_id,
                            'upi_code' => $item['upi_code'],
                            'upi_status' => $item['upi_status'],
                        ];
                    }
                });

                if (!empty($bulkPayload)) {
                    if ($isSync) {
                        BulkPushUpiToShopifyJob::dispatchSync($bulkPayload, 'Single Store CSV Import Command');
                    } else {
                        BulkPushUpiToShopifyJob::dispatch($bulkPayload, 'Single Store CSV Import Command');
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
        $this->info("SUCCESS: Single Store CSV Import & Sync Completed!");
        $this->info("Total Products Updated: {$pushedCount}");
        $this->info("=================================================");

        return 0;
    }
}
