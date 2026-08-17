<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegisterWebhooksJob implements ShouldQueue
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
        Log::info("RegisterWebhooksJob: Starting webhook registration for {$this->shop->shop_domain}");

        $client = new ShopifyClient($this->shop);
        $appHost = config('services.shopify.app_host') ?? 'shopify-upi-manager.test';
        $webhookUrl = "https://{$appHost}/api/webhooks";

        $topics = [
            'PRODUCTS_CREATE',
            'PRODUCTS_UPDATE',
            'PRODUCTS_DELETE',
            'APP_UNINSTALLED'
        ];

        $failedTopics = [];

        foreach ($topics as $topic) {
            try {
                $success = $client->registerWebhook($topic, $webhookUrl);
                if ($success) {
                    Log::info("RegisterWebhooksJob: Successfully registered webhook {$topic} for {$this->shop->shop_domain}");
                } else {
                    $failedTopics[] = $topic;
                    Log::warning("RegisterWebhooksJob: Failed to register webhook {$topic} for {$this->shop->shop_domain}");
                }
            } catch (\Exception $e) {
                $failedTopics[] = $topic;
                Log::error("RegisterWebhooksJob: Exception registering {$topic} for {$this->shop->shop_domain}: " . $e->getMessage());
            }
        }

        if (!empty($failedTopics)) {
            throw new \Exception("Failed to register webhooks: " . implode(', ', $failedTopics));
        }

        Log::info("RegisterWebhooksJob: Webhook registration complete for {$this->shop->shop_domain}");
    }
}
