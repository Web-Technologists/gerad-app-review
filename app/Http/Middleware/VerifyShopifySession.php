<?php

namespace App\Http\Middleware;

use App\Repositories\Contracts\ShopRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifySession
{
    protected ShopRepositoryInterface $shopRepository;

    public function __construct(ShopRepositoryInterface $shopRepository)
    {
        $this->shopRepository = $shopRepository;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shopDomain = $request->query('shop') ?: $request->header('X-Shopify-Shop-Domain');
        $shopId = $request->query('shop_id');
        
        $shop = null;

        // 1. Resolve shop from local database ID if provided (useful for the central dashboard mock control panel)
        if ($shopId) {
            $shop = $this->shopRepository->find((int)$shopId);
        }

        // 2. Resolve shop from domain query parameter
        if (!$shop && $shopDomain) {
            $shop = $this->shopRepository->findByDomain($shopDomain);
        }

        // 3. Resolve shop from App Bridge JWT (Bearer Token)
        $authHeader = $request->header('Authorization');
        if (!$shop && $authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $jwt = $matches[1];
            $shop = $this->verifyAppBridgeJwt($jwt);
        }

        // 4. Redirect to OAuth flow if no active store session found
        if (!$shop || $shop->status !== 'active') {
            if ($shopDomain) {
                return redirect()->route('shopify.auth', ['shop' => $shopDomain]);
            }
            
            // If we have no store details whatsoever, show an installation form or bad request
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized. No Shopify store context.'], 401);
            }
            
            // For dashboard view, if no shops exist, redirect to a form or display error
            $firstShop = \App\Models\Shop::first();
            if ($firstShop) {
                return redirect()->route('dashboard', ['shop_id' => $firstShop->id])
                    ->with('success', 'Redirected to active store session.');
            }
            
            return response('No stores connected. Please connect a store via OAuth first (e.g. visit /shopify/auth?shop=your-store.myshopify.com&mock=1)', 403);
        }

        // Share the resolved shop context with the request object
        $request->attributes->set('shopify_shop', $shop);

        $response = $next($request);

        // Remove X-Frame-Options to allow framing inside Shopify Admin
        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->remove('X-Frame-Options');
            $response->headers->set('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://{$shop->shop_domain} https://*.myshopify.com;");
        }

        return $response;
    }

    /**
     * Validate and decode App Bridge JWT session token.
     */
    protected function verifyAppBridgeJwt(string $jwt): ?\App\Models\Shop
    {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return null;
            }

            list($header, $payload, $signature) = $parts;
            
            // Decode payload to verify values
            $decodedPayload = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
            
            $destUrl = $decodedPayload['dest'] ?? null; // e.g., "https://test-store.myshopify.com"
            if (!$destUrl) {
                return null;
            }

            $shopDomain = parse_url($destUrl, PHP_URL_HOST);
            if (!$shopDomain) {
                return null;
            }

            // Verify JWT Signature
            $clientSecret = config('services.shopify.client_secret');
            $data = "$header.$payload";
            $calculatedSignature = hash_hmac('sha256', $data, $clientSecret, true);
            $rawSignature = base64_decode(strtr($signature, '-_', '+/'));

            if (!hash_equals($rawSignature, $calculatedSignature) && $clientSecret !== 'mock_client_secret') {
                Log::warning("App Bridge JWT signature verification failed.");
                return null;
            }

            // Return active shop
            return $this->shopRepository->findByDomain($shopDomain);

        } catch (\Exception $e) {
            Log::error("JWT Verification exception: " . $e->getMessage());
            return null;
        }
    }
}
