<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\SyncJob;
use App\Jobs\StoreProvisioningJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BulkOnboardStoresJob implements ShouldQueue
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
    public function handle(): void
    {
        $this->syncJob->update(['status' => 'processing']);

        $filePath = $this->syncJob->file_path;
        if (!Storage::exists($filePath)) {
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['file' => "Store CSV file not found at {$filePath}."]
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

        // Read header row
        $header = fgetcsv($file);
        if (!$header) {
            fclose($file);
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['file' => 'CSV file is empty.']
            ]);
            return;
        }

        // Normalize headers
        $headerMap = array_flip(array_map('strtolower', array_map('trim', $header)));

        $storeKey = isset($headerMap['store']) ? 'store' : (isset($headerMap['shop']) ? 'shop' : (isset($headerMap['domain']) ? 'domain' : null));
        $tokenKey = isset($headerMap['access token']) ? 'access token' : (isset($headerMap['token']) ? 'token' : (isset($headerMap['access_token']) ? 'access_token' : null));

        if (!$storeKey || !$tokenKey) {
            fclose($file);
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['headers' => 'Missing required columns. CSV must contain "Store" and "Access Token" headers.']
            ]);
            return;
        }

        $totalRows = 0;
        $processedRows = 0;
        $failedRows = 0;
        $errors = [];

        // Count rows
        while (fgetcsv($file)) {
            $totalRows++;
        }
        $this->syncJob->update(['total_rows' => $totalRows]);

        // Reset pointer
        fseek($file, 0);
        fgetcsv($file); // Skip header

        $rowNumber = 1;
        while (($row = fgetcsv($file)) !== false) {
            $rowNumber++;

            $storeDomain = isset($headerMap[$storeKey]) ? trim($row[$headerMap[$storeKey]] ?? '') : '';
            $accessToken = isset($headerMap[$tokenKey]) ? trim($row[$headerMap[$tokenKey]] ?? '') : '';

            if (empty($storeDomain)) {
                $failedRows++;
                $errors[] = "Row {$rowNumber}: Missing 'Store' value.";
                continue;
            }

            if (empty($accessToken)) {
                $failedRows++;
                $errors[] = "Row {$rowNumber}: Missing 'Access Token' value for store '{$storeDomain}'.";
                continue;
            }

            // Append domain extension if missing
            $storeDomain = strtolower($storeDomain);
            if (!Str::contains($storeDomain, '.')) {
                $storeDomain .= '.myshopify.com';
            }

            // Validate domain regex
            if (!preg_match('/^[a-zA-Z0-9.-]+\.myshopify\.com$/', $storeDomain)) {
                $failedRows++;
                $errors[] = "Row {$rowNumber}: Invalid store domain format: '{$storeDomain}'.";
                continue;
            }

            try {
                // Create or update Shop model
                $shop = Shop::updateOrCreate(
                    ['shop_domain' => $storeDomain],
                    [
                        'access_token' => $accessToken,
                        'scopes' => ['read_products', 'write_products'],
                        'status' => 'active',
                    ]
                );

                // Dispatch provisioning chain
                StoreProvisioningJob::dispatch($shop, false);

                $processedRows++;
            } catch (\Exception $e) {
                $failedRows++;
                $errors[] = "Row {$rowNumber}: Error onboarding '{$storeDomain}': " . $e->getMessage();
            }

            // Periodically log updates
            if ($rowNumber % 50 === 0) {
                $this->syncJob->update([
                    'processed_rows' => $processedRows,
                    'failed_rows' => $failedRows,
                    'error_log' => count($errors) > 0 ? array_slice($errors, 0, 100) : null,
                ]);
            }
        }

        fclose($file);

        // Update completion status
        $this->syncJob->update([
            'status' => $failedRows === $totalRows ? 'failed' : 'completed',
            'processed_rows' => $processedRows,
            'failed_rows' => $failedRows,
            'error_log' => count($errors) > 0 ? $errors : null,
        ]);

        Log::info("BulkOnboardStoresJob: Finished. Total: {$totalRows}, Processed: {$processedRows}, Failed: {$failedRows}");
    }
}
