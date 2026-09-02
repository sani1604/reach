@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="page-title">Dashboard</h1>
    <p class="page-sub">Your OpenAI Ads funnel, revenue and top products — last 30 days.</p>

    <div class="grid stats mb-16">
        <div class="stat">
            <div class="label">Net revenue from OpenAI Ads</div>
            <div class="value green" id="stat-net">₹{{ number_format((float) $stats['netRevenue'], 0) }}</div>
            <div class="delta">gross ₹{{ number_format((float) $stats['revenue'], 0) }} · refunds ₹{{ number_format((float) $stats['refunds'], 0) }}</div>
        </div>
        <div class="stat">
            <div class="label">Orders attributed</div>
            <div class="value">{{ number_format($stats['orders']) }}</div>
            <div class="delta">unique Shopify orders</div>
        </div>
        <div class="stat">
            <div class="label">Refunds</div>
            <div class="value">{{ number_format($stats['refundCount']) }}</div>
            <div class="delta">₹{{ number_format((float) $stats['refunds'], 0) }} refunded</div>
        </div>
        <div class="stat">
            <div class="label">Events — last hour</div>
            <div class="value brand" id="stat-hour">{{ number_format($stats['lastHour']) }}</div>
            <div class="delta">live</div>
        </div>
        <div class="stat">
            <div class="label">Events today</div>
            <div class="value" id="stat-today">{{ number_format($stats['todayCount']) }}</div>
            <div class="delta">all events</div>
        </div>
        <div class="stat">
            <div class="label">Events this month</div>
            <div class="value" id="stat-month">{{ number_format($shop->monthly_event_count) }}</div>
            <div class="delta">of {{ number_format($shop->eventsLimit()) }} on your plan</div>
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Conversion funnel</h3>
            <p class="sub">Browser + server-side, deduplicated with shared event IDs.</p>
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

    @if (($shop->plan ?? 'free') === 'growth')
        <div class="grid cols-2 mb-16">
            <div class="card">
                <h3>Top campaigns</h3>
                <p class="sub">Conversions by UTM campaign, last 30 days.</p>
                @if (empty($stats['topCampaigns']))
                    <p class="muted small">No campaign data yet — add UTM parameters to your ChatGPT Ads links (e.g. <span class="mono">?utm_campaign=diwali-sale</span>).</p>
                @else
                    <table class="list">
                        @foreach ($stats['topCampaigns'] as $campaign => $count)
                            <tr>
                                <td class="mono">{{ $campaign }}</td>
                                <td class="nowrap" style="text-align: right; font-weight: 700;">{{ $count }} events</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>

            <div class="card">
                <h3>Live feed</h3>
                <p class="sub"><span id="live-status" style="color: var(--green); font-weight: 700;">● live</span> — events stream in as they happen.</p>
                <div id="live-feed" class="muted small" style="max-height: 220px; overflow-y: auto;">
                    <p>Connecting…</p>
                </div>
            </div>
        </div>
    @endif

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
                        set('stat-today', fmt(d.today));
                        set('stat-hour', fmt(d.last_hour));
                        set('stat-month', fmt(d.month));
                        set('stat-net', '₹' + fmt(d.net_revenue));
                    })
                    .catch(function () { /* keep showing the last known numbers */ });
            }

            setInterval(poll, 15000);
        })();

        (function () {
            'use strict';
            var feed = document.getElementById('live-feed');
            if (!feed) return;

            var status = document.getElementById('live-status');
            var after = {{ $maxEventId }};
            var streamUrl = {!! json_encode(route('dashboard.stream')) !!} + '?after=' + after;
            var buffer = [];

            // SSE cannot send headers — authenticate with ?id_token= instead.
            function openStream(url) {
                if (window.reachAuth && window.reachAuth.shop) {
                    return window.reachAuth.withToken(url).then(function (signed) {
                        return new EventSource(signed);
                    });
                }
                return Promise.resolve(new EventSource(url));
            }

            function flush() {
                var node = feed.querySelector('p');
                if (node) node.remove();

                buffer.forEach(function (html) {
                    var div = document.createElement('div');
                    div.style.cssText = 'padding:3px 0;border-bottom:1px solid var(--line);font-size:12.5px;';
                    div.innerHTML = html;
                    feed.prepend(div);
                });
                buffer = [];
            }

            function schedule() {
                if (buffer.length) flush();
            }
            setInterval(schedule, 3000);

            var src = null;

            function connect() {
                openStream(streamUrl).then(function (es) {
                    src = es;
                    wire(src);
                });
            }

            function wire(s) {
            s.addEventListener('event', function (e) {
                if (status) status.textContent = '● live';
                try {
                    var d = JSON.parse(e.data);
                    var value = d.value != null ? ' · ₹' + Number(d.value).toLocaleString('en-IN') : '';
                    var when = d.occurred_at ? new Date(d.occurred_at).toLocaleTimeString() : '';
                    var tag = d.event_name === 'Purchase' ? 'green' : (d.event_name === 'PurchaseCancelled' ? 'amber' : '');
                    buffer.unshift(
                        '<span class="tag ' + tag + '">' + d.event_name + '</span> ' +
                        '<span class="tag gray">' + d.source + '</span>' +
                        '<span class="muted"> ' + when + '</span>' +
                        '<strong>' + value + '</strong>'
                    );
                } catch (err) { /* skip malformed */ }
            });

            s.addEventListener('eof', function () {
                if (status) status.textContent = '● reconnecting';
                s.close();
                setTimeout(connect, 1500);
            });

            s.onerror = function () {
                if (status) status.textContent = '● reconnecting';
                s.close();
                setTimeout(connect, 3000);
            };
            }

            connect();
        })();
    </script>
@endsection
