<?php

use App\Models\Shop;
use App\Services\ShopifyClient;

// Boot Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$shop = Shop::where('shop_domain', 'like', '%the-social-life%')->first();
if (!$shop) {
    echo "Shop matching 'the-social-life' not found in database.\n";
    exit(1);
}

echo "Found shop: {$shop->shop_domain}\n";
$client = new ShopifyClient($shop);

$query = <<<GQL
query {
  products(first: 1, query: "status:active") {
    edges {
      node {
        id
        title
        handle
        vendor
        productType
        status
        createdAt
        updatedAt
        metafields(first: 20) {
          edges {
            node {
              id
              namespace
              key
              value
              type
            }
          }
        }
      }
    }
  }
}
GQL;

echo "Calling Shopify GraphQL API...\n";
try {
    $result = $client->graph($query);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Exception $e) {
    echo "Error calling Shopify API: " . $e->getMessage() . "\n";
}
