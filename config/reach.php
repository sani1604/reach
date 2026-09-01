<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server-Sent Events (realtime dashboard)
    |--------------------------------------------------------------------------
    | Tune for your hosting. Shared hosts usually cap per-request runtime, so
    | keep max_seconds well below the host's PHP max_execution_time.
    */
    'sse' => [
        'max_seconds' => (int) env('SSE_MAX_SECONDS', 55),
        'interval'    => (int) env('SSE_INTERVAL', 2),
    ],
];
