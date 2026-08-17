<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Models\Product;
use App\Services\ProductSyncService;
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

    /**
     * Create a new job instance.
     */
    public function __construct(SyncJob $syncJob)
    {
        $this->syncJob = $syncJob;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ShopRepositoryInterface $shopRepository,
        ProductRepositoryInterface $productRepository,
        ProductSyncService $syncService
    ): void {
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

        $storeKey = isset($headerMap['store']) ? 'store' : (isset($headerMap['shop']) ? 'shop' : null);
        $idKey = isset($headerMap['shopify_product_id']) ? 'shopify_product_id' : (isset($headerMap['product id']) ? 'product id' : (isset($headerMap['product_id']) ? 'product_id' : null));
        $handleKey = isset($headerMap['product handle']) ? 'product handle' : (isset($headerMap['handle']) ? 'handle' : null);
        $upiKey = isset($headerMap['upi code']) ? 'upi code' : (isset($headerMap['upi_code']) ? 'upi_code' : (isset($headerMap['upi']) ? 'upi' : null));
        $categoryKey = isset($headerMap['item category']) ? 'item category' : (isset($headerMap['item_category']) ? 'item_category' : (isset($headerMap['category']) ? 'category' : null));

        if ($storeKey === null || ($idKey === null && $handleKey === null)) {
            fclose($file);
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['file' => 'Required CSV columns are missing. Must include Store, and either Product ID or Product Handle.']
            ]);
            return;
        }

        $processedRows = 0;
        $failedRows = 0;
        $errors = [];
        $rowNumber = 1; // header row is 1
        $chunkSize = 100;
        $updatesByShop = [];

        while (($row = fgetcsv($file)) !== false) {
            $rowNumber++;

            // Extract values
            $storeName = trim($row[$headerMap[$storeKey]] ?? '');
            $productIdStr = $idKey !== null ? trim($row[$headerMap[$idKey]] ?? '') : '';
            $productHandle = $handleKey !== null ? trim($row[$headerMap[$handleKey]] ?? '') : '';
            $upiCode = $upiKey !== null ? trim($row[$headerMap[$upiKey]] ?? '') : '';
            $itemCategory = $categoryKey !== null ? trim($row[$headerMap[$categoryKey]] ?? '') : '';

            if (empty($storeName)) {
                $failedRows++;
                $errors[] = "Row {$rowNumber}: Store domain is empty.";
                continue;
            }

            // Lookup store
            $shop = $shopRepository->findByDomain($storeName);
            if (!$shop && !str_contains($storeName, '.myshopify.com')) {
                $shop = $shopRepository->findByDomain($storeName . '.myshopify.com');
            }

            if (!$shop) {
                $failedRows++;
                $errors[] = "Row {$rowNumber}: Shopify Store '{$storeName}' is not connected in this central system.";
                continue;
            }

            // Lookup product
            $product = null;
            if (!empty($productIdStr)) {
                $product = Product::where('shop_id', $shop->id)
                    ->where('shopify_product_id', (int)$productIdStr)
                    ->first();
            }

            // Fallback search by handle
            if (!$product && !empty($productHandle)) {
                $product = Product::where('shop_id', $shop->id)
                    ->where('handle', $productHandle)
                    ->first();
            }

            if (!$product) {
                $failedRows++;
                $identifier = !empty($productIdStr) ? "Product ID '{$productIdStr}'" : "Product Handle '{$productHandle}'";
                $errors[] = "Row {$rowNumber}: {$identifier} not found in store '{$shop->shop_domain}'.";
                continue;
            }

            // Validate UPI format if provided in CSV
            if (!empty($upiCode)) {
                if (!preg_match('/^[a-zA-Z0-9]+$/', $upiCode) || strlen($upiCode) < 4 || strlen($upiCode) > 15) {
                    $failedRows++;
                    $errors[] = "Row {$rowNumber}: Invalid UPI Code format '{$upiCode}'. Must be 4-15 alphanumeric characters.";
                    continue;
                }
            }

            // Queue up the local update
            $updatesByShop[$shop->id][] = [
                'product' => $product,
                'upi_code' => !empty($upiCode) ? $upiCode : $product->upi_code,
                'upi_status' => !empty($upiCode) ? 'Active' : $product->upi_status,
                'item_category' => !empty($itemCategory) ? $itemCategory : $product->item_category,
            ];
            $processedRows++;

            // Flush chunk if it reaches chunk size
            if ($processedRows % $chunkSize === 0) {
                $this->flushImportChunks($updatesByShop, $shopRepository);
                $updatesByShop = [];

                $this->syncJob->update([
                    'processed_rows' => $processedRows,
                    'failed_rows' => $failedRows,
                    'error_log' => !empty($errors) ? $errors : null,
                ]);
            }
        }

        fclose($file);

        // Flush remaining chunks
        if (!empty($updatesByShop)) {
            $this->flushImportChunks($updatesByShop, $shopRepository);
        }

        $status = 'completed';
        if ($processedRows === 0 && $failedRows > 0) {
            $status = 'failed';
        }

        $this->syncJob->update([
            'status' => $status,
            'processed_rows' => $processedRows,
            'failed_rows' => $failedRows,
            'error_log' => !empty($errors) ? $errors : null,
        ]);

        Log::info("BulkImportCsvJob: Processing finished. Total: " . ($processedRows + $failedRows) . ", Success: {$processedRows}, Failures: {$failedRows}");
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
                        'last_updated_by' => 'CSV Bulk Import',
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

            BulkPushUpiToShopifyJob::dispatch($jobUpdates, 'CSV Bulk Import');
        }
    }
}