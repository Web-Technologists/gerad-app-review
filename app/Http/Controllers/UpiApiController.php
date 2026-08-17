<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductSyncService;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UpiApiController extends Controller
{
    protected ProductRepositoryInterface $productRepository;
    protected ProductSyncService $syncService;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductSyncService $syncService
    ) {
        $this->productRepository = $productRepository;
        $this->syncService = $syncService;
    }

    /**
     * Retrieve UPI Code and Status for a single product.
     * Route: GET /api/products/{id}/upi
     */
    public function show(Request $request, $id)
    {
        $product = $this->productRepository->find((int)$id);
        if (!$product) {
            $product = $this->productRepository->findByShopifyId((int)$id);
        }

        if (!$product) {
            return response()->json(['error' => 'Product not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'product' => $product
        ], 200);
    }

    /**
     * Create or update UPI Code and Status for a single product.
     * Route: POST /api/products/{id}/upi
     */
    public function store(Request $request, $id)
    {
        $request->validate([
            'upi_code' => 'required|string|min:4|max:15|regex:/^[a-zA-Z0-9]+$/',
            'upi_status' => 'nullable|string|max:50',
            'updated_by' => 'nullable|string|max:100',
        ]);

        $product = $this->productRepository->find((int)$id);
        if (!$product) {
            $product = $this->productRepository->findByShopifyId((int)$id);
        }

        if (!$product) {
            return response()->json(['error' => 'Product not found.'], 404);
        }

        $upiCode = $request->input('upi_code');
        $upiStatus = $request->input('upi_status', 'Active');
        $updatedBy = $request->input('updated_by', 'API Client');

        // Trigger updates and pushes to Shopify immediately
        $this->syncService->triggerLocalUpiUpdate($product, $upiCode, $upiStatus, $updatedBy);

        return response()->json([
            'success' => true,
            'message' => 'UPI code set successfully, syncing with Shopify in background.',
            'product' => $product->fresh()
        ], 200);
    }

    /**
     * Delete/Clear UPI Code and Status for a single product.
     * Route: DELETE /api/products/{id}/upi
     */
    public function destroy(Request $request, $id)
    {
        $request->validate([
            'updated_by' => 'nullable|string|max:100',
        ]);

        $product = $this->productRepository->find((int)$id);
        if (!$product) {
            $product = $this->productRepository->findByShopifyId((int)$id);
        }

        if (!$product) {
            return response()->json(['error' => 'Product not found.'], 404);
        }

        $updatedBy = $request->input('updated_by', 'API Client (Deletion)');

        // Clear local database and sync back deletion to Shopify metafields
        $this->syncService->triggerLocalUpiUpdate($product, null, null, $updatedBy);

        return response()->json([
            'success' => true,
            'message' => 'UPI code cleared successfully, syncing with Shopify in background.',
            'product' => $product->fresh()
        ], 200);
    }

    /**
     * Bulk update multiple products' UPI values.
     * Route: POST /api/products/upi/bulk
     */
    public function bulk(Request $request)
    {
        $request->validate([
            'updates' => 'required|array|min:1',
            'updates.*.shopify_product_id' => 'required|integer',
            'updates.*.upi_code' => 'required|string|min:4|max:15|regex:/^[a-zA-Z0-9]+$/',
            'updates.*.upi_status' => 'nullable|string|max:50',
            'updated_by' => 'nullable|string|max:100',
        ]);

        $updates = $request->input('updates');
        $updatedBy = $request->input('updated_by', 'API Bulk Client');

        // Dispatch bulk update background job to handle execution asynchronously
        BulkPushUpiToShopifyJob::dispatch($updates, $updatedBy);

        return response()->json([
            'success' => true,
            'message' => 'Bulk UPI update job queued successfully.',
            'queued_count' => count($updates),
        ], 202);
    }
}
