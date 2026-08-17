<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyClient
{
    protected Shop $shop;
    protected string $apiVersion;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
        $this->apiVersion = '2024-07'; // Stable Shopify API version
    }

    /**
     * Get the base URL for the shop's API.
     */
    protected function getBaseUrl(): string
    {
        return "https://{$this->shop->shop_domain}/admin/api/{$this->apiVersion}";
    }

    /**
     * Check if the access token is close to expiry and refresh it if needed.
     */
    protected function checkAndRefreshAccessToken(): void
    {
        // Skip mock token checks
        if ($this->shop->access_token === 'mock_access_token_123456789' || str_starts_with($this->shop->access_token, 'mock')) {
            return;
        }

        // Only attempt refresh if refresh_token exists AND token is about to expire
        if (!empty($this->shop->refresh_token) && $this->shop->expires_at && now()->addMinutes(5)->greaterThan($this->shop->expires_at)) {
            try {
                $this->refreshAccessToken();
            } catch (\Exception $e) {
                Log::warning("ShopifyClient: Refresh token attempt failed for {$this->shop->shop_domain}: " . $e->getMessage() . ". Continuing with current access token.");
            }
        }
    }

    /**
     * Refresh the Shopify access token using the refresh token.
     */
    public function refreshAccessToken(): void
    {
        Log::info("ShopifyClient: Refreshing access token for {$this->shop->shop_domain}");

        $clientId = config('services.shopify.client_id');
        $clientSecret = config('services.shopify.client_secret');

        if (!$this->shop->refresh_token) {
            Log::warning("ShopifyClient: Cannot refresh token, refresh_token is missing for {$this->shop->shop_domain}");
            return;
        }

        $response = Http::post("https://{$this->shop->shop_domain}/admin/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->shop->refresh_token,
        ]);

        if (!$response->successful()) {
            Log::error("ShopifyClient: Failed to refresh access token.", [
                'shop' => $this->shop->shop_domain,
                'response' => $response->body()
            ]);
            throw new \Exception("Failed to refresh Shopify access token: " . $response->body());
        }

        $data = $response->json();

        $this->shop->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $this->shop->refresh_token,
            'expires_at' => isset($data['expires_in']) ? now()->addSeconds((int)$data['expires_in']) : null,
        ]);

        $this->shop->refresh();
    }

    /**
     * Execute a GraphQL Query or Mutation.
     */
    public function graph(string $query, array $variables = []): array
    {
        $this->checkAndRefreshAccessToken();

        $url = "{$this->getBaseUrl()}/graphql.json";
        
        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->shop->access_token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        if ($response->status() === 429) {
            // Leaky bucket limit hit, wait and retry
            $retryAfter = $response->header('Retry-After') ?: 2;
            sleep((int)$retryAfter);
            return $this->graph($query, $variables);
        }

        if (!$response->successful()) {
            $body = $response->body();
            $isAuthError = ($response->status() === 401 || 
                            str_contains($body, 'Invalid API key or access token') || 
                            str_contains($body, 'unrecognized login'));
            
            if ($isAuthError) {
                $this->shop->update(['status' => 'inactive']);
                Log::warning("Shopify access token revoked/invalid for shop: {$this->shop->shop_domain}. Marked as inactive.");
                throw new \Exception("Shopify access token is invalid or revoked. Please re-authenticate/re-install the app.");
            }

            Log::error("Shopify GraphQL Request failed: {$body}", [
                'shop' => $this->shop->shop_domain,
            ]);
            throw new \Exception("Shopify GraphQL Request failed: " . $body);
        }

        $data = $response->json();
        
        if (isset($data['errors'])) {
            Log::error("Shopify GraphQL errors detected", [
                'errors' => $data['errors'],
                'shop' => $this->shop->shop_domain,
            ]);
        }

        return $data;
    }

    /**
     * Create Webhook Subscription.
     */
    public function registerWebhook(string $topic, string $address): bool
    {
        $query = <<<GQL
        mutation webhookSubscriptionCreate(\$topic: WebhookSubscriptionTopic!, \$webhookSubscription: WebhookSubscriptionInput!) {
            webhookSubscriptionCreate(topic: \$topic, webhookSubscription: \$webhookSubscription) {
                userErrors {
                    field
                    message
                }
                webhookSubscription {
                    id
                }
            }
        }
        GQL;

        $variables = [
            'topic' => $topic,
            'webhookSubscription' => [
                'callbackUrl' => $address,
                'format' => 'JSON',
            ],
        ];

        try {
            $result = $this->graph($query, $variables);
            $userErrors = $result['data']['webhookSubscriptionCreate']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                foreach ($userErrors as $error) {
                    if (isset($error['message']) && str_contains($error['message'], 'already been taken')) {
                        return true;
                    }
                }

                Log::warning("Shopify Webhook registration user errors", [
                    'errors' => $userErrors,
                    'topic' => $topic,
                    'shop' => $this->shop->shop_domain,
                ]);
                return false;
            }
            return isset($result['data']['webhookSubscriptionCreate']['webhookSubscription']['id']);
        } catch (\Exception $e) {
            Log::error("Webhook Registration Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create the UPI Metafield Definitions on Shopify so they show up in Product Admin.
     */
    public function registerMetafieldDefinition(): bool
    {
        $query = <<<GQL
        mutation CreateMetafieldDefinition(\$definition: MetafieldDefinitionInput!) {
            metafieldDefinitionCreate(definition: \$definition) {
                createdDefinition {
                    id
                    name
                    namespace
                    key
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $definitions = [
            [
                'name' => 'UPI',
                'namespace' => 'custom',
                'key' => 'upi',
                'type' => 'single_line_text_field',
                'ownerType' => 'PRODUCT',
                'description' => 'Universal Product Identifier code.',
            ],
            [
                'name' => 'UPI Status',
                'namespace' => 'custom',
                'key' => 'upi_status',
                'type' => 'single_line_text_field',
                'ownerType' => 'PRODUCT',
                'description' => 'Current lifecycle status of the product UPI.',
            ],
            [
                'name' => 'Item Category',
                'namespace' => 'custom',
                'key' => 'item_category',
                'type' => 'single_line_text_field',
                'ownerType' => 'PRODUCT',
                'description' => 'The operational category of the product.',
            ],
            [
                'name' => 'Primary Licensor',
                'namespace' => 'custom',
                'key' => 'primary_licensor',
                'type' => 'single_line_text_field',
                'ownerType' => 'PRODUCT',
                'description' => 'The primary licensor organization associated with the product.',
            ]
        ];

        foreach ($definitions as $definition) {
            try {
                $result = $this->graph($query, ['definition' => $definition]);
                $userErrors = $result['data']['metafieldDefinitionCreate']['userErrors'] ?? [];
                if (!empty($userErrors)) {
                    Log::info("Shopify Metafield Definition Creation status ({$definition['key']})", [
                        'errors' => $userErrors,
                        'shop' => $this->shop->shop_domain,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Metafield Definition Registration Exception ({$definition['key']}): " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Sync UPI Code and Status back to Shopify for a specific Product.
     */
    public function setProductUpi(int $shopifyProductId, ?string $upiCode, ?string $upiStatus = null, ?string $itemCategory = null, ?string $primaryLicensor = null): bool
    {
        $query = <<<GQL
        mutation metafieldsSet(\$metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: \$metafields) {
                metafields {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $metafieldsArray = [
            [
                'ownerId' => "gid://shopify/Product/{$shopifyProductId}",
                'namespace' => 'custom',
                'key' => 'upi',
                'value' => $upiCode ?? '',
                'type' => 'single_line_text_field',
            ],
            [
                'ownerId' => "gid://shopify/Product/{$shopifyProductId}",
                'namespace' => 'custom',
                'key' => 'upi_status',
                'value' => $upiStatus ?? '',
                'type' => 'single_line_text_field',
            ]
        ];

        if ($itemCategory !== null) {
            $metafieldsArray[] = [
                'ownerId' => "gid://shopify/Product/{$shopifyProductId}",
                'namespace' => 'custom',
                'key' => 'item_category',
                'value' => $itemCategory,
                'type' => 'single_line_text_field',
            ];
        }

        if ($primaryLicensor !== null) {
            $metafieldsArray[] = [
                'ownerId' => "gid://shopify/Product/{$shopifyProductId}",
                'namespace' => 'custom',
                'key' => 'primary_licensor',
                'value' => $primaryLicensor,
                'type' => 'single_line_text_field',
            ];
        }

        $variables = [
            'metafields' => $metafieldsArray,
        ];

        $result = $this->graph($query, $variables);
        $userErrors = $result['data']['metafieldsSet']['userErrors'] ?? [];
        
        if (!empty($userErrors)) {
            Log::error("Shopify metafieldsSet error", [
                'errors' => $userErrors,
                'shopify_product_id' => $shopifyProductId,
                'shop' => $this->shop->shop_domain,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Set multiple metafields for multiple products in a bulk mutation.
     */
    public function setBulkMetafields(array $metafields): bool
    {
        if (empty($metafields)) {
            return true;
        }

        $query = <<<GQL
        mutation metafieldsSet(\$metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: \$metafields) {
                metafields {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['metafields' => $metafields]);
            $userErrors = $result['data']['metafieldsSet']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                Log::error("Shopify bulk metafieldsSet error", [
                    'errors' => $userErrors,
                    'shop' => $this->shop->shop_domain,
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("setBulkMetafields Exception: " . $e->getMessage());
            return false;
        }
    }
    /**
     * Delete multiple metafields for multiple products in a bulk mutation.
     */
    public function deleteBulkMetafields(array $metafields): bool
    {
        if (empty($metafields)) {
            return true;
        }

        $query = <<<GQL
        mutation metafieldsDelete(\$metafields: [MetafieldIdentifierInput!]!) {
            metafieldsDelete(metafields: \$metafields) {
                deletedMetafields {
                    ownerId
                    namespace
                    key
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['metafields' => $metafields]);
            $userErrors = $result['data']['metafieldsDelete']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                Log::error("Shopify bulk metafieldsDelete error", [
                    'errors' => $userErrors,
                    'shop' => $this->shop->shop_domain,
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("deleteBulkMetafields Exception: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Create a product in the Shopify store catalog via GraphQL.
     */
    public function createProduct(array $productData): ?int
    {
        $query = <<<GQL
        mutation productCreate(\$input: ProductInput!) {
            productCreate(input: \$input) {
                product {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $variables = [
            'input' => [
                'title' => $productData['title'],
                'vendor' => $productData['vendor'] ?? '',
                'productType' => $productData['product_type'] ?? '',
                'handle' => $productData['handle'] ?? null,
                'status' => strtoupper($productData['status'] ?? 'ACTIVE'),
            ]
        ];

        try {
            $result = $this->graph($query, $variables);
            $userErrors = $result['data']['productCreate']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                Log::error("Shopify product creation user errors", [
                    'errors' => $userErrors,
                    'shop' => $this->shop->shop_domain,
                ]);
                return null;
            }
            $productIdUri = $result['data']['productCreate']['product']['id'] ?? null;
            if ($productIdUri) {
                return (int) basename($productIdUri);
            }
        } catch (\Exception $e) {
            Log::error("Shopify product creation exception: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fetch a product's full details (description, options, variants, media) from Shopify.
     */
    public function getProductDetails(int $shopifyProductId): ?array
    {
        $query = <<<GQL
        query GetProductDetails(\$id: ID!) {
            product(id: \$id) {
                title
                descriptionHtml
                vendor
                productType
                handle
                status
                featuredImage {
                    url
                }
                metafields(first: 100) {
                    edges {
                        node {
                            namespace
                            key
                            value
                            type
                        }
                    }
                }
                options {
                    name
                    values
                }
                media(first: 50) {
                    edges {
                        node {
                            mediaContentType
                            ... on MediaImage {
                                image {
                                    url
                                }
                                alt
                            }
                        }
                    }
                }
                variants(first: 250) {
                    edges {
                        node {
                            id
                            title
                            price
                            sku
                            selectedOptions {
                                name
                                value
                            }
                        }
                    }
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['id' => "gid://shopify/Product/{$shopifyProductId}"]);
            
            $product = $result['data']['product'] ?? null;
            if (!$product) {
                return null;
            }

            // Extract custom metafields
            $metafields = [];
            $product['upi_code'] = null;
            $product['upi_status'] = null;
            $product['item_category'] = null;
            foreach ($product['metafields']['edges'] ?? [] as $edge) {
                $node = $edge['node'];
                if ($node['namespace'] === 'custom') {
                    $metafields[] = [
                        'namespace' => $node['namespace'],
                        'key' => $node['key'],
                        'value' => $node['value'],
                        'type' => $node['type'],
                    ];
                    if ($node['key'] === 'upi') {
                        $product['upi_code'] = $node['value'];
                    }
                    if ($node['key'] === 'upi_status') {
                        $product['upi_status'] = $node['value'];
                    }
                    if ($node['key'] === 'item_category') {
                        $product['item_category'] = $node['value'];
                    }
                }
            }
            $product['all_metafields'] = $metafields;
            $product['main_image_url'] = $product['featuredImage']['url'] ?? null;


            // Format variants for easier consumption
            $variants = [];
            foreach ($product['variants']['edges'] ?? [] as $edge) {
                $node = $edge['node'];
                $variants[] = [
                    'shopify_variant_id' => (int) basename($node['id']),
                    'title' => $node['title'],
                    'price' => $node['price'],
                    'sku' => $node['sku'] ?? null,
                    'selectedOptions' => $node['selectedOptions'] ?? [],
                ];
            }
            $product['variants'] = $variants;

            // Format media for easier consumption
            $media = [];
            foreach ($product['media']['edges'] ?? [] as $edge) {
                $node = $edge['node'];
                if (($node['mediaContentType'] ?? '') === 'IMAGE' && !empty($node['image']['url'])) {
                    $media[] = [
                        'mediaContentType' => 'IMAGE',
                        'originalSource' => $node['image']['url'],
                        'alt' => $node['alt'] ?? null,
                    ];
                }
            }
            $product['media'] = $media;

            return $product;
        } catch (\Exception $e) {
            Log::error("ShopifyClient: getProductDetails failed for {$shopifyProductId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a product with full options, variants, and media on Shopify.
     */
    public function createProductWithVariants(array $productData): ?array
    {
        $createMutation = <<<GQL
        mutation productCreate(\$input: ProductInput!, \$media: [CreateMediaInput!]) {
            productCreate(input: \$input, media: \$media) {
                product {
                    id
                    variants(first: 1) {
                        edges {
                            node {
                                id
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        $productOptions = [];
        if (!empty($productData['options'])) {
            foreach ($productData['options'] as $opt) {
                $values = [];
                if (!empty($opt['values'])) {
                    foreach ($opt['values'] as $val) {
                        $values[] = ['name' => (string) $val];
                    }
                }
                $productOptions[] = [
                    'name' => $opt['name'],
                    'values' => $values,
                ];
            }
        }

        $input = [
            'title' => $productData['title'],
            'descriptionHtml' => $productData['descriptionHtml'] ?? '',
            'vendor' => $productData['vendor'] ?? '',
            'productType' => $productData['productType'] ?? $productData['product_type'] ?? '',
            'handle' => $productData['handle'] ?? null,
            'status' => strtoupper($productData['status'] ?? 'ACTIVE'),
        ];

        if (!empty($productOptions)) {
            $input['productOptions'] = $productOptions;
        }

        $variables = [
            'input' => $input,
        ];

        if (!empty($productData['media'])) {
            $variables['media'] = $productData['media'];
        }

        try {
            $result = $this->graph($createMutation, $variables);
            
            $userErrors = $result['data']['productCreate']['userErrors'] ?? [];
            if (!empty($userErrors)) {
                Log::error("Shopify product creation errors: " . json_encode($userErrors), [
                    'shop' => $this->shop->shop_domain
                ]);
                return null;
            }

            $productId = $result['data']['productCreate']['product']['id'] ?? null;
            if (!$productId) {
                return null;
            }

            $defaultVariantId = $result['data']['productCreate']['product']['variants']['edges'][0]['node']['id'] ?? null;
            $createdVariants = [];

            // If there's only one variant and it's default
            if (count($productData['variants'] ?? []) === 1 && ($productData['variants'][0]['title'] ?? '') === 'Default Title') {
                $variant = $productData['variants'][0];
                $updateMutation = <<<GQL
                mutation productVariantUpdate(\$input: ProductVariantInput!) {
                    productVariantUpdate(input: \$input) {
                        productVariant {
                            id
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
                GQL;

                $updateInput = [
                    'id' => $defaultVariantId,
                    'price' => $variant['price'] ?? '0.00',
                ];

                if (!empty($variant['sku'])) {
                    $updateInput['inventoryItem'] = [
                        'sku' => $variant['sku']
                    ];
                }

                $this->graph($updateMutation, [
                    'input' => $updateInput
                ]);

                $createdVariants[] = [
                    'shopify_variant_id' => (int) basename($defaultVariantId),
                    'title' => 'Default Title',
                    'sku' => $variant['sku'] ?? null,
                    'price' => $variant['price'] ?? 0.00,
                ];
            } else if (!empty($productData['variants'])) {
                $variantsInput = [];
                foreach ($productData['variants'] as $variant) {
                    $optionValues = [];
                    if (!empty($variant['selectedOptions'])) {
                        foreach ($variant['selectedOptions'] as $selOpt) {
                            $optionValues[] = [
                                'optionName' => $selOpt['name'],
                                'name' => $selOpt['value'] ?? $selOpt['name']
                            ];
                        }
                    }

                    $variantInput = [
                        'price' => $variant['price'] ?? '0.00',
                        'optionValues' => $optionValues,
                    ];

                    if (!empty($variant['sku'])) {
                        $variantInput['inventoryItem'] = [
                            'sku' => $variant['sku']
                        ];
                    }

                    $variantsInput[] = $variantInput;
                }

                $bulkCreateMutation = <<<GQL
                mutation productVariantsBulkCreate(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
                    productVariantsBulkCreate(productId: \$productId, variants: \$variants, strategy: REMOVE_STANDALONE_VARIANT) {
                        productVariants {
                            id
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
                GQL;

                $bulkResult = $this->graph($bulkCreateMutation, [
                    'productId' => $productId,
                    'variants' => $variantsInput
                ]);

                $bulkErrors = $bulkResult['data']['productVariantsBulkCreate']['userErrors'] ?? [];
                if (!empty($bulkErrors)) {
                    Log::warning("Shopify product variants bulk creation errors: " . json_encode($bulkErrors), [
                        'shop' => $this->shop->shop_domain
                    ]);
                }

                $bulkVariants = $bulkResult['data']['productVariantsBulkCreate']['productVariants'] ?? [];
                foreach ($bulkVariants as $index => $bv) {
                    if (isset($bv['id'])) {
                        $createdVariants[] = [
                            'shopify_variant_id' => (int) basename($bv['id']),
                            'title' => $productData['variants'][$index]['title'] ?? '',
                            'sku' => $productData['variants'][$index]['sku'] ?? null,
                            'price' => $productData['variants'][$index]['price'] ?? 0.00,
                        ];
                    }
                }
            }

            return [
                'id' => (int) basename($productId),
                'variants' => $createdVariants
            ];

        } catch (\Exception $e) {
            Log::error("createProductWithVariants Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a product by handle from Shopify.
     */
    public function getProductByHandle(string $handle): ?array
    {
        $query = <<<GQL
        query GetProductByHandle(\$handle: String!) {
            productByHandle(handle: \$handle) {
                id
                title
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['handle' => $handle]);
            return $result['data']['productByHandle'] ?? null;
        } catch (\Exception $e) {
            Log::error("getProductByHandle Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a product on Shopify.
     */
    public function deleteProduct(int $shopifyProductId): bool
    {
        $query = <<<GQL
        mutation productDelete(\$input: ProductDeleteInput!) {
            productDelete(input: \$input) {
                deletedProductId
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, [
                'input' => [
                    'id' => "gid://shopify/Product/{$shopifyProductId}"
                ]
            ]);

            $errors = $result['data']['productDelete']['userErrors'] ?? [];
            if (!empty($errors)) {
                Log::error("Shopify product delete errors: " . json_encode($errors), [
                    'shop' => $this->shop->shop_domain
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("deleteProduct Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get product metafield definitions from Shopify.
     */
    public function getMetafieldDefinitions(): array
    {
        $query = <<<GQL
        query (\$first: Int!) {
            metafieldDefinitions(first: \$first, ownerType: PRODUCT) {
                edges {
                    node {
                        namespace
                        key
                        type {
                            name
                        }
                    }
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['first' => 100]);
            $definitions = [];
            foreach ($result['data']['metafieldDefinitions']['edges'] ?? [] as $edge) {
                $node = $edge['node'];
                $definitions[] = [
                    'namespace' => $node['namespace'],
                    'key' => $node['key'],
                    'type' => $node['type']['name'] ?? '',
                ];
            }
            return $definitions;
        } catch (\Exception $e) {
            Log::error("getMetafieldDefinitions Exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Helper to normalize metafield keys to allow fuzzy matching (e.g. _royalty_percantage_ -> royalty_percentage)
     */
    protected function normalizeMetafieldKey(string $key): string
    {
        $normalized = strtolower($key);
        $normalized = trim($normalized, '_');
        $normalized = str_replace('_', '', $normalized);
        $normalized = str_replace('-', '', $normalized);
        $normalized = str_replace('percantage', 'percentage', $normalized);
        return $normalized;
    }

    /**
     * Set multiple metafields on a Shopify product.
     */
    public function setProductMetafields(int $shopifyProductId, array $metafields): bool
    {
        if (empty($metafields)) {
            return true;
        }

        $query = <<<GQL
        mutation metafieldsSet(\$metafields: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: \$metafields) {
                metafields {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        // Retrieve destination store definitions to dynamically map key aliases
        $targetDefs = $this->getMetafieldDefinitions();
        $targetKeysByNamespace = [];
        foreach ($targetDefs as $def) {
            $targetKeysByNamespace[$def['namespace']][$this->normalizeMetafieldKey($def['key'])] = $def['key'];
        }

        // Auto-create missing metafield definitions
        foreach ($metafields as $mf) {
            $ns = $mf['namespace'];
            $key = $mf['key'];
            $normalizedKey = $this->normalizeMetafieldKey($key);

            if (!isset($targetKeysByNamespace[$ns][$normalizedKey])) {
                $name = ucwords(str_replace(['_', '-'], ' ', $key));
                $this->createMetafieldDefinition([
                    'name' => $name,
                    'namespace' => $ns,
                    'key' => $key,
                    'type' => $mf['type'],
                    'ownerType' => 'PRODUCT',
                    'description' => 'Automatically created during sync propagation.'
                ]);

                // Update local definitions cache so we don't try to create it again
                $targetKeysByNamespace[$ns][$normalizedKey] = $key;
            }
        }

        $attempts = 0;
        $maxAttempts = 5;
        $currentMetafields = $metafields;

        while ($attempts < $maxAttempts && !empty($currentMetafields)) {
            $metafieldsArray = [];
            foreach ($currentMetafields as $mf) {
                $ns = $mf['namespace'];
                $key = $mf['key'];
                $normalizedKey = $this->normalizeMetafieldKey($key);

                // If exact key is not defined on target but a normalized match exists, map it!
                if (isset($targetKeysByNamespace[$ns][$normalizedKey])) {
                    $key = $targetKeysByNamespace[$ns][$normalizedKey];
                }

                $metafieldsArray[] = [
                    'ownerId' => "gid://shopify/Product/{$shopifyProductId}",
                    'namespace' => $ns,
                    'key' => $key,
                    'value' => (string) $mf['value'],
                    'type' => $mf['type'],
                ];
            }

            try {
                $result = $this->graph($query, ['metafields' => $metafieldsArray]);
                $userErrors = $result['data']['metafieldsSet']['userErrors'] ?? [];

                if (empty($userErrors)) {
                    return true;
                }

                // Identify which indexes failed
                $failedIndexes = [];
                foreach ($userErrors as $error) {
                    $fieldPath = $error['field'] ?? [];
                    if (count($fieldPath) >= 2 && $fieldPath[0] === 'metafields') {
                        $index = (int) $fieldPath[1];
                        $failedIndexes[] = $index;
                        
                        Log::warning("Shopify metafield sync validation warning: key '{$currentMetafields[$index]['key']}' failed with value '{$currentMetafields[$index]['value']}' on shop {$this->shop->shop_domain}: {$error['message']}");
                    }
                }

                if (empty($failedIndexes)) {
                    // If there are errors but we couldn't parse the indexes, log and return false
                    Log::error("Shopify setProductMetafields failed with unparsable errors", [
                        'errors' => $userErrors,
                        'shopify_product_id' => $shopifyProductId,
                        'shop' => $this->shop->shop_domain,
                    ]);
                    return false;
                }

                // Remove failed indexes from currentMetafields (descending to preserve indices)
                rsort($failedIndexes);
                foreach ($failedIndexes as $idx) {
                    if (isset($currentMetafields[$idx])) {
                        unset($currentMetafields[$idx]);
                    }
                }
                // Re-index array
                $currentMetafields = array_values($currentMetafields);

            } catch (\Exception $e) {
                Log::error("setProductMetafields Exception: " . $e->getMessage());
                return false;
            }

            $attempts++;
        }

        return !empty($currentMetafields);
    }

    /**
     * Update product main image on Shopify via REST Admin API.
     */
    public function updateProductImage(int $shopifyProductId, string $imageUrl, ?string &$errorMsg = null): bool
    {
        if ($this->shop->access_token === 'mock_access_token_123456789' || str_starts_with($this->shop->access_token, 'mock')) {
            return true;
        }

        $this->checkAndRefreshAccessToken();

        $url = "{$this->getBaseUrl()}/products/{$shopifyProductId}.json";

        $payload = [
            'product' => [
                'id' => $shopifyProductId,
                'images' => [
                    [
                        'src' => $imageUrl
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $this->shop->access_token,
                'Content-Type' => 'application/json',
            ])->put($url, $payload);

            if ($response->status() === 429) {
                sleep(2);
                return $this->updateProductImage($shopifyProductId, $imageUrl, $errorMsg);
            }

            if ($response->successful()) {
                return true;
            }

            $errorMsg = "HTTP " . $response->status() . ": " . $response->body();
            Log::error("updateProductImage REST error for product {$shopifyProductId}: {$errorMsg}", [
                'shop' => $this->shop->shop_domain
            ]);
            return false;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error("updateProductImage Exception for product {$shopifyProductId}: {$errorMsg}");
            return false;
        }
    }

    /**
     * Update product details on Shopify.
     */
    public function updateProduct(array $input): bool
    {
        $query = <<<GQL
        mutation productUpdate(\$input: ProductInput!) {
            productUpdate(input: \$input) {
                product {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['input' => $input]);
            $errors = $result['data']['productUpdate']['userErrors'] ?? [];
            if (!empty($errors)) {
                Log::error("Shopify productUpdate errors: " . json_encode($errors), [
                    'shop' => $this->shop->shop_domain
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("updateProduct Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a product variant on Shopify.
     */
    public function updateProductVariant(array $input): bool
    {
        $query = <<<GQL
        mutation productVariantUpdate(\$input: ProductVariantInput!) {
            productVariantUpdate(input: \$input) {
                productVariant {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['input' => $input]);
            $errors = $result['data']['productVariantUpdate']['userErrors'] ?? [];
            if (!empty($errors)) {
                Log::error("Shopify productVariantUpdate errors: " . json_encode($errors), [
                    'shop' => $this->shop->shop_domain
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("updateProductVariant Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a metafield definition on Shopify.
     */
    public function createMetafieldDefinition(array $definition): bool
    {
        $query = <<<GQL
        mutation CreateMetafieldDefinition(\$definition: MetafieldDefinitionInput!) {
            metafieldDefinitionCreate(definition: \$definition) {
                createdDefinition {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;

        try {
            $result = $this->graph($query, ['definition' => $definition]);
            $errors = $result['data']['metafieldDefinitionCreate']['userErrors'] ?? [];
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    if (str_contains($error['message'] ?? '', 'already taken') || str_contains($error['message'] ?? '', 'already exists')) {
                        return true;
                    }
                }
                Log::warning("createMetafieldDefinition errors: " . json_encode($errors), [
                    'shop' => $this->shop->shop_domain
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::error("createMetafieldDefinition Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the shop information (including name).
     */
    public function getShopDetails(): array
    {
        // Mock check
        if ($this->shop->access_token === 'mock_access_token_123456789' || str_starts_with($this->shop->access_token, 'mock')) {
            return ['name' => 'Mock Store ' . ucfirst(explode('.', $this->shop->shop_domain)[0])];
        }

        $query = <<<GQL
        query {
            shop {
                name
                email
                myshopifyDomain
            }
        }
        GQL;

        try {
            $response = $this->graph($query);
            return $response['data']['shop'] ?? [];
        } catch (\Exception $e) {
            Log::error("ShopifyClient getShopDetails Exception: " . $e->getMessage());
            return [];
        }
    }
}



