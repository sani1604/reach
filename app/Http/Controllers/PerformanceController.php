<?php

namespace App\Http\Controllers;

use App\Services\OpenAiAttribution;
use Illuminate\Http\Request;

/**
 * Performance — ChatGPT Ads attribution view (revenue, funnel, match signals).
 *
 * Every metric on this page is OpenAI / ChatGPT–attributed only (oppref or
 * chatgpt/openai UTM). Store-wide pixel traffic lives on the Dashboard funnel.
 */
class PerformanceController extends Controller
{
    private const FUNNEL_STEPS = [
        'PageView'         => 'page_views',
        'ViewContent'      => 'view_content',
        'AddToCart'        => 'add_to_cart',
        'InitiateCheckout' => 'checkouts',
        'Purchase'         => 'purchases',
    ];

    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $days = max(1, min(90, (int) $request->query('days', 30)));
        $since = now()->subDays($days);

        $base = $shop->events()->where('occurred_at', '>=', $since);

        // Load window events once — attribution is payload-based (JSON).
        $windowEvents = (clone $base)->get([
            'id', 'event_name', 'order_id', 'order_name', 'value', 'payload',
            'occurred_at', 'source', 'dedup_key',
        ]);

        $attributed = OpenAiAttribution::filterAttributed($windowEvents);

        $purchaseRows = $windowEvents->where('event_name', 'Purchase')->values();
        $refundRows = $windowEvents->where('event_name', 'PurchaseCancelled')->values();
        $attributedPurchases = OpenAiAttribution::filterAttributed($purchaseRows);
        $attributedRefunds = OpenAiAttribution::attributedRefunds($purchaseRows, $refundRows);

        $revenue = (float) $attributedPurchases->sum(fn ($e) => (float) ($e->value ?? 0));
        $refunds = (float) $attributedRefunds->sum(fn ($e) => (float) ($e->value ?? 0));
        $netRevenue = $revenue - $refunds;
        $orderCount = $attributedPurchases->pluck('order_id')->filter()->unique()->count();
        $aov = $orderCount > 0 ? $netRevenue / $orderCount : 0;

        // Funnel — attributed events only (matches page subtitle + revenue cards).
        $funnelCounts = [];
        foreach (array_keys(self::FUNNEL_STEPS) as $name) {
            $funnelCounts[$name] = $attributed->where('event_name', $name)->count();
        }
        // Purchases: prefer distinct orders when order_id is present.
        $purchasesCount = max(
            $orderCount,
            $attributedPurchases->count()
        );
        $funnelCounts['Purchase'] = $purchasesCount;

        $pageViews = (int) ($funnelCounts['PageView'] ?? 0);
        $viewContent = (int) ($funnelCounts['ViewContent'] ?? 0);
        $addToCart = (int) ($funnelCounts['AddToCart'] ?? 0);
        $checkouts = (int) ($funnelCounts['InitiateCheckout'] ?? 0);

        $cvr = $pageViews > 0 ? round($purchasesCount / $pageViews * 100, 2) : 0.0;
        $atcRate = $viewContent > 0 ? round($addToCart / $viewContent * 100, 2) : 0.0;
        $checkoutRate = $addToCart > 0 ? round($checkouts / $addToCart * 100, 2) : 0.0;

        // EMQ — attributed purchases only.
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

        // ChatGPT sessions = attributed browser events (not unique visitors —
        // we don't have a stable session key beyond vid in payload).
        $chatgptSessions = $attributed->where('source', 'browser')->count();
        $utmCampaigns = [];
        foreach ($attributed as $ev) {
            $p = $ev->payload ?? [];
            $campaign = $p['utm_campaign']
                ?? ($p['user_data']['utm_campaign'] ?? null);
            if ($campaign) {
                $utmCampaigns[$campaign] = ($utmCampaigns[$campaign] ?? 0) + 1;
            }
        }
        arsort($utmCampaigns);
        $utmCampaigns = array_slice($utmCampaigns, 0, 8, true);

        // Delivery health — last 24h (all events; operational, not attribution).
        $lastDay = $shop->events()->where('occurred_at', '>=', now()->subDay());
        $browser24 = (clone $lastDay)->where('source', 'browser')->count();
        $server24 = (clone $lastDay)->where('source', 'server')->count();
        $total24 = $browser24 + $server24;

        // Daily attributed revenue chart — matches selected window (capped 30 bars).
        $chartDays = min($days, 30);
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
        for ($i = $chartDays - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $dailyBuckets[$date] ?? null;
            $chart[] = [
                'date'    => now()->subDays($i)->format('M j'),
                'revenue' => $row ? (float) $row['v'] : 0,
                'orders'  => $row ? (int) $row['c'] : 0,
            ];
        }

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

        // Store-wide totals (for honest empty-state copy when ads traffic is 0).
        $storePageViews = $windowEvents->where('event_name', 'PageView')->count();
        $storePurchases = $purchaseRows->pluck('order_id')->filter()->unique()->count();
        if ($storePurchases === 0) {
            $storePurchases = $purchaseRows->count();
        }

        return view('performance', [
            'shop'  => $shop,
            'days'  => $days,
            'stats' => [
                'net_revenue'       => $netRevenue,
                'revenue'           => $revenue,
                'refunds'           => $refunds,
                'orders'            => $orderCount,
                'aov'               => $aov,
                'cvr'               => $cvr,
                'atc_rate'          => $atcRate,
                'checkout_rate'     => $checkoutRate,
                'match_rate'        => $matchRate,
                'emq_score'         => $emqScore,
                'with_oppref'       => $withOppref,
                'with_phone'        => $withPhone,
                'with_email'        => $withEmail,
                'with_user'         => $withUser,
                'cod_orders'        => $codOrders,
                'prepaid_orders'    => $prepaidOrders,
                'rto_count'         => $rtoCount,
                'cancel_count'      => $cancelCount,
                'page_views'        => $pageViews,
                'view_content'      => $viewContent,
                'add_to_cart'       => $addToCart,
                'checkouts'         => $checkouts,
                'purchases'         => $purchasesCount,
                'chatgpt_sessions'  => $chatgptSessions,
                'utm_campaigns'     => $utmCampaigns,
                'browser_24h'       => $browser24,
                'server_24h'        => $server24,
                'total_24h'         => $total24,
                'chart'             => $chart,
                'chart_days'        => $chartDays,
                'top_products'      => $topProducts,
                'recent_purchases'  => $recentPurchases,
                'tracking_active'   => $shop->webPixelActive() && $shop->capiReady(),
                'store_page_views'  => $storePageViews,
                'store_purchases'   => $storePurchases,
                'has_ads_traffic'   => $attributed->isNotEmpty(),
            ],
        ]);
    }
}
