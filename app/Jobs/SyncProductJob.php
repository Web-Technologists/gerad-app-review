<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ProductSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProductJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected Shop $shop;
    protected array $payload;
    protected string $action;

    /**
     * Create a new job instance.
     */
    public function __construct(Shop $shop, array $payload, string $action)
    {
        $this->shop = $shop;
        $this->payload = $payload;
        $this->action = $action;
    }

    /**
     * Execute the job.
     */
    public function handle(ProductSyncService $syncService): void
    {
        Log::info("SyncProductJob: Dispatching sync to ProductSyncService for ID: " . ($this->payload['id'] ?? 'unknown'));
        
        $syncService->syncFromWebhook($this->shop, $this->payload, $this->action);
    }
}
