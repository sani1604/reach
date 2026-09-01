<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubscribeWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $shopId)
    {
    }

    public function handle(ShopifyClient $client): void
    {
        $shop = Shop::find($this->shopId);

        if (! $shop || ! $shop->isInstalled()) {
            return;
        }

        $client->subscribeWebhooks($shop);
    }
}
