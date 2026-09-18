<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Shopify Partner app
    |--------------------------------------------------------------------------
    | One codebase, two Partner Dashboard apps:
    |
    |   reach   → private / custom install  "Reach — OpenAI Ads Pixel"
    |   pixelai → public App Store listing  "PixelAI: ChatGPT & OpenAI Ads"
    |
    | Set SHOPIFY_APP=reach|pixelai per deployment (or leave blank and resolve
    | from the request host / session-token audience — see ShopifyApp).
    */
    'app' => env('SHOPIFY_APP', 'reach'),

    /*
    |--------------------------------------------------------------------------
    | Branding per app (UI copy, logos, page titles)
    |--------------------------------------------------------------------------
    */
    'apps' => [

        'reach' => [
            'key'         => 'reach',
            'name'        => env('REACH_APP_NAME', 'Reach'),
            'tagline'     => env('REACH_APP_TAGLINE', 'OpenAI Ads Pixel for Shopify'),
            'full_name'   => env('REACH_APP_FULL_NAME', 'Reach — OpenAI Ads Pixel'),
            'mark'        => env('REACH_APP_MARK', 'R'),
            'handle'      => env('SHOPIFY_REACH_HANDLE', env('SHOPIFY_APP_HANDLE', 'reach-openai-ads-pixel')),
            'distribution'=> 'private', // custom / single-store installs
            // Optional host used to auto-pick this app when SHOPIFY_APP is empty.
            'host'        => env('REACH_APP_HOST', 'reach.whatify.in'),
        ],

        'pixelai' => [
            'key'         => 'pixelai',
            'name'        => env('PIXELAI_APP_NAME', 'PixelAI'),
            'tagline'     => env('PIXELAI_APP_TAGLINE', 'ChatGPT & OpenAI Ads'),
            'full_name'   => env('PIXELAI_APP_FULL_NAME', 'PixelAI: ChatGPT & OpenAI Ads'),
            'mark'        => env('PIXELAI_APP_MARK', 'P'),
            'handle'      => env('SHOPIFY_PIXELAI_HANDLE', 'pixelai-chatgpt-openai-ads'),
            'distribution'=> 'public',
            'host'        => env('PIXELAI_APP_HOST', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Server-Sent Events (realtime dashboard)
    |--------------------------------------------------------------------------
    */
    'sse' => [
        'max_seconds' => (int) env('SSE_MAX_SECONDS', 55),
        'interval'    => (int) env('SSE_INTERVAL', 2),
    ],

];
