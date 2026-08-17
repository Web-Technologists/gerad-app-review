<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Models\Product;
use App\Models\Shop;
use App\Services\ProductSyncService;
use App\Services\LicensorDetectionService;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BulkImportCsvJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected SyncJob $syncJob;
    protected ?array $targetShopIds = null;

    /**
     * Create a new job instance.
     */
    public function __construct(SyncJob $syncJob, ?array $targetShopIds = null)
    {
        $this->syncJob = $syncJob;
        $this->targetShopIds = $targetShopIds;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ShopRepositoryInterface $shopRepository,
        ProductRepositoryInterface $productRepository,
        ProductSyncService $syncService
    ): void {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $this->syncJob->update(['status' => 'processing']);

        $filePath = $this->syncJob->file_path;
        if (!Storage::exists($filePath)) {
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['file' => "CSV file not found at {$filePath}."]
            ]);
            return;
        }

        $fullPath = Storage::path($filePath);
        $file = fopen($fullPath, 'r');

        if (!$file) {
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['file' => 'Could not open CSV file for reading.']
            ]);
            return;
        }

        // 1. Read header row
        $header = fgetcsv($file);
        if (!$header) {
            fclose($file);
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['file' => 'CSV file is empty.']
            ]);
            return;
        }

        // Map headers to indexes (case-insensitive and trimmed)
        $headerMap = array_flip(array_map('strtolower', array_map('trim', $header)));

        $storeKey = isset($headerMap['store name']) ? 'store name' : (isset($headerMap['store']) ? 'store' : (isset($headerMap['shop']) ? 'shop' : null));
        $titleKey = isset($headerMap['product name']) ? 'product name' : (isset($headerMap['product title']) ? 'product title' : (isset($headerMap['title']) ? 'title' : (isset($headerMap['product handle']) ? 'product handle' : (isset($headerMap['handle']) ? 'handle' : null))));
        $idKey = isset($headerMap['shopify_product_id']) ? 'shopify_product_id' : (isset($headerMap['product id']) ? 'product id' : (isset($headerMap['product_id']) ? 'product_id' : null));
        $upiKey = isset($headerMap['upi']) ? 'upi' : (isset($headerMap['upi code']) ? 'upi code' : (isset($headerMap['upi_code']) ? 'upi_code' : null));
        $categoryKey = isset($headerMap['product category']) ? 'product category' : (isset($headerMap['product type']) ? 'product type' : (isset($headerMap['item category']) ? 'item category' : (isset($headerMap['item_category']) ? 'item_category' : (isset($headerMap['category']) ? 'category' : null))));
        $licensorKey = isset($headerMap['primary licensor']) ? 'primary licensor' : (isset($headerMap['licensor']) ? 'licensor' : null);

        // Pre-load products into store-scoped lookup maps
        $shopIdFilter = [];
        if (!empty($this->targetShopIds)) {
            $shopIdFilter = array_filter(array_map('intval', $this->targetShopIds));
        } elseif (!empty($this->syncJob->shop_id)) {
            $shopIdFilter = [(int)$this->syncJob->shop_id];
        }

        $query = Product::with('shop');
        if (!empty($shopIdFilter)) {
            $query->whereIn('shop_id', $shopIdFilter);
        }
        $allProducts = $query->get();

        // Build strict shop map & product lookups from database
        $allShops = \App\Models\Shop::all();
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

        $query = Product::with('shop');
        if (!empty($shopIdFilter)) {
            $query->whereIn('shop_id', $shopIdFilter);
        }
        $allProducts = $query->get();

        $byShopAndUpi = [];
        $byShopAndTitle = [];

        foreach ($allProducts as $p) {
            if (!$p->shop_id) continue;
            $sId = (string)$p->shop_id;

            $domain = $p->shop ? strtolower(trim($p->shop->shop_domain)) : '';
            $name = ($p->shop && !empty($p->shop->shop_name)) ? strtolower(trim($p->shop->shop_name)) : '';

            $keys = array_filter([$sId, $domain, $name]);

            if (!empty($p->upi_code)) {
                $upi = trim($p->upi_code);
                foreach ($keys as $k) {
                    $byShopAndUpi["{$k}||{$upi}"] = $p;
                }
            }
            if ($p->shopify_product_id) {
                $pid = (string)$p->shopify_product_id;
                foreach ($keys as $k) {
                    $byShopAndUpi["{$k}||{$pid}"] = $p;
                }
            }
            if (!empty($p->title)) {
                $title = strtolower(trim($p->title));
                foreach ($keys as $k) {
                    $byShopAndTitle["{$k}||{$title}"] = $p;
                }
            }
            if (!empty($p->handle)) {
                $handle = strtolower(trim($p->handle));
                foreach ($keys as $k) {
                    $byShopAndTitle["{$k}||{$handle}"] = $p;
                }
            }
        }

        // Create Audit Log CSV File
        $auditFileName = "imports/audit/import_audit_job_{$this->syncJob->id}_" . date('Ymd_His') . ".csv";
        $auditFullPath = Storage::path($auditFileName);
        $auditDirectory = dirname($auditFullPath);
        if (!file_exists($auditDirectory)) {
            mkdir($auditDirectory, 0755, true);
        }

        $auditHandle = fopen($auditFullPath, 'w');
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
                'UPI Status (Before)',
                'UPI Status (After)',
                'Timestamp'
            ]);
        }

        $processedRows = 0;
        $failedRows = 0;
        $changedProductsCount = 0;
        $preservedBlankCategory = 0;
        $normalizedLicensorCount = 0;
        $errors = [];
        $rowNumber = 1;
        $chunkSize = 250;
        $updatesByShop = [];

        while (($row = fgetcsv($file)) !== false) {
            $rowNumber++;

            $storeName = $storeKey !== null ? trim($row[$headerMap[$storeKey]] ?? '') : '';
            $productName = $titleKey !== null ? trim($row[$headerMap[$titleKey]] ?? '') : '';
            $productIdStr = $idKey !== null ? trim($row[$headerMap[$idKey]] ?? '') : '';
            $csvUpi = $upiKey !== null ? trim($row[$headerMap[$upiKey]] ?? '') : '';
            $csvCategory = $categoryKey !== null ? trim($row[$headerMap[$categoryKey]] ?? '') : '';
            $csvLicensor = $licensorKey !== null ? trim($row[$headerMap[$licensorKey]] ?? '') : '';

            // RULE: Normalize Primary Licensor to ALL CAPS
            if (!empty($csvLicensor)) {
                $upperLicensor = strtoupper($csvLicensor);
                if ($upperLicensor !== $csvLicensor) {
                    $normalizedLicensorCount++;
                }
                $csvLicensor = $upperLicensor;
            }

            // Validate store connection
            $targetShop = null;
            if (!empty($storeName)) {
                $storeClean = strtolower($storeName);
                $targetShop = $shopMap[$storeClean] ?? null;

                if (!$targetShop) {
                    foreach ($allShops as $sObj) {
                        if (strtolower($sObj->shop_domain) === $storeClean || strtolower($sObj->shop_name) === $storeClean) {
                            $targetShop = $sObj;
                            break;
                        }
                    }
                }

                if (!$targetShop) {
                    $failedRows++;
                    $errors[] = "Row {$rowNumber}: Shopify Store '{$storeName}' is not connected in this central system.";
                    continue;
                }
            }

            // Strict Store-Scoped Product Lookup by targetShop->id
            $product = null;
            $targetShopId = $targetShop ? $targetShop->id : ($this->syncJob->shop_id ?? (!empty($shopIdFilter) ? $shopIdFilter[0] : null));

            if ($targetShopId) {
                // 1. Try match by UPI / Product ID + Store ID
                if (!empty($csvUpi)) {
                    $lookupUpiKey = "{$targetShopId}||{$csvUpi}";
                    if (isset($byShopAndUpi[$lookupUpiKey])) {
                        $product = $byShopAndUpi[$lookupUpiKey];
                    }
                }

                if (!$product && !empty($productIdStr)) {
                    $lookupPidKey = "{$targetShopId}||{$productIdStr}";
                    if (isset($byShopAndUpi[$lookupPidKey])) {
                        $product = $byShopAndUpi[$lookupPidKey];
                    }
                }

                // 2. Fallback: Try match by Title / Handle + Store ID
                if (!$product && !empty($productName)) {
                    $titleClean = strtolower($productName);
                    $lookupTitleKey = "{$targetShopId}||{$titleClean}";
                    if (isset($byShopAndTitle[$lookupTitleKey])) {
                        $product = $byShopAndTitle[$lookupTitleKey];
                    }
                }
            } else {
                // Global lookup if no target shop ID is bound
                if (!empty($csvUpi)) {
                    foreach ($byShopAndUpi as $k => $pObj) {
                        if (str_ends_with($k, "||{$csvUpi}")) {
                            $product = $pObj;
                            break;
                        }
                    }
                }
                if (!$product && !empty($productIdStr)) {
                    foreach ($byShopAndUpi as $k => $pObj) {
                        if (str_ends_with($k, "||{$productIdStr}")) {
                            $product = $pObj;
                            break;
                        }
                    }
                }
                if (!$product && !empty($productName)) {
                    $titleClean = strtolower($productName);
                    foreach ($byShopAndTitle as $k => $pObj) {
                        if (str_ends_with($k, "||{$titleClean}")) {
                            $product = $pObj;
                            break;
                        }
                    }
                }
            }

            if (!$product) {
                $failedRows++;
                $identifier = !empty($productIdStr) ? "Product ID '{$productIdStr}'" : (!empty($csvUpi) ? "UPI '{$csvUpi}'" : (!empty($productName) ? "Title '{$productName}'" : "Row {$rowNumber}"));
                $errors[] = "Row {$rowNumber}: {$identifier} not found in store '{$storeName}'.";
                continue;
            }

            // Determine field updates
            $finalUpi = !empty($csvUpi) ? $csvUpi : $product->upi_code;
            
            // RULE 2: Leave blank Product Category untouched
            if (!empty($csvCategory)) {
                $finalCategory = $csvCategory;
            } else {
                $finalCategory = $product->product_type;
                if (empty($csvCategory) && !empty($product->product_type)) {
                    $preservedBlankCategory++;
                }
            }

            $finalLicensor = !empty($csvLicensor) ? $csvLicensor : $product->primary_licensor;
            $finalStatus = !empty($finalUpi) ? 'Active' : ($product->upi_status ?? 'Active');

            // Audit Compare Before & After
            $beforeUpi = (string)($product->upi_code ?? '');
            $afterUpi = (string)($finalUpi ?? '');

            $beforeCategory = (string)($product->item_category ?: ($product->product_type ?? ''));
            $afterCategory = (string)($finalCategory ?? '');

            $beforeLicensor = (string)($product->primary_licensor ?? '');
            $afterLicensor = (string)($finalLicensor ?? '');

            $beforeStatus = (string)($product->upi_status ?? '');
            $afterStatus = (string)($finalStatus ?? '');

            $changedFields = [];
            if ($beforeUpi !== $afterUpi) $changedFields[] = 'UPI Code';
            if ($beforeCategory !== $afterCategory) $changedFields[] = 'Category';
            if ($beforeLicensor !== $afterLicensor) $changedFields[] = 'Primary Licensor';
            if ($beforeStatus !== $afterStatus) $changedFields[] = 'UPI Status';

            if (!empty($changedFields) && $auditHandle) {
                fputcsv($auditHandle, [
                    $product->shop ? ($product->shop->shop_name ?: $product->shop->shop_domain) : $storeName,
                    $product->shopify_product_id ?? '',
                    $product->title ?? '',
                    implode(', ', $changedFields),
                    $beforeUpi,
                    $afterUpi,
                    $beforeCategory,
                    $afterCategory,
                    $beforeLicensor,
                    $afterLicensor,
                    $beforeStatus,
                    $afterStatus,
                    now()->toIso8601String(),
                ]);
                $changedProductsCount++;
            }

            $updatesByShop[$product->shop_id][] = [
                'product' => $product,
                'upi_code' => $finalUpi,
                'upi_status' => $finalStatus,
                'item_category' => $finalCategory,
                'primary_licensor' => $finalLicensor,
            ];
            $processedRows++;

            // Flush chunk if it reaches chunk size
            if ($processedRows % $chunkSize === 0) {
                $this->flushImportChunks($updatesByShop, $shopRepository);
                $updatesByShop = [];

                $this->syncJob->update([
                    'processed_rows' => $processedRows,
                    'failed_rows' => $failedRows,
                    'error_log' => !empty($errors) ? array_slice($errors, 0, 50) : null,
                ]);
                gc_collect_cycles();
            }
        }

        fclose($file);
        if ($auditHandle) {
            fclose($auditHandle);
        }

        // Flush remaining chunks
        if (!empty($updatesByShop)) {
            $this->flushImportChunks($updatesByShop, $shopRepository);
        }

        $status = 'completed';
        if ($processedRows === 0 && $failedRows > 0) {
            $status = 'failed';
        }

        $summaryLog = [
            'total_rows_processed' => $processedRows + $failedRows,
            'successful_rows' => $processedRows,
            'changed_products_count' => $changedProductsCount,
            'failed_rows' => $failedRows,
            'preserved_blank_category_rows' => $preservedBlankCategory,
            'normalized_uppercase_licensor_rows' => $normalizedLicensorCount,
            'audit_file_path' => $auditFileName,
            'sample_errors' => array_slice($errors, 0, 50),
        ];

        $this->syncJob->update([
            'status' => $status,
            'processed_rows' => $processedRows,
            'failed_rows' => $failedRows,
            'file_path' => $auditFileName,
            'error_log' => $summaryLog,
        ]);

        Log::info("BulkImportCsvJob: Processing finished. Total: " . ($processedRows + $failedRows) . ", Success: {$processedRows}, Changed: {$changedProductsCount}, Audit Log: {$auditFileName}");
    }

    /**
     * Helper to flush a chunk of imported records.
     */
    protected function flushImportChunks(array $updatesByShop, ShopRepositoryInterface $shopRepository): void
    {
        foreach ($updatesByShop as $shopId => $items) {
            $shop = $shopRepository->find($shopId);
            if (!$shop) {
                continue;
            }

            // Update locally in bulk transaction
            DB::transaction(function() use ($items) {
                foreach ($items as $item) {
                    $item['product']->update([
                        'upi_code' => $item['upi_code'] ?: null,
                        'upi_status' => $item['upi_status'] ?: null,
                        'item_category' => $item['item_category'] ?: null,
                        'product_type' => $item['item_category'] ?: $item['product']->product_type,
                        'primary_licensor' => $item['primary_licensor'] ?: $item['product']->primary_licensor,
                        'last_updated_by' => 'CSV Dashboard Bulk Import',
                        'last_updated_at' => now(),
                        'sync_status' => 'pending_push',
                    ]);
                }
            });

            // Dispatch BulkPushUpiToShopifyJob for this shop
            $jobUpdates = [];
            foreach ($items as $item) {
                $jobUpdates[] = [
                    'shopify_product_id' => $item['product']->shopify_product_id,
                    'upi_code' => $item['upi_code'],
                    'upi_status' => $item['upi_status'],
                ];
            }

            BulkPushUpiToShopifyJob::dispatch($jobUpdates, 'CSV Dashboard Bulk Import');
        }
    }
}