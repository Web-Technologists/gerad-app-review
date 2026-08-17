<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class ShopifyOtherProductsReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:other-products-report {--csv-path= : Optional custom path to save the CSV report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate counts/reports for products other than those in Category.xlsx and export them to a CSV.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $excelPath = base_path('Category.xlsx');
        if (!file_exists($excelPath)) {
            $this->error("Category.xlsx not found at base path: {$excelPath}");
            return Command::FAILURE;
        }

        $this->info("Parsing Category.xlsx to load Master Categories...");

        // Run python script to extract unique category names
        $pythonCmd = 'python3 -c "import openpyxl, json; wb = openpyxl.load_workbook(\'' . addslashes($excelPath) . '\'); sheet = wb.active; rows = list(sheet.iter_rows(values_only=True))[3:]; cats = set(); [cats.update([str(cell).strip().lower() for cell in r if cell]) for r in rows]; print(json.dumps(list(cats)))"';
        
        $output = shell_exec($pythonCmd);
        if (empty($output)) {
            $this->error("Failed to parse Category.xlsx. Make sure python3 and openpyxl are installed.");
            return Command::FAILURE;
        }

        $excelCategories = json_decode($output, true);
        if (!is_array($excelCategories)) {
            $this->error("Failed to decode JSON categories from parser output.");
            return Command::FAILURE;
        }

        $this->info("Loaded " . count($excelCategories) . " master category values from Excel.");

        // Query database products with shop relation
        $allProducts = Product::with('shop')->get();
        if ($allProducts->isEmpty()) {
            $this->warn("No products found in the database.");
            return Command::SUCCESS;
        }

        $otherProducts = [];
        foreach ($allProducts as $product) {
            $type = trim($product->product_type ?? '');
            $typeLower = strtolower($type);
            
            // If the product type is NOT in the excel categories list, it's an "other" product
            if (!in_array($typeLower, $excelCategories)) {
                $otherProducts[] = $product;
            }
        }

        $rawTotal = count($otherProducts);
        if ($rawTotal === 0) {
            $this->info("\nNo products with other product types found in the database.");
            return Command::SUCCESS;
        }

        // Deduplicate products across stores by handle (fallback to title)
        $uniqueHandles = [];
        $uniqueOtherProducts = [];
        foreach ($otherProducts as $product) {
            $handle = $product->handle ?: $product->title;
            if (!in_array($handle, $uniqueHandles)) {
                $uniqueHandles[] = $handle;
                $uniqueOtherProducts[] = $product;
            }
        }
        $uniqueTotal = count($uniqueOtherProducts);

        // Grouping by status
        $statusRaw = [];
        $statusUnique = [];
        foreach ($otherProducts as $product) {
            $status = $product->status ?: 'unknown';
            $statusRaw[$status] = ($statusRaw[$status] ?? 0) + 1;
        }
        foreach ($uniqueOtherProducts as $product) {
            $status = $product->status ?: 'unknown';
            $statusUnique[$status] = ($statusUnique[$status] ?? 0) + 1;
        }

        // Grouping by store
        $storeRaw = [];
        $storeUnique = [];
        foreach ($otherProducts as $product) {
            $domain = $product->shop->shop_domain ?? 'Unknown Store';
            $storeRaw[$domain] = ($storeRaw[$domain] ?? 0) + 1;
        }
        foreach ($uniqueOtherProducts as $product) {
            $domain = $product->shop->shop_domain ?? 'Unknown Store';
            $storeUnique[$domain] = ($storeUnique[$domain] ?? 0) + 1;
        }

        // Grouping by product type
        $typeRaw = [];
        $typeUnique = [];
        foreach ($otherProducts as $product) {
            $type = $product->product_type ?: '[Empty / Null]';
            $typeRaw[$type] = ($typeRaw[$type] ?? 0) + 1;
        }
        foreach ($uniqueOtherProducts as $product) {
            $type = $product->product_type ?: '[Empty / Null]';
            $typeUnique[$type] = ($typeUnique[$type] ?? 0) + 1;
        }

        $this->newLine();
        $this->info("=================================================");
        $this->info("      OTHER PRODUCTS REPORT (NOT IN EXCEL)       ");
        $this->info("=================================================");

        // Table 1: Overall Counts
        $this->comment("\n1. Overall Product Counts:");
        $this->table(
            ['Metric', 'Raw Count (All Stores)', 'Unique Count (De-duplicated)'],
            [
                ['Total Products', $rawTotal, $uniqueTotal]
            ]
        );

        // Table 2: Counts by Status (Active/Draft/Archived)
        $this->comment("\n2. Counts by Product Status:");
        $statusRows = [];
        $allStatuses = array_unique(array_merge(array_keys($statusRaw), array_keys($statusUnique)));
        sort($allStatuses);
        foreach ($allStatuses as $status) {
            $statusRows[] = [
                ucfirst($status),
                $statusRaw[$status] ?? 0,
                $statusUnique[$status] ?? 0
            ];
        }
        $this->table(['Status', 'Raw Count', 'Unique Count'], $statusRows);

        // Table 3: Counts by Store
        $this->comment("\n3. Counts by Store:");
        $storeRows = [];
        $allStores = array_keys($storeRaw);
        sort($allStores);
        foreach ($allStores as $store) {
            $storeRows[] = [
                $store,
                $storeRaw[$store] ?? 0
            ];
        }
        $this->table(['Store Domain', 'Product Count'], $storeRows);

        // Table 4: Counts by Other Product Type
        $this->comment("\n4. Counts by Other Product Type:");
        $typeRows = [];
        $allTypes = array_unique(array_merge(array_keys($typeRaw), array_keys($typeUnique)));
        sort($allTypes);
        foreach ($allTypes as $type) {
            $typeRows[] = [
                $type,
                $typeRaw[$type] ?? 0,
                $typeUnique[$type] ?? 0
            ];
        }
        $this->table(['Product Type', 'Raw Count', 'Unique Count'], $typeRows);

        // Export to CSV
        $csvPath = $this->option('csv-path');
        if (empty($csvPath)) {
            $dir = storage_path('app/reports');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $csvPath = $dir . '/other_products_' . time() . '.csv';
        } else {
            $dir = dirname($csvPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->comment("\nGenerating CSV export...");
        
        $csvFile = fopen($csvPath, 'w');
        if (!$csvFile) {
            $this->error("Failed to open file for writing CSV: {$csvPath}");
            return Command::FAILURE;
        }

        // CSV Headers
        fputcsv($csvFile, [
            'Store Domain',
            'Store Name',
            'Product ID',
            'Product Title',
            'Product Handle',
            'Vendor',
            'Product Type',
            'Status',
            'UPI Code',
            'UPI Status',
            'Item Category',
            'Updated At'
        ]);

        // CSV Rows
        foreach ($otherProducts as $product) {
            fputcsv($csvFile, [
                $product->shop->shop_domain ?? '',
                $product->shop->shop_name ?? '',
                $product->shopify_product_id ?? '',
                $product->title ?? '',
                $product->handle ?? '',
                $product->vendor ?? '',
                $product->product_type ?? '',
                $product->status ?? '',
                $product->upi_code ?? '',
                $product->upi_status ?? '',
                $product->item_category ?? '',
                $product->last_updated_at ? $product->last_updated_at->toIso8601String() : ($product->updated_at ? $product->updated_at->toIso8601String() : '')
            ]);
        }

        fclose($csvFile);

        $this->newLine();
        $this->info("=================================================");
        $this->info(" SUCCESS: CSV exported successfully!");
        $this->line("Path: <comment>{$csvPath}</comment>");
        $this->info("=================================================");
        $this->newLine();

        return Command::SUCCESS;
    }
}
