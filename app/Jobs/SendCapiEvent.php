<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\OpenAiCapiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCapiEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 60, 120, 300];

    public function __construct(
        public int $shopId,
        public array $event,
    ) {
    }

    public function handle(OpenAiCapiClient $capi): void
    {
        $shop = Shop::find($this->shopId);

        if (! $shop || ! $shop->isInstalled() || ! $shop->pixelConfigured()) {
            return;
        }

        $result = $capi->send($shop, $this->event);
        $status = $result['status'] ?? null;
        $missingToken = ($result['error'] ?? null) === 'missing_token';

        // Retry transient failures (HTTP 5xx and unreachable endpoints), but
        // never retry a missing token — that's a config problem, not transient.
        if (($result['ok'] ?? false) === false && ! $missingToken && ($status === null || $status >= 500)) {
            throw new \RuntimeException('OpenAI CAPI delivery failed ('.($status ?? 'unreachable').')');
        }

        if (($result['ok'] ?? false) === false) {
            logger()->warning('OpenAI CAPI rejected event', [
                'shop_id'    => $this->shopId,
                'event_name' => $this->event['event_name'] ?? null,
                'status'     => $status,
                'body'       => $result['body'] ?? null,
            ]);
        }
    }
}
