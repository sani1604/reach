<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI Ads — Conversions API (CAPI) + browser pixel
    |--------------------------------------------------------------------------
    | The payload follows the Meta Conversions API shape, per product spec:
    |   { "data": [ { "event_name", "event_time", "event_id",
    |                 "action_source", "user_data", "custom_data" } ] }
    | Endpoints/tokens are configurable so real OpenAI Ads credentials can be
    | dropped in without touching code.
    */
    'capi_url'          => env('OPENAI_CAPI_URL', 'https://capi.openai.com/v1/events'),
    'capi_token'        => env('OPENAI_CAPI_TOKEN'),
    'browser_pixel_url' => env('OPENAI_BROWSER_PIXEL_URL', 'https://pixel.openai.com'),

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
