<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Performance — ChatGPT Ads attribution view (revenue, funnel, match signals).
 * Complements the main Dashboard with ROAS-style metrics merchants expect
 * from the competitor Reach app.
 */
class PerformanceController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $days = max(1, min(90, (int) $request->query('days', 30)));
        $since = now()->subDays($days);

        $base = $shop->events()->where('occurred_at', '>=', $since);

        $purchases = (clone $base)->where('event_name', 'Purchase');
        $refundsQ = (clone $base)->where('event_name', 'PurchaseCancelled');

        $revenue = (float) $purchases->sum('value');
        $refunds = (float) $refundsQ->sum('value');
        $netRevenue = $revenue - $refunds;
        $orderCount = (clone $base)->where('event_name', 'Purchase')
            ->whereNotNull('order_id')->distinct()->count('order_id');
        $aov = $orderCount > 0 ? $netRevenue / $orderCount : 0;

        $funnelCounts = (clone $base)
            ->selectRaw('event_name, COUNT(*) as c')
            ->groupBy('event_name')
            ->pluck('c', 'event_name')
            ->all();

        $pageViews = (int) ($funnelCounts['PageView'] ?? 0);
        $viewContent = (int) ($funnelCounts['ViewContent'] ?? 0);
        $addToCart = (int) ($funnelCounts['AddToCart'] ?? 0);
        $checkouts = (int) ($funnelCounts['InitiateCheckout'] ?? 0);
        $purchasesCount = (int) ($funnelCounts['Purchase'] ?? 0);

        $cvr = $pageViews > 0 ? round($purchasesCount / $pageViews * 100, 2) : 0;
        $atcRate = $viewContent > 0 ? round($addToCart / $viewContent * 100, 2) : 0;
        $checkoutRate = $addToCart > 0 ? round($checkouts / $addToCart * 100, 2) : 0;

        // Match-quality proxy: share of purchases that carry attribution ids.
        $purchaseRows = (clone $base)->where('event_name', 'Purchase')->get(['payload']);
        $withOppref = 0;
        $withUser = 0;
        foreach ($purchaseRows as $row) {
            $ud = $row->payload['user_data'] ?? [];
            if (! empty($row->payload['oppref']) || ! empty($ud['oppref'])) {
                $withOppref++;
            }
            if (! empty($ud['email']) || ! empty($ud['phone']) || ! empty($ud['obref']) || ! empty($ud['fbc'])) {
                $withUser++;
            }
        }
        $matchRate = $purchasesCount > 0
            ? round(max($withOppref, $withUser) / $purchasesCount * 100, 1)
            : 0.0;

        // ChatGPT / OpenAI traffic proxy via UTM + oppref on browser events.
        $browserEvents = (clone $base)->where('source', 'browser')->get(['payload', 'event_name']);
        $chatgptSessions = 0;
        $utmCampaigns = [];
        foreach ($browserEvents as $ev) {
            $p = $ev->payload ?? [];
            $src = strtolower((string) ($p['utm_source'] ?? ''));
            $med = strtolower((string) ($p['utm_medium'] ?? ''));
            $hasOppref = ! empty($p['oppref']) || ! empty(($p['user_data']['oppref'] ?? null));
            $isChat = $hasOppref
                || str_contains($src, 'chatgpt')
                || str_contains($src, 'openai')
                || str_contains($med, 'chatgpt')
                || str_contains($med, 'openai');
            if ($isChat) {
                $chatgptSessions++;
                $campaign = $p['utm_campaign'] ?? 'uncategorized';
                $utmCampaigns[$campaign] = ($utmCampaigns[$campaign] ?? 0) + 1;
            }
        }
        arsort($utmCampaigns);
        $utmCampaigns = array_slice($utmCampaigns, 0, 8, true);

        // Delivery health — last 24h source mix.
        $lastDay = $shop->events()->where('occurred_at', '>=', now()->subDay());
        $browser24 = (clone $lastDay)->where('source', 'browser')->count();
        $server24 = (clone $lastDay)->where('source', 'server')->count();
        $total24 = $browser24 + $server24;

        // Daily revenue chart (14 days).
        $dailyRev = (clone $base)
            ->where('event_name', 'Purchase')
            ->selectRaw('DATE(occurred_at) as d, SUM(value) as v, COUNT(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->keyBy('d');

        $chart = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $dailyRev->get($date);
            $chart[] = [
                'date'    => now()->subDays($i)->format('M j'),
                'revenue' => $row ? (float) $row->v : 0,
                'orders'  => $row ? (int) $row->c : 0,
            ];
        }

        // Top products by revenue.
        $topProducts = [];
        foreach ($purchaseRows as $row) {
            foreach ($row->payload['products'] ?? [] as $product) {
                $key = $product['title'] ?? ($product['id'] ?? 'Product');
                $qty = (int) ($product['quantity'] ?? 1);
                $price = (float) ($product['price'] ?? 0);
                if (! isset($topProducts[$key])) {
                    $topProducts[$key] = ['qty' => 0, 'revenue' => 0.0];
                }
                $topProducts[$key]['qty'] += $qty;
                $topProducts[$key]['revenue'] += $price * $qty;
            }
        }
        uasort($topProducts, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $topProducts = array_slice($topProducts, 0, 8, true);

        $recentPurchases = $shop->events()
            ->where('event_name', 'Purchase')
            ->latest('occurred_at')
            ->take(10)
            ->get();

        return view('performance', [
            'shop'  => $shop,
            'days'  => $days,
            'stats' => [
                'net_revenue'      => $netRevenue,
                'revenue'          => $revenue,
                'refunds'          => $refunds,
                'orders'           => $orderCount,
                'aov'              => $aov,
                'cvr'              => $cvr,
                'atc_rate'         => $atcRate,
                'checkout_rate'    => $checkoutRate,
                'match_rate'       => $matchRate,
                'with_oppref'      => $withOppref,
                'with_user'        => $withUser,
                'page_views'       => $pageViews,
                'view_content'     => $viewContent,
                'add_to_cart'      => $addToCart,
                'checkouts'        => $checkouts,
                'purchases'        => $purchasesCount,
                'chatgpt_sessions' => $chatgptSessions,
                'utm_campaigns'    => $utmCampaigns,
                'browser_24h'      => $browser24,
                'server_24h'       => $server24,
                'total_24h'        => $total24,
                'chart'            => $chart,
                'top_products'     => $topProducts,
                'recent_purchases' => $recentPurchases,
                'tracking_active'  => $shop->webPixelActive() && $shop->capiReady(),
            ],
        ]);
    }
}
