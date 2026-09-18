<?php

namespace App\Http\Middleware;

use App\Services\ShopifyApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->getContent();
        $hmac = $request->header('X-Shopify-Hmac-Sha256');

        // Try every configured Partner app secret — both public + private apps
        // share this /webhooks endpoint when running on one codebase.
        $appKey = ShopifyApp::appKeyFromWebhookHmac($raw, $hmac);
        if (! $appKey) {
            abort(401, 'Invalid webhook signature.');
        }

        ShopifyApp::setKey($appKey);
        $request->attributes->set('shopify_app_key', $appKey);

        return $next($request);
    }
}
