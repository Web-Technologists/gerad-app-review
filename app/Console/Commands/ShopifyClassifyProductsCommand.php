<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Jobs\BulkPushUpiToShopifyJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyClassifyProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:classify-products 
                            {shop_domain? : Optional. Specific store domain to run for.}
                            {--force : Force categorization on all products, overriding existing categories}
                            {--limit= : Limit the number of products to process (for debugging)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically classify products into correct categories using Google Gemini AI, based on Category.xlsx.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = config('services.gemini.key');

        if (!$apiKey) {
            $this->error("GEMINI_API_KEY is not set in your config or .env file.");
            $this->error("Please get a Gemini API Key from Google AI Studio and configure GEMINI_API_KEY=your_key in your .env file.");
            return Command::FAILURE;
        }

        // Check if categories are imported
        $categoryCount = ProductCategory::count();
        if ($categoryCount === 0) {
            $this->error("No categories found in the product_categories table.");
            $this->error("Please run: php artisan shopify:import-categories first to load categories from Category.xlsx.");
            return Command::FAILURE;
        }

        $this->info("Loaded {$categoryCount} valid categories from database.");

        $shopDomain = $this->argument('shop_domain');
        if ($shopDomain) {
            // Extract domain/host if a URL is provided
            $cleanDomain = preg_replace('/^https?:\/\//', '', $shopDomain);
            $cleanDomain = explode('/', $cleanDomain)[0];
            $cleanDomain = trim($cleanDomain);

            $shops = Shop::where('shop_domain', $shopDomain)
                ->orWhere('shop_domain', 'like', "%{$cleanDomain}%")
                ->get();
            if ($shops->isEmpty()) {
                $this->error("Shop with domain matching '{$shopDomain}' (cleaned: '{$cleanDomain}') not found in the database.");
                return Command::FAILURE;
            }
        } else {
            $shops = Shop::all();
            if ($shops->isEmpty()) {
                $this->info("No shops found in the database.");
                return Command::SUCCESS;
            }
        }

        // Get list of valid category paths
        $validCategories = ProductCategory::pluck('name')->toArray();

        $updatedShopsReport = [];

        foreach ($shops as $shop) {
            $this->info("\nProcessing store: {$shop->shop_domain}...");
            $updatedCount = $this->classifyStoreProducts($shop, $validCategories, $apiKey);
            if ($updatedCount > 0) {
                $updatedShopsReport[] = [
                    'shop_domain' => $shop->shop_domain,
                    'updated_count' => $updatedCount
                ];
            }
        }

        $this->info("\nAll classification processes finished.");

        if (!empty($updatedShopsReport)) {
            $this->info("\nStores with product type updates:");
            $this->table(
                ['Shop Domain', 'Updated Products Count'],
                $updatedShopsReport
            );
        } else {
            $this->info("\nNo stores had product types updated.");
        }

        return Command::SUCCESS;
    }

    /**
     * Classify products for a single store.
     */
    protected function classifyStoreProducts(Shop $shop, array $validCategories, string $apiKey): int
    {
        $force = $this->option('force');
        $limit = $this->option('limit');

        $query = Product::where('shop_id', $shop->id)
            ->whereNotNull('shopify_product_id');

        if (!$force) {
            // Only classify if product_type is empty, null, matching placeholder values, or not in the valid categories list
            $placeholders = ['DEFAULT', 'AMBASSADOR', 'APPAREL', 'RETAIL'];
            $query->where(function ($q) use ($placeholders, $validCategories) {
                $q->whereNull('product_type')
                  ->orWhere('product_type', '')
                  ->orWhereIn(DB::raw('UPPER(product_type)'), $placeholders)
                  ->orWhereNotIn('product_type', $validCategories);
            });
        }

        if ($limit) {
            $query->limit((int)$limit);
        }

        // Disable query log to save memory
        DB::disableQueryLog();

        $productIds = $query->pluck('id')->toArray();

        if (empty($productIds)) {
            $this->info("No products matching classification criteria for {$shop->shop_domain}.");
            return 0;
        }

        $count = count($productIds);
        $this->info("Found {$count} products whose product type does not match the category list from Category.xlsx.");

        if (!$this->confirm("Are you sure you want to proceed with auto-classifying these {$count} products for {$shop->shop_domain} using Gemini AI?", true)) {
            $this->info("Cancelled processing for {$shop->shop_domain}.");
            return 0;
        }

        $isMock = $shop->access_token === 'mock_access_token_123456789' || str_starts_with($shop->access_token ?? '', 'mock');
        $logRows = [];

        if ($isMock) {
            $this->info("[Mock Path] Mocking classification results...");
            $sampleCat = $validCategories[0] ?? 'Apparel > Aprons';
            
            $chunksOfIds = array_chunk($productIds, 500);
            foreach ($chunksOfIds as $chunkIds) {
                $productsBatch = Product::whereIn('id', $chunkIds)->get();
                DB::transaction(function () use ($productsBatch, $sampleCat, &$logRows) {
                    foreach ($productsBatch as $p) {
                        $previousType = $p->product_type;
                        $p->update([
                            'product_type' => $sampleCat,
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                            'last_updated_by' => 'AI Classifier',
                            'last_updated_at' => now(),
                        ]);
                        $logRows[] = [
                            'product_id' => $p->shopify_product_id,
                            'product_name' => $p->title,
                            'previous_type' => $previousType ?: 'None',
                            'new_type' => $sampleCat,
                        ];
                    }
                });
                unset($productsBatch);
            }
            
            $this->writeLogFile($shop, $logRows);
            $this->info("Successfully mock-classified " . $count . " products.");
            return $count;
        }

        $idChunks = array_chunk($productIds, 50);
        $totalProcessed = 0;

        $this->info("Sending batches to Gemini API...");

        foreach ($idChunks as $idChunk) {
            $chunk = Product::whereIn('id', $idChunk)->get();
            $this->info("Processing batch of " . $chunk->count() . " items...");
            $results = $this->callGeminiApi($chunk, $validCategories, $apiKey);

            if (empty($results)) {
                $this->error("Failed to get response for this batch. Skipping.");
                unset($chunk);
                continue;
            }

            $dbUpdates = [];
            $jobUpdates = [];

            foreach ($chunk as $product) {
                $matchedCategory = $results[$product->id] ?? null;

                if ($matchedCategory) {
                    $matchedCategory = trim($matchedCategory);
                    
                    // Verify that the matched category is in our valid categories list
                    if (!in_array($matchedCategory, $validCategories)) {
                        Log::warning("AI Classifier: Matched category '{$matchedCategory}' is not in the valid categories list for Product ID: {$product->id}. Skipping.");
                        continue;
                    }

                    $previousType = $product->product_type;

                    $product->update([
                        'product_type' => $matchedCategory,
                        'last_updated_by' => 'AI Classifier',
                        'last_updated_at' => now(),
                        'sync_status' => 'pending_push',
                    ]);

                    $logRows[] = [
                        'product_id' => $product->shopify_product_id,
                        'product_name' => $product->title,
                        'previous_type' => $previousType ?: 'None',
                        'new_type' => $matchedCategory,
                    ];

                    $jobUpdates[] = [
                        'shopify_product_id' => $product->shopify_product_id,
                        'upi_code' => $product->upi_code,
                        'upi_status' => $product->upi_status ?: 'Active',
                    ];

                    $totalProcessed++;
                } else {
                    Log::warning("AI Classifier: No match for Product ID: {$product->id}");
                }
            }

            if (!empty($jobUpdates)) {
                BulkPushUpiToShopifyJob::dispatch($jobUpdates, 'AI Classifier');
            }

            $this->info("Completed batch. Classified: " . count($jobUpdates) . " products.");
            
            unset($chunk);
            gc_collect_cycles();
            
            // Sleep briefly to be nice to Gemini rate limits (Gemini 2.5 Flash has 15 RPM free limit)
            sleep(4);
        }

        if (!empty($logRows)) {
            $this->writeLogFile($shop, $logRows);
        }

        $this->info("Finished classification for {$shop->shop_domain}. Classified: {$totalProcessed} products.");
        return $totalProcessed;
    }

    /**
     * Write classification history log file.
     */
    protected function writeLogFile(Shop $shop, array $logRows): void
    {
        try {
            $safeDomain = str_replace([':', '/'], '_', $shop->shop_domain);
            $filename = "classification/{$safeDomain}_" . now()->format('Ymd_His') . ".csv";

            // Ensure directory exists
            \Illuminate\Support\Facades\Storage::makeDirectory('classification');

            $csvContent = "Product ID,Product Name,Previous Product Type,New Product Type\n";
            foreach ($logRows as $row) {
                $escapedTitle = str_replace('"', '""', $row['product_name']);
                $csvContent .= "{$row['product_id']},\"{$escapedTitle}\",\"{$row['previous_type']}\",\"{$row['new_type']}\"\n";
            }

            \Illuminate\Support\Facades\Storage::put($filename, $csvContent);
            $absolutePath = \Illuminate\Support\Facades\Storage::path($filename);
            $this->info("Saved classification CSV log to: {$absolutePath}");
        } catch (\Exception $e) {
            Log::error("Failed to write classification log file: " . $e->getMessage());
            $this->error("Failed to save classification CSV log: " . $e->getMessage());
        }
    }

    /**
     * Call the Gemini API to classify a batch of products.
     */
    protected function callGeminiApi($productsBatch, array $validCategories, string $apiKey): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        // Format valid categories list
        $categoriesString = implode("\n", $validCategories);

        // Format product batch list
        $productsList = "";
        foreach ($productsBatch as $p) {
            $title = str_replace('"', "'", $p->title);
            $vendor = str_replace('"', "'", $p->vendor ?: 'Unknown');
            $type = str_replace('"', "'", $p->product_type ?: 'Unknown');
            $productsList .= "- ID: {$p->id}, Title: \"{$title}\", Vendor: \"{$vendor}\", Type: \"{$type}\"\n";
        }

        $prompt = <<<TEXT
You are an e-commerce taxonomy expert. Your task is to classify a list of products into the most appropriate category path selected STRICTLY from the provided VALID CATEGORIES LIST.

DO NOT invent or generate any new categories. You MUST choose the closest or best matching category from the provided VALID CATEGORIES LIST.
If a product has multiple possible categories, pick the one that fits best.

VALID CATEGORIES LIST:
{$categoriesString}

PRODUCTS TO CLASSIFY:
{$productsList}

You must return a JSON array of objects, where each object has:
- "id": the integer database ID of the product.
- "category": the exact string path from the VALID CATEGORIES LIST (e.g. "Apparel > Aprons").

Example Output:
[
  {"id": 123, "category": "Apparel > Aprons"},
  {"id": 124, "category": "Drinkware > Coffee Mugs"}
]
TEXT;

        try {
            $response = Http::timeout(120)->retry(3, 2000)->withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json'
                ]
            ]);

            if (!$response->successful()) {
                Log::error("Gemini API Error Response: " . $response->body());
                return [];
            }

            $body = $response->json();
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($text)) {
                Log::error("Gemini API returned an empty text field.");
                return [];
            }

            $classificationList = json_decode(trim($text), true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($classificationList)) {
                Log::error("Gemini API did not return valid JSON array. Raw output: " . $text);
                return [];
            }

            // Map results to key by Product ID
            $resultsMap = [];
            foreach ($classificationList as $item) {
                if (isset($item['id'], $item['category'])) {
                    $resultsMap[(int)$item['id']] = trim($item['category']);
                }
            }

            return $resultsMap;

        } catch (\Exception $e) {
            Log::error("Exception in callGeminiApi: " . $e->getMessage());
            return [];
        }
    }
}
