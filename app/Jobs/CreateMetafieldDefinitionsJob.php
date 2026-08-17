<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateMetafieldDefinitionsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

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
        Log::info("CreateMetafieldDefinitionsJob: Creating metafield definitions for {$this->shop->shop_domain}");

        $client = new ShopifyClient($this->shop);
        $success = $client->registerMetafieldDefinition();

        if (!$success) {
            throw new \Exception("Failed to register metafield definitions for {$this->shop->shop_domain}");
        }

        Log::info("CreateMetafieldDefinitionsJob: Metafield definition creation finished for {$this->shop->shop_domain}");
    }
}
