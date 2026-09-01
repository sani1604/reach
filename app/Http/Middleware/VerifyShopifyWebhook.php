<?php

namespace App\Http\Middleware;

use App\Services\ShopifyWebhook;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->getContent();
        $hmac = $request->header('X-Shopify-Hmac-Sha256');

        if (! ShopifyWebhook::verify($raw, $hmac)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
