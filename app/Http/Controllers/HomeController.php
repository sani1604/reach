<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ShopifyRequest;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Smart entry point — also the application_url target.
     *
     * When Shopify loads the app inside the admin iframe it appends
     * ?shop=&host=. OAuth redirects are blocked inside that iframe, so the
     * merchant is handed to the embedded boot page: it authenticates with an
     * App Bridge session token (token exchange / managed installation) and
     * then navigates to the dashboard. Without a shop param this is the
     * public landing page.
     */
    public function index(Request $request)
    {
        $domain = ShopifyRequest::shopDomain($request);

        if ($domain) {
            return app(ShopifyAuthController::class)->boot($request);
        }

        return $this->landing($request);
    }

    public function landing(Request $request)
    {
        return view('landing');
    }
}
