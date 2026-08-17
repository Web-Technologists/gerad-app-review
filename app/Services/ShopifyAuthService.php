<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyAuthService
{
    /**
     * Generate the authorization redirect URL for a shop.
     */
    public function getAuthorizeUrl(string $shopDomain, string $state): string
    {
        $clientId = config('services.shopify.client_id');
        $scopes = config('services.shopify.scopes');
        
        $appHost = config('services.shopify.app_host');
        if ($appHost) {
            $redirectUri = "https://{$appHost}/shopify/callback";
        } else {
            $redirectUri = secure_url('/shopify/callback');
        }

        return "https://{$shopDomain}/admin/oauth/authorize?" . http_build_query([
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Exchange the temporary authorization code for a permanent offline access token.
     */
    public function exchangeCode(string $shopDomain, string $code): array
    {
        $clientId = config('services.shopify.client_id');
        $clientSecret = config('services.shopify.client_secret');

        $response = Http::post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'expiring' => 1,
        ]);

        if (!$response->successful()) {
            Log::error("ShopifyAuthService: Failed to retrieve access token.", [
                'shop' => $shopDomain,
                'response' => $response->body()
            ]);
            throw new \Exception("Failed to exchange Shopify authorization code: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Validate the HMAC signature for incoming Shopify callbacks.
     */
    public function verifyCallbackHmac(array $params): bool
    {
        $hmac = $params['hmac'] ?? '';
        unset($params['hmac']);

        // Sort keys alphabetically
        ksort($params);

        // Join query parameters
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }
        $queryString = implode('&', $pairs);

        $clientSecret = config('services.shopify.client_secret');
        $calculatedHmac = hash_hmac('sha256', $queryString, $clientSecret);

        return hash_equals($hmac, $calculatedHmac);
    }
}
