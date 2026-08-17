<?php

namespace App\Livewire;

use App\Models\Shop;
use App\Models\Product;
use App\Models\SyncJob;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\ProductSyncService;
use App\Jobs\BulkPushUpiToShopifyJob;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\Url;

class Dashboard extends Component
{
    use WithPagination, WithFileUploads;

    public bool $isStandalone = false;
    public bool $isEmbedded = false;
    public ?int $currentShopId = null;
    public ?string $currentShopDomain = null;
    public ?string $currentShopName = null;
    public $storeTokensCsv;

    // Search and Filter Bindings, tracked dynamically in the URL query string
    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $filterShopId = '';

    #[Url(history: true)]
    public string $filterVendor = '';

    #[Url(history: true)]
    public string $filterType = '';

    #[Url(history: true)]
    public string $filterStatus = '';

    #[Url(history: true)]
    public string $filterUpiStatus = '';

    // Selection properties for Bulk Updates
    public array $selectedProducts = [];
    public bool $selectAll = false;

    // Bulk Editing Form bindings
    public string $bulkUpiCode = '';
    public string $bulkUpiStatus = 'Active';

    // Inline Editing Trackers
    public ?int $editProductId = null;
    public string $editUpiCode = '';
    public string $editUpiStatus = 'Active';

    /**
     * Reset pagination page whenever filters or search terms change.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }
    
    public function updatingFilterShopId(): void
    {
        $this->resetPage();
    }

    public function updatingFilterVendor(): void
    {
        $this->resetPage();
    }

    public function updatingFilterType(): void
    {
        $this->resetPage();
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatingFilterUpiStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Start inline editing mode for a specific product.
     */
    public function startEdit(int $productId): void
    {
        $product = Product::find($productId);
        if ($product) {
            $this->editProductId = $product->id;
            $this->editUpiCode = $product->upi_code ?? '';
            $this->editUpiStatus = $product->upi_status ?? 'Active';
        }
    }

    /**
     * Cancel inline editing.
     */
    public function cancelEdit(): void
    {
        $this->editProductId = null;
        $this->editUpiCode = '';
        $this->editUpiStatus = 'Active';
    }

    /**
     * Save inline updates via ProductSyncService and Push to Shopify.
     */
    public function saveInlineUpi(ProductSyncService $syncService): void
    {
        if (!$this->editProductId) {
            return;
        }

        $product = Product::find($this->editProductId);
        if ($product) {
            $this->validate([
                'editUpiCode' => 'nullable|string|min:4|max:15|regex:/^[a-zA-Z0-9]+$/',
                'editUpiStatus' => 'required|string|max:50',
            ]);
            try {
                $syncService->triggerLocalUpiUpdate(
                    $product,
                    $this->editUpiCode,
                    $this->editUpiStatus,
                    'Laravel Dashboard (Inline)'
                );
                session()->flash('message', "UPI for {$product->title} updated successfully!");
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("saveInlineUpi error: " . $e->getMessage());
                session()->flash('error', "Failed to save UPI: " . $e->getMessage());
            }
        }

        $this->cancelEdit();
    }

    /**
     * Select or deselect all products on the current paginated page.
     */
    public function updatedSelectAll($value): void
    {
        if ($value) {
            $filters = [
                'shop_id' => $this->filterShopId,
                'vendor' => $this->filterVendor,
                'product_type' => $this->filterType,
                'status' => $this->filterStatus,
                'search' => $this->search,
            ];
            
            $productRepo = app(ProductRepositoryInterface::class);
            $query = $productRepo->getFilteredProductsQuery($filters);
            
            // Custom UPI status filter handling
            if ($this->filterUpiStatus === 'missing') {
                $query->whereNull('upi_code');
            } elseif ($this->filterUpiStatus) {
                $query->where('upi_status', $this->filterUpiStatus);
            }

            // Extract IDs of all matching products
            $this->selectedProducts = $query->pluck('id')->map(fn($id) => (string)$id)->toArray();
        } else {
            $this->selectedProducts = [];
        }
    }

    /**
     * Clear all checked products.
     */
    public function clearSelection(): void
    {
        $this->selectedProducts = [];
        $this->selectAll = false;
    }

    /**
     * Process bulk edits via background queues.
     */
    public function submitBulkEdit(): void
    {
        $this->validate([
            'bulkUpiCode' => 'required|string|min:4|max:15|regex:/^[a-zA-Z0-9]+$/',
            'bulkUpiStatus' => 'required|string|max:50',
        ]);

        if (empty($this->selectedProducts)) {
            return;
        }

        // 1. Compile update array structure for selected products
        $updates = [];
        $productsToUpdate = Product::whereIn('id', $this->selectedProducts)->get();

        foreach ($productsToUpdate as $product) {
            $updates[] = [
                'shopify_product_id' => $product->shopify_product_id,
                'upi_code' => $this->bulkUpiCode,
                'upi_status' => $this->bulkUpiStatus,
            ];
        }

        try {
            // 2. Dispatch bulk queue operation
            BulkPushUpiToShopifyJob::dispatch($updates, 'Laravel Dashboard (Bulk)');
            session()->flash('success', 'Bulk update job completed/queued successfully for ' . count($updates) . ' products.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("submitBulkEdit error: " . $e->getMessage());
            session()->flash('error', "Bulk update failed: " . $e->getMessage());
        }
        
        $this->clearSelection();
        $this->bulkUpiCode = '';
        $this->bulkUpiStatus = 'Active';
    }

    /**
     * Mount the component and detect route mode context.
     */
    public function mount(): void
    {
        $this->isStandalone = request()->routeIs('admin.dashboard');

        $resolvedShop = request()->attributes->get('shopify_shop');
        if ($resolvedShop) {
            $this->currentShopId = $resolvedShop->id;
            $this->currentShopDomain = $resolvedShop->shop_domain;
            $this->currentShopName = $resolvedShop->shop_name;
            $this->isEmbedded = true;
            $this->filterShopId = (string) $resolvedShop->id;
        } else {
            $shopDomain = request()->query('shop') ?: request()->header('X-Shopify-Shop-Domain');
            if ($shopDomain) {
                $shop = Shop::where('shop_domain', $shopDomain)->first();
                if ($shop) {
                    $this->currentShopId = $shop->id;
                    $this->currentShopDomain = $shop->shop_domain;
                    $this->currentShopName = $shop->shop_name;
                    $this->isEmbedded = true;
                    $this->filterShopId = (string) $shop->id;
                }
            }
        }
    }

    /**
     * Onboard stores from an uploaded Access Token CSV file.
     */
    public function onboardStoresCsv(): void
    {
        $this->validate([
            'storeTokensCsv' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $path = $this->storeTokensCsv->store('store_imports');

        // Create SyncJob record
        $syncJob = SyncJob::create([
            'type' => 'store_import',
            'status' => 'pending',
            'file_path' => $path,
        ]);

        // Dispatch BulkOnboardStoresJob
        \App\Jobs\BulkOnboardStoresJob::dispatch($syncJob);

        session()->flash('success', 'Store Token CSV onboarding job started in background.');
        $this->storeTokensCsv = null;
    }

    /**
     * Compute statistics dashboard features.
     */
    protected function getStats(): array
    {
        if ($this->isEmbedded && $this->currentShopId) {
            $totalProducts = Product::where('shop_id', $this->currentShopId)->count();
            $missingUpi = Product::where('shop_id', $this->currentShopId)->where(fn($q) => $q->whereNull('upi_code')->orWhere('upi_code', ''))->count();
            $withUpi = Product::where('shop_id', $this->currentShopId)->where(fn($q) => $q->whereNotNull('upi_code')->where('upi_code', '!=', ''))->count();
            
            $syncedCount = Product::where('shop_id', $this->currentShopId)->where('sync_status', 'synced')->count();
            $syncedRate = $totalProducts > 0 ? round(($syncedCount / $totalProducts) * 100) : 100;

            return [
                'total_products' => $totalProducts,
                'missing_upi' => $missingUpi,
                'with_upi' => $withUpi,
                'synced_rate' => $syncedRate,
            ];
        }

        $totalStores = Shop::count();
        $totalProducts = Product::count();
        $missingUpi = Product::whereNull('upi_code')->orWhere('upi_code', '')->count();
        $withUpi = Product::whereNotNull('upi_code')->where('upi_code', '!=', '')->count();
        
        $syncedCount = Product::where('sync_status', 'synced')->count();
        $syncedRate = $totalProducts > 0 ? round(($syncedCount / $totalProducts) * 100) : 100;

        return [
            'total_stores' => $totalStores,
            'total_products' => $totalProducts,
            'missing_upi' => $missingUpi,
            'with_upi' => $withUpi,
            'synced_rate' => $syncedRate,
        ];
    }

    /**
     * Sync products from Shopify for the current store.
     */
    public function syncStoreProducts(?int $shopId = null): void
    {
        $resolvedShopId = $shopId ?: ($this->isEmbedded ? $this->currentShopId : $this->filterShopId);

        if (!$resolvedShopId) {
            session()->flash('error', 'Please select a store to sync products.');
            return;
        }

        $shop = Shop::find($resolvedShopId);
        if (!$shop) {
            session()->flash('error', 'Invalid store context.');
            return;
        }

        if ($shop->access_token === 'mock_access_token_123456789') {
            // For mock store, we just populate mock data in the background
            \App\Jobs\StoreProvisioningJob::dispatch($shop, true);
            session()->flash('success', "Simulated product sync started for {$shop->shop_domain}.");
            return;
        }

        try {
            // Dispatch store provisioning job chain
            \App\Jobs\StoreProvisioningJob::dispatch($shop, false);
            session()->flash('success', "Product synchronization job completed/queued successfully for {$shop->shop_domain}.");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("syncStoreProducts error: " . $e->getMessage());
            session()->flash('error', "Sync failed: " . $e->getMessage());
        }
    }

    /**
     * Generate missing UPI codes for all products in the current store.
     */
    public function generateMissingUpis(ProductSyncService $syncService): void
    {
        $shopId = $this->isEmbedded ? $this->currentShopId : $this->filterShopId;
        
        if (!$shopId) {
            session()->flash('error', 'Please select a store to generate UPI codes.');
            return;
        }

        $shop = Shop::find($shopId);
        if (!$shop) {
            session()->flash('error', 'Invalid store context.');
            return;
        }

        $productsMissingUpi = Product::where('shop_id', $shop->id)
            ->where(function($q) {
                $q->whereNull('upi_code')->orWhere('upi_code', '');
            })
            ->get();

        if ($productsMissingUpi->isEmpty()) {
            session()->flash('info', 'All products in this store already have UPI codes.');
            return;
        }

        // Get all existing UPI codes in DB to do O(1) memory lookup
        $existingUpis = Product::whereNotNull('upi_code')
            ->where('upi_code', '!=', '')
            ->pluck('upi_code')
            ->flip()
            ->toArray();

        $updates = [];
        $jobUpdates = [];
        $generatedCount = 0;

        foreach ($productsMissingUpi as $product) {
            $datePrefix = $product->created_at ? $product->created_at->format('ymd') : date('ymd');
            $idSuffix = $product->shopify_product_id 
                ? substr((string)$product->shopify_product_id, -4) 
                : str_pad((string)$product->id, 4, '0', STR_PAD_LEFT);
            
            $baseUpiCode = "UPI{$datePrefix}{$idSuffix}";
            $upiCode = $baseUpiCode;
            $counter = 1;

            // Uniqueness check: verify the UPI code is not already in use in memory
            while (isset($existingUpis[$upiCode])) {
                $counterStr = (string)$counter;
                $upiCode = substr($baseUpiCode, 0, 15 - strlen($counterStr)) . $counterStr;
                $counter++;
            }

            // Reserve it in memory for subsequent iterations
            $existingUpis[$upiCode] = true;

            $updates[] = [
                'id' => $product->id,
                'upi_code' => $upiCode,
                'upi_status' => 'Active',
                'item_category' => $product->item_category,
            ];

            $jobUpdates[] = [
                'shopify_product_id' => $product->shopify_product_id,
                'upi_code' => $upiCode,
                'upi_status' => 'Active',
            ];

            $generatedCount++;
        }

        try {
            // Run local DB updates in a single transaction
            \Illuminate\Support\Facades\DB::transaction(function() use ($updates) {
                foreach ($updates as $update) {
                    Product::where('id', $update['id'])->update([
                        'upi_code' => $update['upi_code'],
                        'upi_status' => $update['upi_status'],
                        'item_category' => $update['item_category'],
                        'last_updated_by' => 'Shopify Embedded App Generator',
                        'last_updated_at' => now(),
                        'sync_status' => 'pending_push',
                    ]);
                }
            });

            // Dispatch bulk push job
            BulkPushUpiToShopifyJob::dispatch($jobUpdates, 'Shopify Embedded App Generator');
            session()->flash('success', "Successfully generated and queued/completed UPI codes for {$generatedCount} products.");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("generateMissingUpis error: " . $e->getMessage());
            session()->flash('error', "UPI generation failed: " . $e->getMessage());
        }
    }


    /**
     * Render the Livewire component template.
     */
    public function render(
        ShopRepositoryInterface $shopRepo,
        ProductRepositoryInterface $productRepo
    ) {
        if ($this->isEmbedded && $this->currentShopId) {
            $this->filterShopId = (string) $this->currentShopId;
        }

        // Dropdown options
        $shops = Shop::orderBy('shop_domain')->get();
        $vendors = Product::distinct()->orderBy('vendor')->pluck('vendor')->filter()->values();
        $productTypes = Product::distinct()->orderBy('product_type')->pluck('product_type')->filter()->values();

        // Main store directories
        $storesList = Shop::orderBy('shop_domain')->get();

        // Apply product query filters
        $filters = [
            'shop_id' => $this->filterShopId,
            'vendor' => $this->filterVendor,
            'product_type' => $this->filterType,
            'status' => $this->filterStatus,
            'search' => $this->search,
        ];

        $productQuery = $productRepo->getFilteredProductsQuery($filters);

        // Filter by UPI status
        if ($this->filterUpiStatus === 'missing') {
            $productQuery->whereNull('upi_code');
        } elseif ($this->filterUpiStatus) {
            $productQuery->where('upi_status', $this->filterUpiStatus);
        }

        $products = $productQuery->orderBy('updated_at', 'desc')->paginate(15);

        // Fetch recent active CSV import/export jobs
        $recentJobsQuery = SyncJob::with('shop');
        if ($this->isEmbedded && $this->currentShopId) {
            $recentJobsQuery->where('shop_id', $this->currentShopId);
        }
        $recentJobs = $recentJobsQuery->orderBy('created_at', 'desc')->take(5)->get();

        // Fetch recently updated UPI action logs for Audit Trail
        $recentUpdatesQuery = Product::whereNotNull('upi_code');
        if ($this->isEmbedded && $this->currentShopId) {
            $recentUpdatesQuery->where('shop_id', $this->currentShopId);
        }
        $recentUpdates = $recentUpdatesQuery->orderBy('last_updated_at', 'desc')->take(5)->get();

        return view('livewire.dashboard', [
            'products' => $products,
            'shops' => $shops,
            'vendors' => $vendors,
            'productTypes' => $productTypes,
            'storesList' => $storesList,
            'recentJobs' => $recentJobs,
            'recentUpdates' => $recentUpdates,
            'stats' => $this->getStats(),
        ]);
    }
}
