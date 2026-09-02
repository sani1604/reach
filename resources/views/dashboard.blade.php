@extends('layouts.app')

@section('title', 'Performance')

@section('content')
    <div class="status-banner">
        <div class="left">
            <div class="icon-box">✓</div>
            <div>
                <h4>Tracking healthy</h4>
                <p>Pixel installed • Server-side events active • Event taxonomy verified</p>
            </div>
        </div>
        <div class="right">
            <span class="live-dot"></span> Live
        </div>
    </div>

    <h1 class="page-title">Performance</h1>
    <p class="page-sub">OpenAI Ads tracking and Shopify event activity.</p>

    <!-- 5 Quick Stat Cards matching screenshot image-1 -->
    <div class="grid stats mb-16">
        @php
            $counts = collect($stats['funnel'])->pluck('count', 'name')->all();
            $pageViews = $counts['PageView'] ?? 0;
            $productViews = $counts['ViewContent'] ?? 0;
            $addToCart = $counts['AddToCart'] ?? 0;
            $checkout = $counts['InitiateCheckout'] ?? 0;
            $purchases = $counts['Purchase'] ?? 0;
        @endphp
        <div class="stat">
            <div class="label">Page views</div>
            <div class="value" id="stat-pv">{{ number_format($pageViews) }}</div>
            <div class="delta">Delivered</div>
        </div>
        <div class="stat">
            <div class="label">Product views</div>
            <div class="value" id="stat-vc">{{ number_format($productViews) }}</div>
            <div class="delta">Delivered</div>
        </div>
        <div class="stat">
            <div class="label">Add to cart</div>
            <div class="value" id="stat-atc">{{ number_format($addToCart) }}</div>
            <div class="delta">Delivered</div>
        </div>
        <div class="stat">
            <div class="label">Checkout</div>
            <div class="value" id="stat-chk">{{ number_format($checkout) }}</div>
            <div class="delta">Delivered</div>
        </div>
        <div class="stat">
            <div class="label">Purchases</div>
            <div class="value green" id="stat-pur">{{ number_format($purchases) }}</div>
            <div class="delta">Delivered</div>
        </div>
    </div>

    <!-- Revenue & attribution summary -->
    <div class="grid stats mb-16">
        <div class="stat" style="grid-column: span 2;">
            <div class="label">Net revenue from OpenAI Ads</div>
            <div class="value green" id="stat-net">₹{{ number_format((float) $stats['netRevenue'], 0) }}</div>
            <div class="delta" style="color: var(--muted);">gross ₹{{ number_format((float) $stats['revenue'], 0) }} · refunds ₹{{ number_format((float) $stats['refunds'], 0) }}</div>
        </div>
        <div class="stat" style="grid-column: span 2;">
            <div class="label">Orders attributed</div>
            <div class="value">{{ number_format($stats['orders']) }}</div>
            <div class="delta" style="color: var(--muted);">unique Shopify orders</div>
        </div>
        <div class="stat" style="grid-column: span 2;">
            <div class="label">Events this month</div>
            <div class="value" id="stat-month">{{ number_format($shop->monthly_event_count) }}</div>
            <div class="delta" style="color: var(--muted);">of {{ number_format($shop->eventsLimit()) }} on your plan</div>
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Event funnel</h3>
            <p class="sub">All tracked Shopify activity in the last 30 days.</p>
            @foreach ($stats['funnel'] as $step)
                <div class="funnel-row {{ $step['name'] === 'Purchase' ? 'highlight' : '' }}">
                    <div class="fname">{{ $step['label'] }}</div>
                    <div class="funnel-track">
                        <div class="funnel-fill" style="width: {{ $step['width'] }}%"></div>
                    </div>
                    <div class="funnel-count">{{ number_format($step['count']) }}</div>
                    <div class="funnel-cv">{{ $step['conversion'] !== null ? $step['conversion'].'%' : '—' }}</div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <h3>Events — last 14 days</h3>
            <p class="sub">All tracked events by day.</p>
            <div class="chart">
                @foreach ($stats['chart'] as $day)
                    <div class="bar" title="{{ $day['date'] }}: {{ $day['count'] }}">
                        <div class="col" style="height: {{ $day['count'] > 0 ? max(3, round($day['count'] / max(1, $stats['chart'] ? max(array_column($stats['chart'], 'count')) : 1) * 100)) : 0 }}%"></div>
                        <div class="day">{{ $day['date'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Top products via OpenAI Ads</h3>
            <p class="sub">By units purchased, last 30 days.</p>
            @if (empty($stats['topProducts']))
                <p class="muted small">No purchases yet — connect your pixel and start driving ChatGPT Ads traffic.</p>
            @else
                <table class="list">
                    @foreach ($stats['topProducts'] as $title => $qty)
                        <tr>
                            <td>{{ $title }}</td>
                            <td class="nowrap" style="text-align: right; font-weight: 700;">{{ $qty }} units</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>Recent events</h3>
            <p class="sub">Latest activity hitting your store.</p>
            <table class="list">
                <thead>
                    <tr><th>Event</th><th>Source</th><th>Value</th><th>When</th></tr>
                </thead>
                <tbody>
                    @forelse ($recent as $e)
                        <tr>
                            <td>
                                <span class="tag {{ $e->event_name === 'Purchase' ? 'green' : ($e->event_name === 'PurchaseCancelled' ? 'amber' : '') }}">
                                    {{ $e->event_name }}
                                </span>
                            </td>
                            <td><span class="tag gray">{{ $e->source }}</span></td>
                            <td class="nowrap">{{ $e->value !== null ? '₹'.number_format((float) $e->value, 0) : '—' }}</td>
                            <td class="nowrap muted">{{ $e->occurred_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No events yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        (function () {
            'use strict';
            var endpoint = {!! json_encode(route('dashboard.live')) !!};

            function set(id, value) {
                var el = document.getElementById(id);
                if (el) el.textContent = value;
            }

            function fmt(n) {
                return Number(n || 0).toLocaleString('en-IN');
            }

            function poll() {
                fetch(endpoint, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { if (!r.ok) throw new Error('bad status'); return r.json(); })
                    .then(function (d) {
                        set('stat-month', fmt(d.month));
                        set('stat-net', '₹' + fmt(d.net_revenue));
                    })
                    .catch(function () {});
            }

            setInterval(poll, 15000);
        })();
    </script>
@endsection
