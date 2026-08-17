<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShopifyImportCategoriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:import-categories';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import product categories from Category.xlsx file into the database.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("Parsing Category.xlsx using Python openpyxl...");

        $scriptPath = base_path('app/Console/Commands/parse_categories.py');
        
        // Find python executable (try python3 first, then python)
        $python = 'python3';
        exec("which python3", $out3, $status3);
        if ($status3 !== 0) {
            $python = 'python';
        }

        $command = escapeshellcmd("{$python} {$scriptPath}");
        $output = shell_exec($command);

        if (!$output) {
            $this->error("No output returned from Python parser script.");
            return Command::FAILURE;
        }

        $data = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Failed to decode JSON from Python script. Raw output: " . substr($output, 0, 500));
            return Command::FAILURE;
        }

        if (isset($data['error'])) {
            $this->error("Python error: " . $data['error']);
            return Command::FAILURE;
        }

        $this->info("Found " . count($data) . " categories. Seeding database table 'product_categories'...");

        $bar = $this->output->createProgressBar(count($data));
        $bar->start();

        // Clear existing categories
        ProductCategory::truncate();

        DB::transaction(function () use ($data, $bar) {
            foreach ($data as $item) {
                ProductCategory::create([
                    'name' => $item['name'],
                    'parent' => $item['parent'],
                    'child' => $item['child'],
                ]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nCategories imported successfully!");

        return Command::SUCCESS;
    }
}
