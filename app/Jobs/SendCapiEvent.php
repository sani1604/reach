<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\OpenAiCapiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendCapiEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 60, 120, 300];

    /** Cap exception-driven retries so a bad payload can't thrash the worker. */
    public int $maxExceptions = 3;

    public function __construct(
        public int $shopId,
        public array $event,
    ) {
    }

    public function handle(OpenAiCapiClient $capi): void
    {
        $shop = Shop::find($this->shopId);

        if (! $shop || ! $shop->isInstalled() || ! $shop->capiReady()) {
            return;
        }

        try {
            $result = $capi->send($shop, $this->event);
        } catch (Throwable $e) {
            logger()->warning('OpenAI CAPI client threw', [
                'shop_id'    => $this->shopId,
                'event_type' => $this->event['type'] ?? null,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }

        $status = $result['status'] ?? null;
        $error = $result['error'] ?? null;
        $configError = in_array($error, ['missing_token', 'missing_pixel_id', 'empty_events'], true);

        // Retry transient failures (HTTP 5xx and unreachable endpoints), but
        // never retry a config problem — that's a settings issue, not transient.
        if (($result['ok'] ?? false) === false && ! $configError && ($status === null || $status >= 500)) {
            throw new \RuntimeException('OpenAI CAPI delivery failed ('.($status ?? 'unreachable').')');
        }

        if (($result['ok'] ?? false) === false) {
            logger()->warning('OpenAI CAPI rejected event', [
                'shop_id'    => $this->shopId,
                'event_type' => $this->event['type'] ?? ($this->event['event_name'] ?? null),
                'event_id'   => $this->event['id'] ?? null,
                'status'     => $status,
                'error'      => $error,
                'body'       => $result['body'] ?? null,
                'url'        => $result['url'] ?? null,
            ]);
        }
    }

    public function failed(?Throwable $e): void
    {
        logger()->warning('SendCapiEvent permanently failed', [
            'shop_id'    => $this->shopId,
            'event_type' => $this->event['type'] ?? null,
            'event_id'   => $this->event['id'] ?? null,
            'error'      => $e?->getMessage(),
        ]);
    }
}
