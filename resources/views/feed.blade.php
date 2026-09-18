@extends('layouts.app')

@section('title', 'Product Feed')

@section('content')
    <div class="page-head">
        <div>
            <h1 class="page-title">Product Feed</h1>
            <p class="page-sub">Keep your catalog ready for OpenAI Ads — every variant mapped, issues caught early.</p>
        </div>
        <div class="page-head-actions">
            @if (($syncing ?? false) || $shop->feed_status === 'syncing')
                <span class="tag amber" id="feed-status-tag">Syncing…</span>
            @elseif ($shop->feed_status)
                <span class="tag {{ $shop->feed_status === 'ready' ? 'green' : ($shop->feed_status === 'empty' ? 'gray' : 'amber') }}" id="feed-status-tag">
                    {{ ucfirst($shop->feed_status) }}
                </span>
            @else
                <span class="tag gray" id="feed-status-tag">Not synced</span>
            @endif
        </div>
    </div>

    @if (session('feed_ok'))
        <div class="alert success">✓ {{ session('feed_ok') }}</div>
    @endif
    @if (($syncing ?? false) || $shop->feed_status === 'syncing')
        <div class="alert info" id="feed-syncing-banner">
            Building your catalog feed in the background…
            <span class="muted small" id="feed-syncing-hint">This usually takes 15–60 seconds. The page will reload when ready.</span>
        </div>
    @endif
    @if ($error)
        <div class="alert error">
            Feed sync failed: {{ $error }}
            <div class="hint" style="margin-top:6px;color:inherit;">
                Confirm the app has <span class="mono">read_products</span> scope and a queue worker is running
                (<span class="mono">php artisan queue:work</span>), then try Sync again.
            </div>
        </div>
    @endif

    {{-- Hero / install-style card matching competitor Product Feed screen --}}
    <div class="card mb-16 feed-hero">
        <div class="eyebrow">Built into {{ \App\Services\ShopifyApp::name() }}</div>
        <h2 class="feed-hero-title">Keep your catalog ready for OpenAI Ads</h2>
        <p class="feed-hero-lede">
            Build a correctly formatted feed from every Shopify variant, catch issues before delivery,
            and keep a public URL you can paste into OpenAI Ads Manager.
        </p>

        <div class="feed-hero-actions">
            <form method="POST" action="{{ route('feed.sync') }}" id="feed-sync-form">
                @csrf
                <button class="btn btn-primary" type="submit" id="feed-sync-btn"
                    {{ (($syncing ?? false) || $shop->feed_status === 'syncing') ? 'disabled' : '' }}>
                    @if (($syncing ?? false) || $shop->feed_status === 'syncing')
                        Syncing…
                    @elseif ($shop->feed_synced_at)
                        Sync catalog now
                    @else
                        Build product feed
                    @endif
                </button>
            </form>
            @if ($shop->feed_token && $shop->feed_item_count > 0)
                <a class="btn btn-ghost" href="{{ $feedUrl }}?format=tsv" target="_blank" rel="noopener">Download TSV</a>
                <a class="btn btn-ghost" href="{{ $feedUrl }}?format=csv" target="_blank" rel="noopener">Download CSV</a>
            @endif
        </div>

        <p class="hint mt-16">
            Free for your full catalog on this install · Requires OpenAI Ads feed access in Ads Manager.
            @if ($shop->feed_synced_at)
                · Last synced {{ $shop->feed_synced_at->diffForHumans() }}
            @endif
        </p>
    </div>

    <div class="card mb-16">
        <h3>Built to work with {{ \App\Services\ShopifyApp::name() }} Pixel</h3>
        <div class="pipeline">
            <span class="pipe-chip">Shopify catalog</span>
            <span class="pipe-arrow">→</span>
            <span class="pipe-chip on">Product Feed</span>
            <span class="pipe-arrow">→</span>
            <span class="pipe-chip">OpenAI Ads</span>
            <span class="pipe-arrow">→</span>
            <span class="pipe-chip">Pixel &amp; Analytics</span>
        </div>
        <p class="sub" style="margin-top:12px;">
            Product Feed gets your products into OpenAI Ads. {{ \App\Services\ShopifyApp::name() }} Pixel measures what happens after the click.
        </p>
    </div>

    <div class="grid stats mb-16">
        <div class="stat">
            <div class="label">Variants in feed</div>
            <div class="value">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
            <div class="delta">every sellable variant mapped</div>
        </div>
        <div class="stat">
            <div class="label">Ready for ads</div>
            <div class="value green">{{ number_format((int) ($stats['ready'] ?? 0)) }}</div>
            <div class="delta">pass OpenAI required fields</div>
        </div>
        <div class="stat">
            <div class="label">With issues</div>
            <div class="value">{{ number_format((int) ($stats['issues'] ?? 0)) }}</div>
            <div class="delta">missing image / price / etc.</div>
        </div>
        <div class="stat">
            <div class="label">Out of stock</div>
            <div class="value">{{ number_format((int) ($stats['out_of_stock'] ?? 0)) }}</div>
            <div class="delta">included as out_of_stock</div>
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>What Product Feed handles</h3>
            <ul class="feature-list">
                <li>
                    <span class="fi">↦</span>
                    <div><strong>Every variant mapped</strong><br><span class="muted small">Products and variants formatted for OpenAI.</span></div>
                </li>
                <li>
                    <span class="fi warn">!</span>
                    <div><strong>Feed issues caught early</strong><br><span class="muted small">Missing images, prices and fields surfaced before delivery.</span></div>
                </li>
                <li>
                    <span class="fi ok">✓</span>
                    <div><strong>OpenAI delivery URL</strong><br><span class="muted small">Stable tokenized link for Ads Manager uploads / scheduled fetch.</span></div>
                </li>
                <li>
                    <span class="fi">↻</span>
                    <div><strong>On-demand sync</strong><br><span class="muted small">Re-build after catalog changes. Auto-sync on paid plans coming next.</span></div>
                </li>
            </ul>
        </div>

        <div class="card">
            <h3>Feed URL for OpenAI Ads</h3>
            <p class="sub">Paste this into OpenAI Ads Manager → Product feeds (or download and upload manually).</p>
            @if ($shop->feed_token)
                <div class="feed-url-box">
                    <code id="feed-url">{{ $feedUrl }}?format=tsv</code>
                    <button type="button" class="btn btn-ghost btn-sm" id="copy-feed-url">Copy</button>
                </div>
                <p class="hint mt-16">
                    Format: TSV (tab-separated) · UTF-8 · OpenAI / Google Shopping-compatible columns
                    (<span class="mono">item_id, title, description, url, brand, price, availability, image_url, …</span>
                </p>
            @else
                <p class="muted small">Sync once to generate your private feed URL.</p>
            @endif
        </div>
    </div>

    <div class="grid cols-2 mb-16">
        <div class="card">
            <h3>Sample items</h3>
            <p class="sub">First rows of the current feed.</p>
            @if (empty($sample))
                <p class="muted small">No items yet — click <strong>Build product feed</strong>.</p>
            @else
                <table class="list">
                    <thead><tr><th>Item</th><th>Price</th><th>Stock</th></tr></thead>
                    <tbody>
                        @foreach ($sample as $item)
                            <tr>
                                <td>
                                    <div style="font-weight:600;">{{ \Illuminate\Support\Str::limit($item['title'] ?? '', 48) }}</div>
                                    <div class="mono muted small">{{ $item['item_id'] ?? '' }}</div>
                                </td>
                                <td class="nowrap">{{ $item['sale_price'] ?? $item['price'] ?? '—' }}</td>
                                <td>
                                    @if (($item['availability'] ?? '') === 'in_stock')
                                        <span class="tag green">in stock</span>
                                    @else
                                        <span class="tag amber">{{ $item['availability'] ?? '—' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card">
            <h3>Issues to fix</h3>
            <p class="sub">Fix these in Shopify so OpenAI accepts more of your catalog.</p>
            @if (empty($issues))
                <p class="muted small">
                    @if (($stats['total'] ?? 0) > 0)
                        No blocking issues on the last sync. Nice.
                    @else
                        Sync the catalog to see validation results.
                    @endif
                </p>
            @else
                <table class="list">
                    <thead><tr><th>Level</th><th>Item</th><th>Issue</th></tr></thead>
                    <tbody>
                        @foreach ($issues as $issue)
                            <tr>
                                <td>
                                    <span class="tag {{ ($issue['level'] ?? '') === 'error' ? 'amber' : (($issue['level'] ?? '') === 'info' ? 'gray' : 'amber') }}">
                                        {{ $issue['level'] ?? 'warn' }}
                                    </span>
                                </td>
                                <td class="mono small">{{ $issue['item_id'] ?? '—' }}</td>
                                <td>{{ $issue['message'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <script>
        (function () {
            var btn = document.getElementById('copy-feed-url');
            var el = document.getElementById('feed-url');
            if (btn && el) {
                btn.addEventListener('click', function () {
                    var text = el.textContent || '';
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function () {
                            btn.textContent = 'Copied';
                            setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
                        });
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = text; document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy'); btn.textContent = 'Copied'; } catch (e) {}
                        document.body.removeChild(ta);
                        setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
                    }
                });
            }

            // Poll feed status while a background sync is running so merchants
            // see results without a long blocking request (avoids nginx 504).
            var syncing = {{ (($syncing ?? false) || ($shop->feed_status === 'syncing')) ? 'true' : 'false' }};
            if (!syncing) return;

            var statusUrl = {!! json_encode($statusUrl ?? route('feed.status')) !!};
            var tries = 0;
            var maxTries = 90; // ~3 minutes at 2s

            function poll() {
                tries++;
                var req = (window.reachAuth && window.reachAuth.withToken)
                    ? window.reachAuth.withToken(statusUrl).then(function (u) { return fetch(u, { headers: { 'Accept': 'application/json' } }); })
                    : fetch(statusUrl, { headers: { 'Accept': 'application/json' } });

                req.then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d) {
                            if (tries < maxTries) setTimeout(poll, 2000);
                            return;
                        }
                        if (d.syncing) {
                            var hint = document.getElementById('feed-syncing-hint');
                            if (hint && d.item_count) {
                                hint.textContent = 'Still working… ' + Number(d.item_count).toLocaleString() + ' variants so far.';
                            }
                            if (tries < maxTries) setTimeout(poll, 2000);
                            return;
                        }
                        // Done (ready / empty / issues / error) — reload to show results.
                        window.location.reload();
                    })
                    .catch(function () {
                        if (tries < maxTries) setTimeout(poll, 3000);
                    });
            }

            setTimeout(poll, 1500);
        })();
    </script>
@endsection
