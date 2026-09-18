@extends('layouts.app')

@section('title', 'Performance')

@section('content')
    @php $s = $stats; @endphp

    <div class="page-head">
        <div>
            <h1 class="page-title">Performance</h1>
            <p class="page-sub">ChatGPT Ads attribution from real Shopify orders — last {{ $days }} days.</p>
        </div>
        <div class="page-head-actions">
            <div class="seg">
                @foreach ([7, 14, 30, 90] as $d)
                    <a class="seg-item {{ $days === $d ? 'on' : '' }}" href="{{ route('performance', ['days' => $d]) }}">{{ $d }}d</a>
                @endforeach
            </div>
        </div>
    </div>


    @if (!($s['has_ads_traffic'] ?? false) && (($s['store_page_views'] ?? 0) > 0 || ($s['store_purchases'] ?? 0) > 0))
        <div class="callout callout-info mb-16" role="status">
            <div class="callout-icon" aria-hidden="true">i</div>
            <div class="callout-body">
                <div class="callout-title">No OpenAI Ads attribution in this window</div>
                <p class="callout-text">
                    Your pixel is live on the storefront, but none of these events include
                    an OpenAI click id or ChatGPT UTM — so Performance stays at zero until
                    ads traffic lands.
                </p>
                <div class="callout-meta">
                    <span class="callout-chip">{{ number_format((int) ($s['store_page_views'] ?? 0)) }} page views</span>
                    <span class="callout-chip">{{ number_format((int) ($s['store_purchases'] ?? 0)) }} store orders</span>
                    <span class="callout-chip callout-chip-muted">0 attributed</span>
                </div>
                <p class="callout-action">
                    On ChatGPT Ads landing URLs add
                    <code class="mono">utm_source=chatgpt</code>
                    (and keep <code class="mono">oppref</code> from the ad click).
                    Dashboard still shows full store funnel; this page is ads-only.
                </p>
            </div>
        </div>
    @endif

    @unless ($s['tracking_active'] ?? false)
        <div class="callout callout-info mb-16" role="status">
            <div class="callout-icon" aria-hidden="true">i</div>
            <div class="callout-body">
                <div class="callout-title">Tracking isn’t fully live yet</div>
                <p class="callout-text">
                    Connect your Pixel ID + CAPI key and reconnect the Shopify pixel in
                    <a href="{{ route('settings') }}">Settings</a>
                    so Performance can fill with real attributed data.
                </p>
            </div>
        </div>
    @endunless

    <div class="grid stats mb-16">
        <div class="stat">
            <div class="label">Net revenue from OpenAI Ads</div>
            <div class="value green">₹{{ number_format((float) $s['net_revenue'], 0) }}</div>
            <div class="delta">attributed only · gross ₹{{ number_format((float) $s['revenue'], 0) }} · refunds ₹{{ number_format((float) $s['refunds'], 0) }}</div>
        </div>
        <div class="stat">
            <div class="label">Orders attributed</div>
            <div class="value">{{ number_format($s['orders']) }}</div>
            <div class="delta">with oppref / ChatGPT UTM</div>
        </div>
        <div class="stat">
            <div class="label">AOV</div>
            <div class="value">₹{{ number_format((float) $s['aov'], 0) }}</div>
            <div class="delta">average order value</div>
        </div>
        <div class="stat">
            <div class="label">Conversion rate</div>
            <div class="value brand">{{ $s['cvr'] }}%</div>
            <div class="delta">attributed purchase / attributed page view</div>
        </div>
        <div class="stat">
            <div class="label">EMQ score</div>
            <div class="value brand">{{ $s['emq_score'] ?? $s['match_rate'] }}%</div>
            <div class="delta">phone-first · {{ $s['with_phone'] ?? 0 }} phone · {{ $s['with_oppref'] }} oppref</div>
        </div>
        <div class="stat">
            <div class="label">Attributed events</div>
            <div class="value">{{ number_format($s['chatgpt_sessions']) }}</div>
            <div class="delta">browser events with oppref / ChatGPT UTM</div>
        </div>
    </div>

    <div class="grid stats mb-16">
        <div class="stat">
            <div class="label">COD orders</div>
            <div class="value">{{ number_format($s['cod_orders'] ?? 0) }}</div>
            <div class="delta">cash on delivery (India)</div>
        </div>
        <div class="stat">
            <div class="label">Prepaid orders</div>
            <div class="value green">{{ number_format($s['prepaid_orders'] ?? 0) }}</div>
            <div class="delta">UPI · card · wallet</div>
        </div>
        <div class="stat">
            <div class="label">RTO / cancels</div>
            <div class="value">{{ number_format($s['rto_count'] ?? 0) }}</div>
            <div class="delta">{{ number_format($s['cancel_count'] ?? 0) }} total adjustments</div>
        </div>
        <div class="stat">
            <div class="label">Phone match rate</div>
            <div class="value">{{ ($s['orders'] ?? 0) > 0 ? round(($s['with_phone'] ?? 0) / max(1, $s['purchases']) * 100, 1) : 0 }}%</div>
            <div class="delta">+91 E.164 hashed to CAPI</div>
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Funnel efficiency</h3>
            <p class="sub">OpenAI Ads traffic only (oppref / ChatGPT UTM) — not whole-store pixel volume.</p>
            <div class="perf-funnel">
                @php
                    $steps = [
                        ['label' => 'Page views', 'n' => $s['page_views']],
                        ['label' => 'Product views', 'n' => $s['view_content']],
                        ['label' => 'Add to cart', 'n' => $s['add_to_cart'], 'rate' => $s['atc_rate']],
                        ['label' => 'Checkouts', 'n' => $s['checkouts'], 'rate' => $s['checkout_rate']],
                        ['label' => 'Purchases', 'n' => $s['purchases'], 'rate' => $s['cvr']],
                    ];
                    $max = max(1, max(array_column($steps, 'n')));
                @endphp
                @foreach ($steps as $step)
                    @php
                        $pct = $step['n'] > 0 ? max(6, (int) round($step['n'] / $max * 100)) : 0;
                    @endphp
                    <div class="pf-row">
                        <div class="pf-label">{{ $step['label'] }}</div>
                        <div class="pf-track">
                            <div class="pf-fill" style="width: {{ $pct }}%;{{ $pct === 0 ? ' min-width:0;opacity:.35;width:2px;' : '' }}"></div>
                        </div>
                        <div class="pf-count">{{ number_format($step['n']) }}</div>
                        <div class="pf-rate">{{ isset($step['rate']) ? $step['rate'].'%' : '—' }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <h3>Attributed revenue — last {{ $s['chart_days'] ?? $days }} days</h3>
            <p class="sub">OpenAI Ads purchases only (oppref / ChatGPT UTM).</p>
            <div class="chart rev-chart">
                @php
                    $maxRev = max(1.0, (float) max(array_column($s['chart'] ?? [], 'revenue') ?: [0]));
                @endphp
                @foreach ($s['chart'] as $day)
                    @php
                        $h = $day['revenue'] > 0 ? max(8, (int) round($day['revenue'] / $maxRev * 100)) : 0;
                    @endphp
                    <div class="bar" title="{{ $day['date'] }}: ₹{{ number_format($day['revenue'], 0) }} · {{ $day['orders'] }} orders">
                        <div class="col {{ $h === 0 ? 'is-empty' : '' }}" style="height: {{ $h === 0 ? 2 : $h }}%"></div>
                        <div class="day">{{ $day['date'] }}</div>
                    </div>
                @endforeach
            </div>
            @if ($maxRev <= 1 && collect($s['chart'] ?? [])->sum('revenue') <= 0)
                <p class="muted small mt-16" style="margin-bottom:0;">No attributed purchase revenue in this window yet — bars fill when ChatGPT Ads orders land.</p>
            @endif
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Event delivery (24h)</h3>
            <p class="sub">Browser + server dual-fire health.</p>
            <div class="delivery-bars">
                @php
                    $b = max(0, (int) $s['browser_24h']);
                    $sv = max(0, (int) $s['server_24h']);
                    $t = max(1, $b + $sv);
                @endphp
                <div class="del-row">
                    <span>Browser</span>
                    <div class="del-track"><div class="del-fill browser" style="width: {{ round($b / $t * 100) }}%"></div></div>
                    <strong>{{ number_format($b) }}</strong>
                </div>
                <div class="del-row">
                    <span>Server</span>
                    <div class="del-track"><div class="del-fill server" style="width: {{ round($sv / $t * 100) }}%"></div></div>
                    <strong>{{ number_format($sv) }}</strong>
                </div>
                <p class="hint mt-16">Total last 24h: <strong>{{ number_format($s['total_24h']) }}</strong> events. Server path survives ad-blockers & Safari ITP.</p>
            </div>
        </div>

        <div class="card">
            <h3>ChatGPT Ads campaigns</h3>
            <p class="sub">Sessions tagged with ChatGPT / OpenAI UTM or oppref.</p>
            @if (empty($s['utm_campaigns']))
                <p class="muted small">No campaign tags yet. Add <span class="mono">utm_source=chatgpt&utm_campaign=…</span> to your OpenAI Ads landing URLs.</p>
            @else
                <table class="list">
                    @foreach ($s['utm_campaigns'] as $campaign => $count)
                        <tr>
                            <td class="mono">{{ $campaign }}</td>
                            <td class="nowrap" style="text-align:right;font-weight:700;">{{ number_format($count) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Top products from OpenAI Ads</h3>
            <p class="sub">Attributed purchase revenue only.</p>
            @if (empty($s['top_products']))
                <p class="muted small">No attributed product sales yet — needs oppref or utm_source=chatgpt on the order.</p>
            @else
                <table class="list">
                    <thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead>
                    <tbody>
                        @foreach ($s['top_products'] as $title => $row)
                            <tr>
                                <td>{{ $title }}</td>
                                <td class="nowrap">{{ number_format($row['qty']) }}</td>
                                <td class="nowrap" style="font-weight:700;">₹{{ number_format($row['revenue'], 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <h3>Recent attributed purchases</h3>
            <p class="sub">Latest OpenAI Ads–attributed Shopify orders.</p>
            <table class="list">
                <thead><tr><th>Order</th><th>Value</th><th>When</th></tr></thead>
                <tbody>
                    @forelse ($s['recent_purchases'] as $p)
                        <tr>
                            <td class="mono">{{ $p->order_name ?: ('#'.$p->order_id) }}</td>
                            <td class="nowrap">{{ $p->value !== null ? '₹'.number_format((float) $p->value, 0) : '—' }}</td>
                            <td class="muted nowrap">{{ $p->occurred_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No attributed purchases yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
