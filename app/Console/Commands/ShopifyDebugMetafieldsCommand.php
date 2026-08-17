<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;

class ShopifyDebugMetafieldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:debug-metafields {shop}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query and inspect metafield definitions and validation rules from Shopify.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $shopDomain = $this->argument('shop');
        $shop = Shop::where('shop_domain', $shopDomain)
            ->orWhere('shop_domain', 'like', "%{$shopDomain}%")
            ->first();

        if (!$shop) {
            $this->error("Shop not found in DB matching: {$shopDomain}");
            return Command::FAILURE;
        }

        $this->info("Querying metafield definitions for shop: {$shop->shop_domain}");

        $client = new ShopifyClient($shop);

        $query = <<<GQL
        query GetMetafieldDefs {
            metafieldDefinitions(first: 50, ownerType: PRODUCT) {
                edges {
                    node {
                        name
                        namespace
                        key
                        type {
                            name
                        }
                        validations {
                            name
                            value
                        }
                    }
                }
            }
        }
        GQL;

        try {
            $response = $client->graph($query);
            $edges = $response['data']['metafieldDefinitions']['edges'] ?? [];

            if (empty($edges)) {
                $this->warn("No metafield definitions found on Shopify.");
                return Command::SUCCESS;
            }

            foreach ($edges as $edge) {
                $node = $edge['node'];
                $this->line("------------------------------------------------");
                $this->info("Name:      " . ($node['name'] ?? 'N/A'));
                $this->line("Namespace: " . ($node['namespace'] ?? 'N/A'));
                $this->line("Key:       " . ($node['key'] ?? 'N/A'));
                $this->line("Type:      " . ($node['type']['name'] ?? 'N/A'));
                
                $validations = $node['validations'] ?? [];
                if (!empty($validations)) {
                    $this->line("Validations:");
                    foreach ($validations as $validation) {
                        $this->line("  - " . $validation['name'] . ": " . $validation['value']);
                    }
                } else {
                    $this->line("Validations: None");
                }
            }
            $this->line("------------------------------------------------");

        } catch (\Exception $e) {
            $this->error("Error querying metafield definitions: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
