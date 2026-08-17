<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushPendingUpisJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 45, 90];

    protected Shop $shop;

    /**
     * Create a new job instance.
     */
    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("PushPendingUpisJob: Checking for pending/failed UPI pushes for {$this->shop->shop_domain}");

        $pendingProducts = Product::where('shop_id', $this->shop->id)
            ->whereIn('sync_status', ['pending_push', 'failed'])
            ->whereNotNull('upi_code')
            ->where('upi_code', '!=', '')
            ->get();

        if ($pendingProducts->isEmpty()) {
            Log::info("PushPendingUpisJob: No pending/failed UPI pushes for {$this->shop->shop_domain}");
            return;
        }

        Log::info("PushPendingUpisJob: Found {$pendingProducts->count()} pending/failed products for {$this->shop->shop_domain}. Dispatching bulk push.");

        $jobUpdates = [];
        foreach ($pendingProducts as $product) {
            $jobUpdates[] = [
                'shopify_product_id' => $product->shopify_product_id,
                'upi_code' => $product->upi_code,
                'upi_status' => $product->upi_status ?: 'Active',
            ];
        }

        // Dispatch BulkPushUpiToShopifyJob
        BulkPushUpiToShopifyJob::dispatch($jobUpdates, 'Auto-Recovery Push Job');
        
        Log::info("PushPendingUpisJob: Bulk push job dispatched for {$this->shop->shop_domain}");
    }
}
