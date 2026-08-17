<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Jobs\SyncProductJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    protected ShopRepositoryInterface $shopRepository;

    public function __construct(ShopRepositoryInterface $shopRepository)
    {
        $this->shopRepository = $shopRepository;
    }

    /**
     * Handle incoming webhooks from Shopify.
     * Route: POST /api/webhooks
     */
    public function handle(Request $request)
    {
        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $data = $request->getContent(); // Raw payload

        // Find the corresponding Shop using Repository
        $shop = $this->shopRepository->findByDomain($shopDomain);
        if (!$shop) {
            Log::warning("ShopifyWebhookController: Webhook received for untracked store: {$shopDomain}");
            return response()->json(['error' => 'Store not found.'], 404);
        }

        $payload = json_decode($data, true);

        // Dispatch specific queue jobs based on event topic
        switch ($topic) {
            case 'products/create':
            case 'products/update':
                SyncProductJob::dispatch($shop, $payload, 'update');
                break;

            case 'products/delete':
                SyncProductJob::dispatch($shop, $payload, 'delete');
                break;

            case 'app/uninstalled':
                $this->shopRepository->updateOrCreate(
                    ['id' => $shop->id],
                    ['status' => 'uninstalled']
                );
                Log::info("ShopifyWebhookController: App uninstalled for: {$shopDomain}");
                break;

            default:
                Log::info("ShopifyWebhookController: Unhandled topic: {$topic}");
                break;
        }

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Handle GDPR Customer Data Request.
     * Route: POST /api/webhooks/customers/data_request
     */
    public function customersDataRequest(Request $request)
    {
        Log::info("ShopifyWebhookController: GDPR customer data request received.");
        return response()->json(['status' => 'acknowledged'], 200);
    }

    /**
     * Handle GDPR Customer Redact Request.
     * Route: POST /api/webhooks/customers/redact
     */
    public function customersRedact(Request $request)
    {
        Log::info("ShopifyWebhookController: GDPR customer redact request received.");
        return response()->json(['status' => 'acknowledged'], 200);
    }

    /**
     * Handle GDPR Shop Redact Request.
     * Route: POST /api/webhooks/shop/redact
     */
    public function shopRedact(Request $request)
    {
        Log::info("ShopifyWebhookController: GDPR shop redact request received.");
        return response()->json(['status' => 'acknowledged'], 200);
    }
}
