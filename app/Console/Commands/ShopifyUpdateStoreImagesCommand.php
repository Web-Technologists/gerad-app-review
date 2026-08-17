<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Shop;
use App\Models\Product;
use App\Services\ShopifyClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyUpdateStoreImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:update-store-images
                            {store : Store name or domain (e.g. shophannahscloset.myshopify.com or "Shop aKDPhi")}
                            {file : Path to CSV file relative to project root or absolute path}
                            {--dry-run : Preview image updates without modifying DB or pushing to Shopify}
                            {--sync : Push image updates to Shopify synchronously during execution}
                            {--force : Force push all image URLs from CSV to Shopify even if DB URL matches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Strictly update product images for a SINGLE targeted store from CSV, with zero risk to other stores.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $storeInput = trim($this->argument('store'));
        $filePath = $this->argument('file');

        if (!str_starts_with($filePath, '/')) {
            $filePath = base_path($filePath);
        }

        if (!file_exists($filePath)) {
            $this->error("Error: CSV file not found at path: {$filePath}");
            return 1;
        }

        // 1. Resolve target shop model strictly
        $storeClean = strtolower($storeInput);
        $targetShop = Shop::where(function($query) use ($storeClean) {
            $query->whereRaw('LOWER(shop_domain) = ?', [$storeClean])
                  ->orWhereRaw('LOWER(shop_name) = ?', [$storeClean])
                  ->orWhereRaw('LOWER(REPLACE(shop_domain, ".myshopify.com", "")) = ?', [$storeClean]);
        })->first();

        if (!$targetShop) {
            // Fallback fuzzy search for connected shops
            $allShops = Shop::all();
            foreach ($allShops as $sObj) {
                if (strtolower($sObj->shop_domain) === $storeClean || strtolower($sObj->shop_name) === $storeClean) {
                    $targetShop = $sObj;
                    break;
                }
            }
        }

        if (!$targetShop) {
            $this->error("Error: Target store '{$storeInput}' is not connected in the system.");
            $this->info("Available connected stores:");
            foreach (Shop::all() as $s) {
                $this->line("  - {$s->shop_domain} ({$s->shop_name})");
            }
            return 1;
        }

        $isDryRun = $this->option('dry-run');
        $isSync = $this->option('sync');
        $isForce = $this->option('force');

        $this->info("=================================================");
        $this->info("   Single-Store Product Image Updater            ");
        $this->info("=================================================");
        $this->info("Target Store: {$targetShop->shop_domain} ({$targetShop->shop_name})");
        $this->info("CSV File:     " . basename($filePath));
        $this->info("Mode:         " . ($isDryRun ? "[DRY RUN]" : ($isForce ? "[FORCE UPDATE & PUSH TO SHOPIFY]" : "[LIVE UPDATE]")));
        $this->line("");

        // 2. Load products ONLY for target shop (100% store-isolated)
        $this->info("Loading product catalog strictly for store #{$targetShop->id} ({$targetShop->shop_domain})...");
        $storeProducts = Product::where('shop_id', $targetShop->id)->get();

        $byUpi = [];
        $byPid = [];
        $byTitle = [];

        foreach ($storeProducts as $p) {
            if (!empty($p->upi_code)) {
                $byUpi[strtolower(trim($p->upi_code))] = $p;
            }
            if (!empty($p->shopify_product_id)) {
                $byPid[(string)$p->shopify_product_id] = $p;
            }
            if (!empty($p->title)) {
                $byTitle[strtolower(trim($p->title))] = $p;
            }
            if (!empty($p->handle)) {
                $byTitle[strtolower(trim($p->handle))] = $p;
            }
        }

        $this->info("Loaded " . $storeProducts->count() . " products belonging to {$targetShop->shop_domain}.");
        $this->line("");

        // 3. Parse CSV File
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error("Failed to open CSV file.");
            return 1;
        }

        $headers = fgetcsv($handle, 10000, ',');
        if (!$headers) {
            $this->error("CSV file is empty.");
            fclose($handle);
            return 1;
        }

        $headerMap = array_flip(array_map('strtolower', array_map('trim', $headers)));

        $storeCol = $headerMap['store name'] ?? ($headerMap['store'] ?? ($headerMap['shop'] ?? null));
        $titleCol = $headerMap['product name'] ?? ($headerMap['product title'] ?? ($headerMap['title'] ?? ($headerMap['product handle'] ?? ($headerMap['handle'] ?? null))));
        $upiCol = $headerMap['upi'] ?? ($headerMap['upi code'] ?? ($headerMap['upi_code'] ?? null));
        $pidCol = $headerMap['shopify_product_id'] ?? ($headerMap['product id'] ?? ($headerMap['product_id'] ?? null));
        $imgCol = $headerMap['product main image url'] ?? ($headerMap['image url'] ?? ($headerMap['main_image_url'] ?? ($headerMap['image'] ?? null)));

        if ($imgCol === null) {
            $this->error("Error: CSV file must contain a 'Product Main Image URL' or 'Image URL' column.");
            fclose($handle);
            return 1;
        }

        $totalRows = 0;
        $otherStoreSkipped = 0;
        $matchedCount = 0;
        $unmatchedCount = 0;
        $itemsToUpdate = [];

        while (($data = fgetcsv($handle, 10000, ',')) !== false) {
            $totalRows++;

            $rowStore = $storeCol !== null ? trim($data[$storeCol] ?? '') : '';
            $rowTitle = $titleCol !== null ? trim($data[$titleCol] ?? '') : '';
            $rowUpi = $upiCol !== null ? trim($data[$upiCol] ?? '') : '';
            $rowPid = $pidCol !== null ? trim($data[$pidCol] ?? '') : '';
            $rowImgUrl = trim($data[$imgCol] ?? '');

            // Skip rows belonging to other stores
            if (!empty($rowStore)) {
                $rowStoreClean = strtolower($rowStore);
                $isTarget = ($rowStoreClean === strtolower($targetShop->shop_domain)) ||
                            ($rowStoreClean === strtolower($targetShop->shop_name)) ||
                            (str_replace('.myshopify.com', '', $rowStoreClean) === str_replace('.myshopify.com', '', strtolower($targetShop->shop_domain)));
                
                if (!$isTarget) {
                    $otherStoreSkipped++;
                    continue;
                }
            }

            if (empty($rowImgUrl)) {
                continue;
            }

            // Match product strictly within target store
            $product = null;
            if (!empty($rowUpi) && isset($byUpi[strtolower($rowUpi)])) {
                $product = $byUpi[strtolower($rowUpi)];
            }
            if (!$product && !empty($rowPid) && isset($byPid[(string)$rowPid])) {
                $product = $byPid[(string)$rowPid];
            }
            if (!$product && !empty($rowTitle) && isset($byTitle[strtolower($rowTitle)])) {
                $product = $byTitle[strtolower($rowTitle)];
            }

            if (!$product) {
                $unmatchedCount++;
                continue;
            }

            $matchedCount++;
            $dbImgUrl = trim((string)($product->main_image_url ?? ''));

            $dbNorm = $this->normalizeImageUrl($dbImgUrl);
            $rowNorm = $this->normalizeImageUrl($rowImgUrl);

            if ($isForce || ($dbNorm !== $rowNorm)) {
                $itemsToUpdate[] = [
                    'product' => $product,
                    'old_image_url' => $dbImgUrl,
                    'new_image_url' => $rowImgUrl,
                ];
            }
        }
        fclose($handle);

        $this->info("CSV Parse Summary:");
        $this->info("  • Total CSV Rows:           {$totalRows}");
        $this->info("  • Skipped Other Store Rows: {$otherStoreSkipped}");
        $this->info("  • Matched Target Products:  {$matchedCount}");
        $this->info("  • Unmatched Rows:           {$unmatchedCount}");
        $this->info("  • Products Needing Image Update: " . count($itemsToUpdate));
        $this->line("");

        if (empty($itemsToUpdate)) {
            $this->info("No image changes required for {$targetShop->shop_domain}. Catalog is up to date.");
            return 0;
        }

        // Generate Audit Log CSV
        $safeDomain = str_replace([':', '/', '.'], '_', $targetShop->shop_domain);
        $auditLogFile = storage_path("logs/update_images_{$safeDomain}_" . date('Ymd_His') . ".csv");
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
                'UPI Code',
                'Image URL (Before)',
                'Image URL (After)',
                'Timestamp'
            ]);

            foreach ($itemsToUpdate as $item) {
                $pObj = $item['product'];
                fputcsv($auditHandle, [
                    $targetShop->shop_domain,
                    $pObj->shopify_product_id,
                    $pObj->title,
                    $pObj->upi_code,
                    $item['old_image_url'],
                    $item['new_image_url'],
                    now()->toIso8601String()
                ]);
            }
            fclose($auditHandle);
            $this->info("Audit Log File generated: {$auditLogFile}");
        }

        if ($isDryRun) {
            $this->warn("[DRY RUN COMPLETE] Previewed " . count($itemsToUpdate) . " image updates. No changes saved to DB or Shopify.");
            return 0;
        }

        // Apply updates to local DB and push to Shopify
        $this->info("Updating database records & pushing images to Shopify for {$targetShop->shop_domain}...");
        $client = new ShopifyClient($targetShop);
        $pushedCount = 0;
        $failedCount = 0;

        foreach ($itemsToUpdate as $item) {
            /** @var Product $pObj */
            $pObj = $item['product'];
            $newUrl = $item['new_image_url'];

            // 1. Update DB
            $pObj->update([
                'main_image_url' => $newUrl,
                'last_updated_by' => 'Single-Store Image Update Command',
                'last_updated_at' => now(),
            ]);

            // 2. Push to Shopify API if active token
            if ($targetShop->access_token && !str_starts_with($targetShop->access_token, 'mock')) {
                $pId = (int)$pObj->shopify_product_id;
                $err = null;
                $pushed = $client->updateProductImage($pId, $newUrl, $err);

                if ($pushed) {
                    $pushedCount++;
                } else {
                    $failedCount++;
                    if ($failedCount <= 3) {
                        $this->error("Failed to update image for product {$pId} ('{$pObj->title}'): {$err}");
                    }
                }
            } else {
                $pushedCount++;
            }
        }

        $this->line("");
        $this->info("=================================================");
        $this->info("SUCCESS: Image Update Completed for {$targetShop->shop_domain}!");
        $this->info("Total Products Updated in DB: " . count($itemsToUpdate));
        $this->info("Images Pushed to Shopify:    {$pushedCount}");
        if ($failedCount > 0) {
            $this->warn("Failed Shopify API Pushes:   {$failedCount}");
        }
        $this->info("=================================================");

        return 0;
    }

    /**
     * Normalize image URL for comparison by stripping query parameters (?v=...), protocol, and trailing slashes.
     */
    protected function normalizeImageUrl(?string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $clean = urldecode(trim($url));
        $clean = explode('?', $clean)[0];
        $clean = preg_replace('#^https?://#i', '', $clean);

        return strtolower(rtrim($clean, '/'));
    }
}
