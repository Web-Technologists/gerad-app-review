<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\ShopRepositoryInterface;
use App\Services\ShopifyAuthService;
use App\Jobs\StoreProvisioningJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyOAuthController extends Controller
{
    protected ShopRepositoryInterface $shopRepository;
    protected ShopifyAuthService $authService;

    public function __construct(ShopRepositoryInterface $shopRepository, ShopifyAuthService $authService)
    {
        $this->shopRepository = $shopRepository;
        $this->authService = $authService;
    }

    /**
     * Start the Shopify OAuth install flow.
     * Route: GET /shopify/auth
     */
    public function auth(Request $request)
    {
        $shopDomain = $request->query('shop');
        $isMock = $request->query('mock', false);

        if (!$shopDomain) {
            return response()->json(['error' => 'Missing shop parameter.'], 400);
        }

        $shopDomain = strtolower(trim($shopDomain));
        if (!preg_match('/^[a-zA-Z0-9.-]+\.myshopify\.com$/', $shopDomain)) {
            return response()->json(['error' => 'Invalid Shopify shop domain.'], 400);
        }

        // Demo Simulator bypass
        if ($isMock) {
            return $this->handleMockInstallation($shopDomain);
        }

        $authorizeUrl = $this->authService->getAuthorizeUrl($shopDomain, csrf_token());

        // Escape iframe to redirect top-level window to Shopify OAuth page
        return response()->make("
            <!DOCTYPE html>
            <html>
                <head>
                    <script type='text/javascript'>
                        window.top.location.href = '" . addslashes($authorizeUrl) . "';
                    </script>
                </head>
                <body>
                    <p>Redirecting to Shopify authorization...</p>
                </body>
            </html>
        ");
    }

    /**
     * Handle Shopify OAuth callback.
     * Route: GET /shopify/callback
     */
    public function callback(Request $request)
    {
        $params = $request->all();
        
        if (!isset($params['shop'], $params['code'], $params['hmac'])) {
            return response()->json(['error' => 'Invalid callback parameters.'], 400);
        }

        $shopDomain = $params['shop'];
        $code = $params['code'];

        // 1. Verify HMAC signature
        if (!$this->authService->verifyCallbackHmac($params)) {
            Log::error("ShopifyOAuthController: HMAC verification failed.", $params);
            return response()->json(['error' => 'HMAC signature verification failed.'], 403);
        }

        try {
            // 2. Exchange temp code for access token
            $tokenData = $this->authService->exchangeCode($shopDomain, $code);
            $accessToken = $tokenData['access_token'];
            $scopes = explode(',', $tokenData['scope'] ?? '');

            // 3. Store shop credentials using Repository
            $shop = $this->shopRepository->updateOrCreate(
                ['shop_domain' => $shopDomain],
                [
                    'access_token' => $accessToken,
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds((int)$tokenData['expires_in']) : null,
                    'scopes' => $scopes,
                    'status' => 'active',
                ]
            );

            // 4. Dispatch Store Provisioning Job
            StoreProvisioningJob::dispatch($shop);

            $clientId = config('services.shopify.client_id');

            // Redirect to embedded Shopify dashboard
            return redirect()->away("https://admin.shopify.com/store/" . str_replace('.myshopify.com', '', $shopDomain) . "/apps/" . $clientId);

        } catch (\Exception $e) {
            Log::error("ShopifyOAuthController: " . $e->getMessage());
            
            // Handle scenario where authorization code is already used / expired due to a page refresh or duplicate request,
            // but we already have a successfully authorized shop in the database.
            $existingShop = $this->shopRepository->findByDomain($shopDomain);
            if ($existingShop && $existingShop->access_token && $existingShop->status === 'active') {
                Log::info("ShopifyOAuthController: Redirecting existing active shop {$shopDomain} after code exchange failure (likely code already used).");
                $clientId = config('services.shopify.client_id');
                return redirect()->away("https://admin.shopify.com/store/" . str_replace('.myshopify.com', '', $shopDomain) . "/apps/" . $clientId);
            }

            return response()->json(['error' => 'Installation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Set up a simulated store configuration for testing and local demos.
     */
    protected function handleMockInstallation(string $shopDomain)
    {
        // Setup shop using Repository
        $shop = $this->shopRepository->updateOrCreate(
            ['shop_domain' => $shopDomain],
            [
                'custom_domain' => "www." . str_replace('.myshopify.com', '.com', $shopDomain),
                'access_token' => 'mock_access_token_123456789',
                'scopes' => ['read_products', 'write_products'],
                'status' => 'active',
                'shop_name' => 'Mock Store ' . ucfirst(explode('.', $shopDomain)[0]),
            ]
        );

        StoreProvisioningJob::dispatch($shop, true);

        return redirect()->route('dashboard', ['shop_id' => $shop->id])
            ->with('success', "Simulated Store '{$shopDomain}' successfully connected in under 2 seconds!");
    }
}
