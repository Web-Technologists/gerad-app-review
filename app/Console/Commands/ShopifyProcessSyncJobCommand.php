<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SyncJob;
use App\Jobs\BulkImportCsvJob;
use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Services\ProductSyncService;

class ShopifyProcessSyncJobCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:process-sync-job {job_id} {--target-shops=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process a pending SyncJob asynchronously in background without web request timeouts';

    /**
     * Execute the console command.
     */
    public function handle(
        ShopRepositoryInterface $shopRepository,
        ProductRepositoryInterface $productRepository,
        ProductSyncService $syncService
    ): int {
        $jobId = $this->argument('job_id');
        $syncJob = SyncJob::find($jobId);

        if (!$syncJob) {
            $this->error("SyncJob #{$jobId} not found.");
            return 1;
        }

        $targetShopsStr = $this->option('target-shops');
        $targetShopIds = !empty($targetShopsStr) ? explode(',', $targetShopsStr) : null;

        $this->info("Processing SyncJob #{$jobId} in background...");

        $job = new BulkImportCsvJob($syncJob, $targetShopIds);
        $job->handle($shopRepository, $productRepository, $syncService);

        $this->info("SyncJob #{$jobId} completed successfully.");
        return 0;
    }
}
