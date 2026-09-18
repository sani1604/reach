<?php

namespace App\Http\Controllers;

use App\Services\OpenAiAttribution;
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

        // All purchase/refund rows (needed for attribution filter + EMQ).
        $purchaseRows = (clone $base)->where('event_name', 'Purchase')
            ->get(['id', 'order_id', 'order_name', 'value', 'payload', 'occurred_at', 'source', 'event_name', 'dedup_key']);
        $refundRows = (clone $base)->where('event_name', 'PurchaseCancelled')
            ->get(['id', 'order_id', 'value', 'payload', 'dedup_key', 'occurred_at']);

        // Revenue / orders: OpenAI Ads–attributed only (oppref / ChatGPT UTM).
        $attributedPurchases = OpenAiAttribution::filterAttributed($purchaseRows);
        $attributedRefunds = OpenAiAttribution::attributedRefunds($purchaseRows, $refundRows);

        $revenue = (float) $attributedPurchases->sum(fn ($e) => (float) ($e->value ?? 0));
        $refunds = (float) $attributedRefunds->sum(fn ($e) => (float) ($e->value ?? 0));
        $netRevenue = $revenue - $refunds;
        $orderCount = $attributedPurchases->pluck('order_id')->filter()->unique()->count();
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
        // Attributed purchases only — matches revenue cards.
        $purchasesCount = $attributedPurchases->count();

        $cvr = $pageViews > 0 ? round($purchasesCount / $pageViews * 100, 2) : 0;
        $atcRate = $viewContent > 0 ? round($addToCart / $viewContent * 100, 2) : 0;
        $checkoutRate = $addToCart > 0 ? round($checkouts / $addToCart * 100, 2) : 0;

        // Event Match Quality (EMQ) proxy — phone-first for India.
        // Score attributed purchases (what OpenAI Ads can match).
        $withOppref = 0;
        $withUser = 0;
        $withPhone = 0;
        $withEmail = 0;
        $codOrders = 0;
        $prepaidOrders = 0;
        foreach ($attributedPurchases as $row) {
            $p = $row->payload ?? [];
            $ud = is_array($p['user_data'] ?? null) ? $p['user_data'] : [];
            if (! empty($p['oppref']) || ! empty($ud['oppref']) || ! empty($ud['oai_click_id'])
                || ! empty($p['oai_click_id']) || ! empty($p['chatgpt_aid'])) {
                $withOppref++;
            }
            $hasPhone = ! empty($ud['phone']) || ! empty($ud['phone_numbers_sha256']);
            $hasEmail = ! empty($ud['email']) || ! empty($ud['emails_sha256']);
            if ($hasPhone) {
                $withPhone++;
            }
            if ($hasEmail) {
                $withEmail++;
            }
            if ($hasPhone || $hasEmail || ! empty($ud['obref']) || ! empty($ud['fbc'])
                || ! empty($ud['city']) || ! empty($ud['ip_address'])) {
                $withUser++;
            }
            if (! empty($p['is_cod'])) {
                $codOrders++;
            } else {
                $prepaidOrders++;
            }
        }
        // EMQ score weights phone higher (India OTP/WhatsApp checkouts).
        $emqScore = 0.0;
        if ($purchasesCount > 0) {
            $emqScore = round((
                ($withOppref * 40) +
                ($withPhone * 35) +
                ($withEmail * 15) +
                ($withUser * 10)
            ) / ($purchasesCount * 100) * 100, 1);
            $emqScore = min(100.0, $emqScore);
        }
        $matchRate = $purchasesCount > 0
            ? round(max($withOppref, $withPhone, $withUser) / $purchasesCount * 100, 1)
            : 0.0;

        $rtoCount = $attributedRefunds
            ->filter(fn ($e) => str_starts_with((string) ($e->dedup_key ?? ''), 'rto:'))
            ->count();
        $cancelCount = $attributedRefunds->count();

        // ChatGPT / OpenAI traffic proxy via UTM + oppref on browser events.
        $browserEvents = (clone $base)->where('source', 'browser')->get(['payload', 'event_name']);
        $chatgptSessions = 0;
        $utmCampaigns = [];
        foreach ($browserEvents as $ev) {
            if (! OpenAiAttribution::isAttributed($ev->payload ?? [])) {
                continue;
            }
            $p = $ev->payload ?? [];
            $chatgptSessions++;
            $campaign = $p['utm_campaign']
                ?? ($p['user_data']['utm_campaign'] ?? null)
                ?? 'uncategorized';
            $utmCampaigns[$campaign] = ($utmCampaigns[$campaign] ?? 0) + 1;
        }
        arsort($utmCampaigns);
        $utmCampaigns = array_slice($utmCampaigns, 0, 8, true);

        // Delivery health — last 24h source mix.
        $lastDay = $shop->events()->where('occurred_at', '>=', now()->subDay());
        $browser24 = (clone $lastDay)->where('source', 'browser')->count();
        $server24 = (clone $lastDay)->where('source', 'server')->count();
        $total24 = $browser24 + $server24;

        // Daily attributed revenue chart (14 days).
        $dailyBuckets = [];
        foreach ($attributedPurchases as $row) {
            $date = optional($row->occurred_at)->toDateString();
            if (! $date) {
                continue;
            }
            if (! isset($dailyBuckets[$date])) {
                $dailyBuckets[$date] = ['v' => 0.0, 'c' => 0];
            }
            $dailyBuckets[$date]['v'] += (float) ($row->value ?? 0);
            $dailyBuckets[$date]['c']++;
        }

        $chart = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $dailyBuckets[$date] ?? null;
            $chart[] = [
                'date'    => now()->subDays($i)->format('M j'),
                'revenue' => $row ? (float) $row['v'] : 0,
                'orders'  => $row ? (int) $row['c'] : 0,
            ];
        }

        // Top products by revenue (attributed purchases only).
        $topProducts = [];
        foreach ($attributedPurchases as $row) {
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

        $recentPurchases = $attributedPurchases
            ->sortByDesc(fn ($e) => $e->occurred_at?->timestamp ?? 0)
            ->take(10)
            ->values();

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
                'emq_score'        => $emqScore,
                'with_oppref'      => $withOppref,
                'with_phone'       => $withPhone,
                'with_email'       => $withEmail,
                'with_user'        => $withUser,
                'cod_orders'       => $codOrders,
                'prepaid_orders'   => $prepaidOrders,
                'rto_count'        => $rtoCount,
                'cancel_count'     => $cancelCount,
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
