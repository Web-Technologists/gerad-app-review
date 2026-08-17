<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Jobs\StoreProvisioningJob;
use Illuminate\Console\Command;

class ShopifySyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync products and configurations for all active Shopify stores.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $shops = Shop::where('status', 'active')->get();

        if ($shops->isEmpty()) {
            $this->info("No active shops found to sync.");
            return Command::SUCCESS;
        }

        foreach ($shops as $shop) {
            $isMock = $shop->access_token === 'mock_access_token_123456789' || ($shop->access_token && str_starts_with($shop->access_token, 'mock'));
            StoreProvisioningJob::dispatch($shop, $isMock);
            $this->info("Queued sync job for shop: {$shop->shop_domain}");
        }

        return Command::SUCCESS;
    }
}
