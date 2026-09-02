<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shopify app credentials
    |--------------------------------------------------------------------------
    | Create the app in your Shopify Partner Dashboard and paste the
    | Client ID (API key) and Client Secret here, or set them via env.
    */
    'api_key'      => env('SHOPIFY_API_KEY', '3d494905b0473e553ab710f85dbb99a8'),
    'api_secret'   => env('SHOPIFY_API_SECRET'),

    // Latest stable Admin API version (quarterly releases, 12-month support).
    'api_version'  => env('SHOPIFY_API_VERSION', '2026-07'),

    // write_pixels lets the app activate the Customer Events web pixel.
    'scopes'       => array_filter(explode(',', env('SHOPIFY_APP_SCOPES', 'read_orders,read_products,write_pixels'))),
    'redirect_uri' => env('SHOPIFY_REDIRECT_URI', '/auth/callback'),
    // Admin deep-link handle: https://admin.shopify.com/store/{store}/apps/{handle}
    'app_handle'   => env('SHOPIFY_APP_HANDLE', 'reach-openai-ads-pixel'),
    'embedded'     => (bool) env('SHOPIFY_EMBEDDED', true),

    /*
    |--------------------------------------------------------------------------
    | Access tokens (2026 policy)
    |--------------------------------------------------------------------------
    | Public apps must use expiring offline access tokens (access token
    | ~1 hour, refresh token ~90 days and rotated on use). New public apps
    | since April 1, 2026 get expiring tokens; every public app must be
    | migrated by January 1, 2027. `expiring=1` is always requested in the
    | OAuth/token-exchange calls, and tokens are refreshed automatically.
    */
    'expiring_tokens' => (bool) env('SHOPIFY_EXPIRING_TOKENS', true),

    /*
    |--------------------------------------------------------------------------
    | Webhooks the app subscribes to on install (runtime fallback)
    |--------------------------------------------------------------------------
    | With Shopify managed app setup the authoritative subscriptions live in
    | shopify.app.toml ([[webhooks.subscriptions]]) and are registered by
    | `shopify app deploy`. This list is only used by the runtime fallback
    | for Partner-Dashboard-configured apps.
    |
    | orders/paid and orders/create both fire the Purchase conversion, with
    | per-order deduplication so COD (cash-on-delivery) and prepaid orders
    | in India are both attributed exactly once.
    |
    | The three privacy topics (customers/data_request, customers/redact,
    | shop/redact) are MANDATORY for public apps — App Store review tests
    | them. They are declared in the toml with `compliance_topics`.
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
