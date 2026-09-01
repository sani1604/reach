<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedDemoData extends Command
{
    protected $signature = 'reach:demo {--fresh : Wipe and reseed the demo shop}';

    protected $description = 'Seed a demo shop with 14 days of realistic funnel events';

    private const PRODUCTS = [
        ['id' => '1001', 'title' => 'Kesar Face Serum 30ml', 'price' => 599],
        ['id' => '1002', 'title' => 'A2 Gir Cow Ghee 1L', 'price' => 899],
        ['id' => '1003', 'title' => 'Organic Turmeric Latte Mix', 'price' => 349],
        ['id' => '1004', 'title' => 'Handloom Cotton Kurta', 'price' => 1299],
        ['id' => '1005', 'title' => 'Cold-Pressed Coconut Oil 500ml', 'price' => 449],
        ['id' => '1006', 'title' => 'Brass Incense Holder', 'price' => 699],
    ];

    public function handle(): int
    {
        $shop = Shop::firstOrNew(['shopify_domain' => 'demo-store.myshopify.com']);
        $shop->fill([
            'access_token'  => 'demo-token',
            'plan'          => 'free',
            'plan_status'   => null,
            'pixel_id'      => 'DEMO-PIXEL-123456',
            'capi_token'    => 'demo-capi-token',
            'installed_at'  => $shop->installed_at ?? now()->subDays(20),
            'uninstalled_at'=> null,
        ]);
        $shop->save();

        if ($this->option('fresh')) {
            $shop->events()->delete();
        }

        if ($shop->events()->count() > 0) {
            $this->info('Demo shop already has events. Use --fresh to reseed.');

            return 0;
        }

        $total = 0;

        for ($daysAgo = 14; $daysAgo >= 0; $daysAgo--) {
            $day = now()->subDays($daysAgo);

            $this->spawn($shop, $day, 'PageView', rand(45, 95), 'browser', null);
            $this->spawn($shop, $day, 'ViewContent', rand(20, 45), 'browser', null);
            $this->spawn($shop, $day, 'AddToCart', rand(8, 20), 'browser', null);
            $this->spawn($shop, $day, 'InitiateCheckout', rand(4, 10), 'server', null);
            $purchases = $this->spawn($shop, $day, 'Purchase', rand(1, 5), 'server', true);
            $total += $purchases;
        }

        $shop->update([
            'monthly_event_count' => $shop->events()->count(),
            'events_reset_at'     => now()->startOfMonth(),
        ]);

        $this->info('Demo seeded: '.$shop->events()->count().' events, '.$total.' purchases.');

        return 0;
    }

    private function spawn(Shop $shop, $day, string $name, int $count, string $source, ?bool $isPurchase): int
    {
        $made = 0;

        for ($i = 0; $i < $count; $i++) {
            $at = $day->copy()->setTime(rand(9, 22), rand(0, 59), rand(0, 59));

            if ($isPurchase) {
                $line = self::PRODUCTS[array_rand(self::PRODUCTS)];
                $qty = rand(1, 3);
                $products = [[
                    'id'       => $line['id'],
                    'title'    => $line['title'],
                    'price'    => $line['price'],
                    'quantity' => $qty,
                ]];
                $value = $line['price'] * $qty;
                $orderId = Str::random(10);
                $made++;

                Event::create([
                    'shop_id'     => $shop->id,
                    'event_name'  => 'Purchase',
                    'event_id'    => 'purchase-'.$orderId,
                    'dedup_key'   => 'purchase:'.$orderId,
                    'source'      => 'server',
                    'order_id'    => $orderId,
                    'order_name'  => '#'.rand(1000, 9999),
                    'currency'    => 'INR',
                    'value'       => $value,
                    'occurred_at' => $at,
                    'payload'     => ['products' => $products],
                ]);

                continue;
            }

            $value = null;
            $currency = null;
            if ($name === 'AddToCart' || $name === 'InitiateCheckout') {
                $line = self::PRODUCTS[array_rand(self::PRODUCTS)];
                $value = $line['price'] * rand(1, 2);
                $currency = 'INR';
            }

            Event::create([
                'shop_id'     => $shop->id,
                'event_name'  => $name,
                'event_id'    => (string) Str::uuid(),
                'dedup_key'   => $source.':'.$name.':'.Str::uuid(),
                'source'      => $source,
                'currency'    => $currency,
                'value'       => $value,
                'occurred_at' => $at,
                'payload'     => [],
            ]);
        }

        return $made;
    }
}
