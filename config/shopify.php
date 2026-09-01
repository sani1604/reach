<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shopify app credentials
    |--------------------------------------------------------------------------
    | Create the app in your Shopify Partner Dashboard and paste the
    | Client ID (API key) and Client Secret here, or set them via env.
    */
    'api_key'      => env('SHOPIFY_API_KEY'),
    'api_secret'   => env('SHOPIFY_API_SECRET'),
    'api_version'  => env('SHOPIFY_API_VERSION', '2026-01'),
    'scopes'       => array_filter(explode(',', env('SHOPIFY_APP_SCOPES', 'read_orders,read_products'))),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', '/auth/callback'),
    'app_handle'   => env('SHOPIFY_APP_HANDLE', 'reach'),
    'embedded'     => (bool) env('SHOPIFY_EMBEDDED', true),

    /*
    |--------------------------------------------------------------------------
    | Webhooks the app subscribes to on install
    |--------------------------------------------------------------------------
    | orders/paid and orders/create both fire the Purchase conversion, with
    | per-order deduplication so COD (cash-on-delivery) and prepaid orders
    | in India are both attributed exactly once.
    */
    'webhooks' => [
        'app/uninstalled',
        'orders/create',
        'orders/paid',
        'checkouts/create',
        'refunds/create',
        'app_subscriptions/update',
    ],

    'plan' => [
        'free'  => ['name' => 'Free', 'events' => 50_000],
        'basic' => ['name' => 'Basic', 'events' => 1_000_000],
    ],
];
