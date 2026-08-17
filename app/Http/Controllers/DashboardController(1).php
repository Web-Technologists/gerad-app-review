<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductSyncService;
use App\Models\SyncJob;
use App\Jobs\BulkImportCsvJob;
use App\Jobs\BulkExportCsvJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    protected ShopRepositoryInterface $shopRepository;
    protected ProductRepositoryInterface $productRepository;
    protected ProductSyncService $syncService;

    public function __construct(
        ShopRepositoryInterface $shopRepository,
        ProductRepositoryInterface $productRepository,
        ProductSyncService $syncService
    ) {
        $this->shopRepository = $shopRepository;
        $this->productRepository = $productRepository;
        $this->syncService = $syncService;
    }

    /**
     * Show the main products dashboard.
     * Route: GET /dashboard
     */
    public function index(Request $request)
    {
        // 1. Fetch dropdown options via models or repositories
        $shops = \App\Models\Shop::orderBy('shop_domain')->get();
        $vendors = \App\Models\Product::distinct()->orderBy('vendor')->pluck('vendor')->filter()->values();
        $productTypes = \App\Models\Product::distinct()->orderBy('product_type')->pluck('product_type')->filter()->values();

        // 2. Query products using the Product Repository
        $filters = $request->only(['shop_id', 'vendor', 'product_type', 'status', 'search']);
        $products = $this->productRepository->getFilteredProductsQuery($filters)
            ->orderBy('updated_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        // Fetch recent active CSV sync jobs
        $recentJobs = SyncJob::with('shop')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('dashboard', compact('products', 'shops', 'vendors', 'productTypes', 'recentJobs'));
    }

    /**
     * Update individual product's UPI code.
     * Route: POST /dashboard/products/{id}/update-upi
     */
    public function updateUpi(Request $request, $id)
    {
        $request->validate([
            'upi_code' => 'nullable|string|min:4|max:15|regex:/^[a-zA-Z0-9]+$/',
        ]);

        $product = \App\Models\Product::findOrFail($id);
        $upiCode = $request->input('upi_code');

        if ($product->upi_code === $upiCode) {
            return response()->json([
                'success' => true,
                'message' => 'UPI code is unchanged.',
                'product' => $product
            ]);
        }

        // Trigger UPI sync using the ProductSyncService
        $this->syncService->triggerLocalUpiUpdate($product, $upiCode);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'UPI code updated, syncing with Shopify.',
                'product' => $product
            ]);
        }

        return redirect()->back()->with('success', 'UPI code updated. Syncing with Shopify.');
    }

    /**
     * Start a CSV Bulk Import.
     * Route: POST /dashboard/import-csv
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            'shop_id' => 'nullable|exists:shops,id',
        ]);

        $file = $request->file('csv_file');
        $path = $file->store('imports');

        // Create SyncJob record
        $syncJob = SyncJob::create([
            'shop_id' => $request->input('shop_id'),
            'type' => 'csv_import',
            'status' => 'pending',
            'file_path' => $path,
        ]);

        // Dispatch background job
        BulkImportCsvJob::dispatch($syncJob);

        return redirect()->back()->with('success', 'CSV Import started in background.');
    }

    /**
     * Start a CSV Bulk Export.
     * Route: GET /dashboard/export-csv
     */
    public function exportCsv(Request $request)
    {
        $filters = $request->only(['shop_id', 'vendor', 'product_type', 'status', 'search']);

        if (!$request->wantsJson()) {
            $query = \App\Models\Product::with('shop');

            if (!empty($filters['shop_id'])) {
                $query->where('shop_id', $filters['shop_id']);
            }
            if (!empty($filters['vendor'])) {
                $query->where('vendor', $filters['vendor']);
            }
            if (!empty($filters['product_type'])) {
                $query->where('product_type', $filters['product_type']);
            }
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('upi_code', 'like', "%{$search}%");
                });
            }

            $products = $query->get();

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="upi_export_' . now()->format('Ymd_His') . '.csv"',
            ];

            $callback = function() use ($products) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Store', 'Product Title', 'Product ID', 'Vendor', 'Product Type', 'UPI Code', 'Item Category', 'Updated Date']);
                foreach ($products as $product) {
                    fputcsv($file, [
                        $product->shop->shop_name ?: ($product->shop->shop_domain ?? ''),
                        $product->title ?? '',
                        $product->shopify_product_id ?? '',
                        $product->vendor ?? '',
                        $product->product_type ?? '',
                        $product->upi_code ?? '',
                        $product->item_category ?? '',
                        $product->last_updated_at ? $product->last_updated_at->toIso8601String() : ($product->updated_at ? $product->updated_at->toIso8601String() : ''),
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        // Create SyncJob record for JSON requests (background tracking/tests)
        $syncJob = SyncJob::create([
            'shop_id' => $request->input('shop_id'),
            'type' => 'csv_export',
            'status' => 'pending',
        ]);

        BulkExportCsvJob::dispatch($syncJob, $filters);

        return response()->json([
            'success' => true,
            'job_id' => $syncJob->id,
            'message' => 'Export job started.'
        ]);
    }

    /**
     * Start a Licensing Export CSV for all products of all stores.
     * Route: GET /dashboard/licensing-export
     */
    public function licensingExport(Request $request)
    {
        if (!$request->wantsJson()) {
            $products = \App\Models\Product::with('shop')->get();

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="licensing_export_' . now()->format('Ymd_His') . '.csv"',
            ];

            $callback = function() use ($products) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Store Name', 'Product Name', 'UPI', 'Product Category', 'Product Main Image URL', 'Primary Licensor']);
                foreach ($products as $product) {
                    $licensor = \App\Services\LicensorDetectionService::resolveProductPrimaryLicensor($product);

                    fputcsv($file, [
                        $product->shop->shop_name ?: ($product->shop->shop_domain ?? ''),
                        $product->title ?? '',
                        $product->upi_code ?? '',
                        $product->product_type ?? '',
                        $product->main_image_url ?? '',
                        $licensor,
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        // Create SyncJob record for JSON requests (API/tests)
        $syncJob = SyncJob::create([
            'shop_id' => null,
            'type' => 'licensing_export',
            'status' => 'pending',
        ]);

        // Dispatch background job
        BulkExportCsvJob::dispatch($syncJob, ['licensing' => true]);

        return response()->json([
            'success' => true,
            'job_id' => $syncJob->id,
            'message' => 'Licensing export job started.'
        ]);
    }

    /**
     * Poll progress or download file for a SyncJob.
     * Route: GET /dashboard/job-status/{job_id}
     */
    public function jobStatus($job_id)
    {
        $job = SyncJob::findOrFail($job_id);
        
        $downloadUrl = null;
        if ($job->status === 'completed' && in_array($job->type, ['csv_export', 'licensing_export']) && $job->file_path) {
            $params = ['job_id' => $job->id] + request()->only(['shop', 'shop_id']);
            $downloadUrl = request()->routeIs('admin.*')
                ? route('admin.dashboard.download_export', $params)
                : route('dashboard.download_export', $params);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'type' => $job->type,
            'total_rows' => $job->total_rows,
            'processed_rows' => $job->processed_rows,
            'failed_rows' => $job->failed_rows,
            'error_log' => $job->error_log,
            'download_url' => $downloadUrl,
            'updated_at' => $job->updated_at->toDateTimeString(),
        ]);
    }

    /**
     * Download completed export file.
     * Route: GET /dashboard/download-export/{job_id}
     */
    public function downloadExport($job_id)
    {
        $job = SyncJob::findOrFail($job_id);

        if (!in_array($job->type, ['csv_export', 'licensing_export']) || $job->status !== 'completed' || !$job->file_path) {
            abort(404, 'File not available.');
        }

        if (!Storage::exists($job->file_path)) {
            abort(404, 'Export file expired.');
        }

        return Storage::download($job->file_path, basename($job->file_path));
    }
}
