<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BulkExportCsvJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected SyncJob $syncJob;
    protected array $filters;

    /**
     * Create a new job instance.
     */
    public function __construct(SyncJob $syncJob, array $filters = [])
    {
        $this->syncJob = $syncJob;
        $this->filters = $filters;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->syncJob->update(['status' => 'processing']);

        try {
            $isLicensing = ($this->syncJob->type === 'licensing_export');

            if ($isLicensing) {
                $products = Product::with('shop')->get();

                $csvData = [];
                // Header: Store Name, Product Name, UPI, Product Category, Product Main Image URL, Primary Licensor
                $csvData[] = ['Store Name', 'Product Name', 'UPI', 'Product Category', 'Product Main Image URL', 'Primary Licensor'];

                foreach ($products as $product) {
                    $licensor = \App\Services\LicensorDetectionService::resolveProductPrimaryLicensor($product);

                    $csvData[] = [
                        $product->shop->shop_name ?: ($product->shop->shop_domain ?? ''),
                        $product->title ?? '',
                        $product->upi_code ?? '',
                        $product->product_type ?? '',
                        $product->main_image_url ?? '',
                        $licensor,
                    ];
                }
            } else {
                $query = Product::with('shop');

                if (!empty($this->filters['shop_id'])) {
                    $query->where('shop_id', $this->filters['shop_id']);
                }
                if (!empty($this->filters['vendor'])) {
                    $query->where('vendor', $this->filters['vendor']);
                }
                if (!empty($this->filters['product_type'])) {
                    $query->where('product_type', $this->filters['product_type']);
                }
                if (!empty($this->filters['search'])) {
                    $search = $this->filters['search'];
                    $query->where(function($q) use ($search) {
                        $q->where('title', 'like', "%{$search}%")
                          ->orWhere('upi_code', 'like', "%{$search}%");
                    });
                }

                $products = $query->get();

                $csvData = [];
                // Header
                $csvData[] = ['Store', 'Product Title', 'Product ID', 'Vendor', 'Product Type', 'UPI Code', 'Item Category', 'Updated Date'];

                foreach ($products as $product) {
                    $csvData[] = [
                        $product->shop->shop_name ?: ($product->shop->shop_domain ?? ''),
                        $product->title ?? '',
                        $product->shopify_product_id ?? '',
                        $product->vendor ?? '',
                        $product->product_type ?? '',
                        $product->upi_code ?? '',
                        $product->item_category ?? '',
                        $product->last_updated_at ? $product->last_updated_at->toIso8601String() : ($product->updated_at ? $product->updated_at->toIso8601String() : ''),
                    ];
                }
            }

            $totalRows = count($products);
            $this->syncJob->update([
                'total_rows' => $totalRows,
                'processed_rows' => $totalRows,
            ]);

            // Write CSV to a string buffer
            $handle = fopen('php://temp', 'r+');
            foreach ($csvData as $row) {
                fputcsv($handle, $row);
            }
            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);

            // Save to storage
            $prefix = $isLicensing ? 'exports/licensing_export_' : 'exports/upi_export_';
            $fileName = $prefix . time() . '_' . uniqid() . '.csv';
            Storage::put($fileName, $csvContent);

            $this->syncJob->update([
                'status' => 'completed',
                'file_path' => $fileName,
            ]);

        } catch (\Exception $e) {
            Log::error("BulkExportCsvJob failed: " . $e->getMessage());
            $this->syncJob->update([
                'status' => 'failed',
                'error_log' => ['exception' => $e->getMessage()],
            ]);
        }
    }
}