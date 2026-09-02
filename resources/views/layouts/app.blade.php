<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reach') — OpenAI Ads Pixel for Shopify</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    @if (config('shopify.embedded'))
        <script>window.__reachShop = @js(($shop->shopify_domain ?? session('shop')));</script>
    @endif
    <header class="app-header">
        <div class="brand">
            <span class="logo">R</span>
            Reach
        </div>
        <nav class="app-nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings*') ? 'active' : '' }}">Settings</a>
            <a href="{{ route('billing') }}" class="{{ request()->routeIs('billing*') ? 'active' : '' }}">Billing</a>
            <a href="{{ route('setup.guide') }}" class="{{ request()->routeIs('setup.guide') ? 'active' : '' }}">Setup guide</a>
        </nav>
        <span class="plan-badge {{ ($shop->plan ?? 'free') === 'free' ? 'free' : '' }}">
            {{ ucfirst($shop->plan ?? 'Free') }} plan
        </span>
    </header>

    <main class="app-main">
        @if (session('saved'))
            <div class="alert success">✓ Saved.</div>
        @endif
        @if (session('error'))
            <div class="alert error">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>

    @if (config('shopify.embedded'))
        {{-- App Bridge 4 CDN — exposes `window.shopify` with `idToken()`.
             (The old unpkg App Bridge 3 UMD build exposed `ShopifyApp` instead,
             so `window.shopify` was undefined, session tokens were never
             attached, and every tab navigation fell back to the install
             screen.) --}}
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
        <script>
        (function () {
            'use strict';

            // The shop domain is resolved server-side (middleware) and passed
            // to the layout; don't depend on App Bridge internals for it.
            var shop = window.__reachShop || null;

            // Fresh token per request — App Bridge caches and refreshes it.
            function token() {
                try {
                    if (window.shopify && typeof window.shopify.idToken === 'function') {
                        return window.shopify.idToken();
                    }
                } catch (e) { /* noop */ }
                return Promise.reject(new Error('App Bridge unavailable'));
            }

            // Build a same-origin URL that carries ?shop=&id_token= so the
            // server can authenticate even when iframe cookies are blocked.
            function withToken(pathAndQuery) {
                var u = new URL(pathAndQuery, window.location.origin);
                if (shop) u.searchParams.set('shop', shop);
                return token().then(function (t) {
                    u.searchParams.set('id_token', t);
                    return u.toString();
                }).catch(function () {
                    return u.toString();
                });
            }

            window.reachAuth = { token: token, withToken: withToken, shop: shop };

            // Wrap fetch: attach the session token to same-origin calls.
            var origFetch = window.fetch;
            window.fetch = function (input, init) {
                try {
                    var url = (typeof input === 'string') ? input : (input && input.url) || '';
                    var sameOrigin = url.charAt(0) === '/' || url.indexOf(window.location.origin) === 0;
                    if (!sameOrigin) return origFetch.call(this, input, init);

                    return token().then(function (t) {
                        init = init || {};
                        init.headers = new Headers((init && init.headers) || {});
                        if (!init.headers.has('Authorization')) {
                            init.headers.set('Authorization', 'Bearer ' + t);
                        }
                        return origFetch.call(this, input, init);
                    }.bind(this)).catch(function () {
                        return origFetch.call(this, input, init);
                    }.bind(this));
                } catch (e) {
                    return origFetch.call(this, input, init);
                }
            };

            // Same-origin link navigations carry ?shop=&id_token=.
            document.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('a[href]') : null;
                if (!link || link.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

                var url = new URL(link.getAttribute('href'), window.location.origin);
                if (url.origin !== window.location.origin || !shop) return;

                e.preventDefault();
                withToken(url.pathname + url.search + url.hash).then(function (href) {
                    window.location.href = href;
                });
            }, true);

            // Form posts (settings, billing) carry the token as a query param
            // on the action — including per-button formaction overrides.
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!(form instanceof HTMLFormElement)) return;

                var actionUrl = (e.submitter && e.submitter.getAttribute('formaction'))
                    || form.getAttribute('action')
                    || window.location.href;

                var action = new URL(actionUrl, window.location.origin);
                if (action.origin !== window.location.origin || !shop) return;

                e.preventDefault();
                withToken(action.pathname + action.search).then(function (href) {
                    form.setAttribute('action', href);
                }).finally(function () {
                    // Native submit — does NOT re-fire the submit event.
                    HTMLFormElement.prototype.submit.call(form);
                });
            }, true);
        })();
        </script>
    @endif
</body>
</html>
