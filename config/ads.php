<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI Ads — Conversions API (CAPI) + browser Measurement Pixel
    |--------------------------------------------------------------------------
    | Official shape (see https://developers.openai.com/ads/conversions-api):
    |
    |   POST https://bzr.openai.com/v1/events?pid={PIXEL_ID}
    |   Authorization: Bearer {CAPI_KEY}
    |   {
    |     "validate_only": false,
    |     "integration_source": "reach_shopify",
    |     "events": [{
    |       "id": "...",
    |       "type": "order_created",
    |       "timestamp_ms": 1773892800000,
    |       "action_source": "web",
    |       "source_url": "https://…",
    |       "oppref": "…",
    |       "user": { "emails_sha256": […], … },
    |       "data": { "type": "contents", "amount": 2599, "currency": "USD" }
    |     }]
    |   }
    |
    | Browser SDK: https://bzrcdn.openai.com/sdk/oaiq.min.js  (window.oaiq)
    */
    'capi_url'            => env('OPENAI_CAPI_URL', 'https://bzr.openai.com/v1/events'),
    'capi_token'          => env('OPENAI_CAPI_TOKEN'),
    'browser_pixel_url'   => env('OPENAI_BROWSER_PIXEL_URL', 'https://bzrcdn.openai.com/sdk/oaiq.min.js'),
    'advertiser_api_url'  => env('OPENAI_ADS_API_URL', 'https://api.ads.openai.com/v1'),
    'integration_source'  => env('OPENAI_INTEGRATION_SOURCE', 'reach_shopify'),

    /*
    |--------------------------------------------------------------------------
    | Internal dashboard event name  →  OpenAI Ads event type
    |--------------------------------------------------------------------------
    */
    'event_types' => [
        'PageView'           => 'page_viewed',
        'ViewContent'        => 'contents_viewed',
        'AddToCart'          => 'items_added',
        'InitiateCheckout'   => 'checkout_started',
        'Purchase'           => 'order_created',
        'PurchaseCancelled'  => 'custom',
        'TestEvent'          => 'custom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    | Affordable India-first pricing. Amount + currency are sent to Shopify's
    | Billing API; adjust here or via env.
    */
    'plans' => [
        'free' => [
            'price'        => 0,
            'currency'     => 'INR',
            'trial_days'   => 0,
            'events_limit' => 50_000,
        ],
        'basic' => [
            'price'        => (float) env('PLAN_BASIC_PRICE', 499),
            'currency'     => env('PLAN_BASIC_CURRENCY', 'INR'),
            'trial_days'   => (int) env('PLAN_BASIC_TRIAL_DAYS', 7),
            'events_limit' => 1_000_000,
        ],
        'growth' => [
            'price'        => (float) env('PLAN_GROWTH_PRICE', 1999),
            'currency'     => env('PLAN_GROWTH_CURRENCY', 'INR'),
            'trial_days'   => (int) env('PLAN_GROWTH_TRIAL_DAYS', 7),
            'events_limit' => 5_000_000,
        ],
    ],
];
