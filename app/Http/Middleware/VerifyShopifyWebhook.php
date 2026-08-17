<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $data = $request->getContent(); // Raw body

        if (!$hmacHeader || !$topic || !$shopDomain) {
            Log::warning("VerifyShopifyWebhook: Missing required headers.", [
                'hmac' => (bool)$hmacHeader,
                'topic' => $topic,
                'shop' => $shopDomain,
            ]);
            return response()->json(['error' => 'Missing required Shopify webhook headers.'], 400);
        }

        // Validate HMAC signature
        $clientSecret = config('services.shopify.client_secret');

        // Bypassed for developer convenience if using default mock secret key
        if ($clientSecret !== 'mock_client_secret') {
            $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $clientSecret, true));
            
            if (!hash_equals($hmacHeader, $calculatedHmac)) {
                Log::error("VerifyShopifyWebhook: Signature verification failed.", [
                    'topic' => $topic,
                    'shop' => $shopDomain,
                ]);
                return response()->json(['error' => 'HMAC signature verification failed.'], 401);
            }
        }

        return $next($request);
    }
}
