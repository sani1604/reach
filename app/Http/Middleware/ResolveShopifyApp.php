<?php

namespace App\Http\Middleware;

use App\Services\ShopifyApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pick which Partner app (reach / pixelai) owns this request and bind its
 * Client ID / Secret / branding into config for the rest of the stack.
 */
class ResolveShopifyApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = ShopifyApp::resolveFromRequest($request);
        ShopifyApp::setKey($key);

        return $next($request);
    }
}
