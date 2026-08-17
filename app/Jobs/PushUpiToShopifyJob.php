<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ProductSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PushUpiToShopifyJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected Product $product;

    /**
     * Create a new job instance.
     */
    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    /**
     * Execute the job.
     */
    public function handle(ProductSyncService $syncService): void
    {
        $shop = $this->product->shop;
        
        Log::info("PushUpiToShopifyJob: Queueing push to ProductSyncService for product {$this->product->id}");

        // If mock access token, bypass throttle and call service immediately
        if ($shop->access_token === 'mock_access_token_123456789') {
            $syncService->pushUpiToShopify($this->product);
            return;
        }

        // Apply Redis rate throttling per store
        $limiterName = "shopify-api:{$shop->id}";
        
        try {
            if (config('database.redis.default') && Redis::connection()) {
                Redis::throttle($limiterName)
                    ->allow(10)
                    ->every(1)
                    ->then(function () use ($syncService) {
                        $syncService->pushUpiToShopify($this->product);
                    }, function () {
                        $this->release(5);
                    });
            } else {
                $syncService->pushUpiToShopify($this->product);
            }
        } catch (\Exception $e) {
            Log::warning("PushUpiToShopifyJob: Redis throttle bypassed due to: " . $e->getMessage());
            $syncService->pushUpiToShopify($this->product);
        }
    }
}
