<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const FUNNEL = [
        'PageView'         => 'Page views',
        'ViewContent'      => 'Product views',
        'AddToCart'        => 'Add to cart',
        'InitiateCheckout' => 'Checkouts started',
        'Purchase'         => 'Purchases',
    ];

    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $since = now()->subDays(30);

        $base = $shop->events()->where('occurred_at', '>=', $since);

        $counts = (clone $base)
            ->selectRaw('event_name, COUNT(*) as c')
            ->groupBy('event_name')
            ->pluck('c', 'event_name')
            ->all();

        $funnel = [];
        $previous = 0;
        foreach (self::FUNNEL as $name => $label) {
            $count = $counts[$name] ?? 0;
            $funnel[] = [
                'name'        => $name,
                'label'       => $label,
                'count'       => $count,
                'conversion'  => $previous > 0 ? round($count / $previous * 100, 1) : null,
                'width'       => $this->barWidth($count, $counts),
            ];
            $previous = $count;
        }

        $revenue = (clone $base)->where('event_name', 'Purchase')->sum('value');
        $refunds = (clone $base)->where('event_name', 'PurchaseCancelled')->sum('value');
        $netRevenue = $revenue - $refunds;
        $refundCount = (clone $base)->where('event_name', 'PurchaseCancelled')->count();
        $orders = (clone $base)->where('event_name', 'Purchase')
            ->whereNotNull('order_id')->distinct()->count('order_id');
        $todayCount = $shop->events()->where('occurred_at', '>=', now()->startOfDay())->count();
        $lastHour = $shop->events()->where('occurred_at', '>=', now()->subHour())->count();
        $maxEventId = (int) ($shop->events()->max('id') ?? 0);

        $daily = (clone $base)
            ->selectRaw('DATE(occurred_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('c', 'd')
            ->all();

        $chart = $this->fillDailyChart($daily, 14);

        $topProducts = $this->topProducts($shop, $since, 5);

        $topCampaigns = $shop->plan === 'growth'
            ? $this->topCampaigns($shop, $since, 5)
            : [];

        $recent = $shop->events()->latest('occurred_at')->take(8)->get();

        $stats = compact(
            'funnel',
            'revenue',
            'refunds',
            'refundCount',
            'netRevenue',
            'orders',
            'todayCount',
            'lastHour',
            'chart',
            'topProducts',
            'topCampaigns'
        );

        return view('dashboard', compact('shop', 'stats', 'recent', 'maxEventId'));
    }

    public function setupGuide(Request $request)
    {
        $shop = $request->attributes->get('shop');
        return view('setup-guide', compact('shop'));
    }

    /**
     * Lightweight JSON endpoint the dashboard polls for live counters.
     */
    public function live(Request $request)
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'today'       => $shop->events()->where('occurred_at', '>=', now()->startOfDay())->count(),
            'last_hour'   => $shop->events()->where('occurred_at', '>=', now()->subHour())->count(),
            'month'       => $shop->monthly_event_count,
            'net_revenue' => round(
                (float) $shop->events()->where('event_name', 'Purchase')->sum('value')
                - (float) $shop->events()->where('event_name', 'PurchaseCancelled')->sum('value'),
                2
            ),
        ]);
    }

    /**
     * Server-Sent Events stream: pushes new events to the dashboard in near
     * realtime. Falls back to a lightweight DB poll so it works on shared
     * hosting without Redis or websockets.
     */
    public function stream(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $after = (int) $request->query('after', 0);
        $maxSeconds = (int) config('reach.sse.max_seconds', 55);
        $interval = max(0, (int) config('reach.sse.interval', 2));

        return response()->stream(function () use ($shop, $after, $maxSeconds, $interval) {
            $end = time() + $maxSeconds;
            $lastId = $after;

            while (time() < $end) {
                $events = $shop->events()
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(50)
                    ->get();

                foreach ($events as $event) {
                    $lastId = (int) $event->id;
                    echo 'id: '.$lastId."\n";
                    echo "event: event\n";
                    echo 'data: '.json_encode([
                        'id'          => (int) $event->id,
                        'event_name'  => $event->event_name,
                        'source'      => $event->source,
                        'value'       => $event->value !== null ? (float) $event->value : null,
                        'currency'    => $event->currency,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                    ])."\n\n";
                }

                echo ": ping\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted()) {
                    break;
                }

                if ($interval > 0) {
                    sleep($interval);
                }
            }

            echo "event: eof\n";
            echo "data: reconnect\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    protected function barWidth(int $count, array $counts): int
    {
        $values = array_values($counts);
        $max = $values ? max($values) : 1;
        $max = max(1, $max);

        return (int) max(4, round($count / $max * 100));
    }

    protected function fillDailyChart(array $daily, int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $out[] = [
                'date'  => now()->subDays($i)->format('M j'),
                'count' => $daily[$date] ?? 0,
            ];
        }

        return $out;
    }

    protected function topProducts($shop, $since, int $limit): array
    {
        $purchases = $shop->events()
            ->where('event_name', 'Purchase')
            ->where('occurred_at', '>=', $since)
            ->get(['payload']);

        $aggregate = [];
        foreach ($purchases as $purchase) {
            foreach ($purchase->payload['products'] ?? [] as $product) {
                $key = $product['title'] ?? ($product['id'] ?? 'Product');
                $aggregate[$key] = ($aggregate[$key] ?? 0) + (int) ($product['quantity'] ?? 1);
            }
        }

        arsort($aggregate);

        return array_slice($aggregate, 0, $limit, true);
    }

    /**
     * Campaign attribution (Growth plan) — counts events by utm_campaign.
     */
    protected function topCampaigns($shop, $since, int $limit): array
    {
        $events = $shop->events()
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_name', ['ViewContent', 'AddToCart', 'InitiateCheckout'])
            ->get(['payload']);

        $aggregate = [];
        foreach ($events as $event) {
            $campaign = $event->payload['utm_campaign'] ?? null;
            if ($campaign) {
                $aggregate[$campaign] = ($aggregate[$campaign] ?? 0) + 1;
            }
        }

        arsort($aggregate);

        return array_slice($aggregate, 0, $limit, true);
    }
}
