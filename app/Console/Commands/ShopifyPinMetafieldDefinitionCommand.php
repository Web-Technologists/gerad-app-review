<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ShopifyPinMetafieldDefinitionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:pin-metafield-definition 
                            {ns : The namespace of the metafield definition (e.g. custom)}
                            {k : The key of the metafield definition (e.g. upi)}
                            {shop? : The Shopify store domain (optional. If omitted, runs for all stores)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pin a product metafield definition on Shopify by namespace and key for one or all stores.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $namespace = $this->argument('ns');
        $key = $this->argument('k');
        $shopDomain = $this->argument('shop');

        if ($shopDomain) {
            $shops = Shop::where('shop_domain', $shopDomain)
                ->orWhere('shop_domain', 'like', "%{$shopDomain}%")
                ->get();
            if ($shops->isEmpty()) {
                $this->error("Shop not found in DB matching: {$shopDomain}");
                return Command::FAILURE;
            }
        } else {
            $shops = Shop::all();
            if ($shops->isEmpty()) {
                $this->info("No shops found in the database.");
                return Command::SUCCESS;
            }
            $this->info("Found " . $shops->count() . " shops. Pinning metafield definition '{$namespace}.{$key}' across all stores...");
        }

        if ($this->input->isInteractive()) {
            if (!$this->confirm("Are you sure you want to pin the metafield definition '{$namespace}.{$key}' on Shopify for " . ($shopDomain ? $shopDomain : "ALL stores") . "?", true)) {
                $this->warn("Operation cancelled.");
                return Command::SUCCESS;
            }
        }

        foreach ($shops as $shop) {
            $this->info("\nProcessing store: {$shop->shop_domain}...");
            $this->pinDefinitionForShop($shop, $namespace, $key);
        }

        $this->info("\nAll operations completed.");
        return Command::SUCCESS;
    }

    /**
     * Pin definition for a single shop.
     */
    protected function pinDefinitionForShop(Shop $shop, string $namespace, string $key): void
    {
        if ($shop->access_token === 'mock_access_token_123456789' || str_starts_with($shop->access_token ?? '', 'mock')) {
            $this->info("[Mock Path] Successfully pinned metafield definition '{$namespace}.{$key}' on Shopify.");
            return;
        }

        $client = new ShopifyClient($shop);

        $queryDefs = <<<GQL
        query GetMetafieldDefs {
            metafieldDefinitions(first: 100, ownerType: PRODUCT) {
                edges {
                    node {
                        id
                        namespace
                        key
                    }
                }
            }
        }
        GQL;

        try {
            $response = $client->graph($queryDefs);
            $edges = $response['data']['metafieldDefinitions']['edges'] ?? [];

            $targetId = null;
            foreach ($edges as $edge) {
                $node = $edge['node'];
                if ($node['namespace'] === $namespace && $node['key'] === $key) {
                    $targetId = $node['id'];
                    break;
                }
            }

            if (!$targetId) {
                $this->warn("Metafield definition '{$namespace}.{$key}' not found on Shopify for {$shop->shop_domain}.");
                return;
            }

            $mutationPin = <<<GQL
            mutation MetafieldDefinitionPin(\$id: ID!) {
                metafieldDefinitionPin(id: \$id) {
                    pinnedDefinition {
                        id
                        name
                        pinnedPosition
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;

            $pinResult = $client->graph($mutationPin, [
                'id' => $targetId
            ]);

            // Check top-level GraphQL errors
            if (isset($pinResult['errors'])) {
                foreach ($pinResult['errors'] as $err) {
                    $this->error("GraphQL error for {$shop->shop_domain}: " . ($err['message'] ?? json_encode($err)));
                }
                return;
            }

            $userErrors = $pinResult['data']['metafieldDefinitionPin']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                foreach ($userErrors as $err) {
                    $this->error("Shopify error for {$shop->shop_domain}: " . $err['message']);
                }
                return;
            }

            $pinnedDef = $pinResult['data']['metafieldDefinitionPin']['pinnedDefinition'] ?? null;
            if ($pinnedDef) {
                $this->info("Successfully pinned definition '{$namespace}.{$key}' on Shopify for {$shop->shop_domain}.");
            } else {
                $this->error("Failed to pin definition for {$shop->shop_domain}. Response: " . json_encode($pinResult));
            }

        } catch (\Exception $e) {
            $this->error("Exception for {$shop->shop_domain}: " . $e->getMessage());
        }
    }
}
