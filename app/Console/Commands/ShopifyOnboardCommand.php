<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Jobs\StoreProvisioningJob;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ShopifyOnboardCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:onboard 
                            {domain : The Shopify store domain (e.g. dev-store.myshopify.com)} 
                            {--token= : Shopify Admin Access Token} 
                            {--mock : Provision with simulated catalog products}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Onboard a new Shopify store, register webhooks, create metafield definitions, and execute catalog syncing.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $domain = strtolower(trim($this->argument('domain')));
        $isMock = $this->option('mock');
        $token = $this->option('token');

        if (!Str::contains($domain, '.')) {
            $domain .= '.myshopify.com';
        }

        if (!preg_match('/^[a-zA-Z0-9.-]+\.myshopify\.com$/', $domain)) {
            $this->error("Invalid Shopify store domain format: {$domain}");
            return Command::FAILURE;
        }

        if (!$isMock && empty($token)) {
            $this->error("Access token (--token) is required for onboarding a live Shopify store. Alternatively, use --mock to run simulated onboarding.");
            return Command::INVALID;
        }

        $this->info("Initializing onboarding for Shopify store: {$domain}...");

        // Resolve or create Shop record
        $shop = Shop::updateOrCreate(
            ['shop_domain' => $domain],
            [
                'access_token' => $token ?: 'mock_cli_access_token',
                'custom_domain' => $isMock ? 'www.' . str_replace('.myshopify.com', '.com', $domain) : null,
                'scopes' => ['read_products', 'write_products'],
                'status' => 'active',
            ]
        );

        // Dispatch provisioning pipeline
        StoreProvisioningJob::dispatch($shop, $isMock);

        $this->successMessage($domain, $isMock);

        return Command::SUCCESS;
    }

    /**
     * Print success messages.
     */
    protected function successMessage(string $domain, bool $isMock): void
    {
        $this->newLine();
        $this->info("=================================================================");
        $this->info(" SUCCESS: Store onboarding pipeline has been successfully queued!");
        $this->info("=================================================================");
        $this->line("Store Domain:   <comment>{$domain}</comment>");
        $this->line("Mode:           <comment>" . ($isMock ? 'Simulated (Mock)' : 'Live Shopify Connect') . "</comment>");
        $this->line("Execution:      Queued via StoreProvisioningJob.");
        $this->line("Status check:   Use 'php artisan queue:work' to process, then view dashboard.");
        $this->info("=================================================================");
        $this->newLine();
    }
}
