<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ShopifyRequest;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Smart entry point. When loaded inside the Shopify admin (embedded)
     * for an installed store, jump straight to the dashboard. Otherwise
     * show the public landing page.
     */
    public function index(Request $request)
    {
        $domain = ShopifyRequest::shopDomain($request);

        if ($domain) {
            $shop = Shop::where('shopify_domain', $domain)->first();
            if ($shop && $shop->isInstalled()) {
                return redirect()->route('dashboard');
            }

            return redirect()->route('auth.install', ['shop' => $domain]);
        }

        return $this->landing($request);
    }

    public function landing(Request $request)
    {
        return view('landing');
    }
}
