<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reach') — OpenAI Ads Pixel for Shopify</title>
    @if (config('shopify.embedded') && config('shopify.api_key'))
        {{-- Required by App Bridge CDN so ui-nav-menu / idToken work inside admin. --}}
        <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    @endif
    {{-- Cache-bust so Shopify admin / CDN always pick up layout CSS after deploys. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: '4' }}">
    @if (config('shopify.embedded'))
        {{-- App Bridge 4 must load in <head> so <ui-nav-menu> upgrades before paint. --}}
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @endif
</head>
<body class="{{ config('shopify.embedded') ? 'is-embedded' : '' }}">
    @if (config('shopify.embedded'))
        <script>window.__reachShop = @js(($shop->shopify_domain ?? session('shop')));</script>

        {{-- Shopify admin sidebar / mobile title-bar nav (App Bridge ui-nav-menu).
             rel="home" marks the default landing page and hides that item from the list
             (the app name already links home). Paths must be relative app routes. --}}
        <ui-nav-menu>
            <a href="{{ url('/dashboard') }}" rel="home">Home</a>
            <a href="{{ url('/dashboard') }}">Dashboard</a>
            <a href="{{ url('/performance') }}">Performance</a>
            <a href="{{ url('/feed') }}">Product Feed</a>
            <a href="{{ url('/settings') }}">Settings</a>
            <a href="{{ url('/billing') }}">Billing</a>
        </ui-nav-menu>
    @endif

    <header class="app-header">
        <div class="brand">
            <span class="logo">R</span>
            Reach
        </div>
        {{-- In-app nav is a fallback when not embedded (local demo / standalone).
             Inside Shopify admin the sidebar ui-nav-menu is the primary nav, so
             this strip is hidden via .is-embedded .app-nav. --}}
        <nav class="app-nav" aria-label="App">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard*') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('performance') }}" class="{{ request()->routeIs('performance') ? 'active' : '' }}">Performance</a>
            <a href="{{ route('feed') }}" class="{{ request()->routeIs('feed*') ? 'active' : '' }}">Product Feed</a>
            <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings*') ? 'active' : '' }}">Settings</a>
            <a href="{{ route('billing') }}" class="{{ request()->routeIs('billing*') ? 'active' : '' }}">Billing</a>
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
        {{-- App Bridge is loaded in <head>. This block wires session tokens
             onto fetches / in-app links / forms so multi-page Laravel routes
             keep working when third-party cookies are blocked. --}}
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

            // Prefer App Bridge's client-side navigator when available so the
            // admin sidebar stays in sync; fall back to a full iframe load.
            function navigate(pathAndQuery) {
                return withToken(pathAndQuery).then(function (href) {
                    try {
                        var path = href.replace(window.location.origin, '');
                        if (window.shopify && typeof window.shopify.navigate === 'function') {
                            window.shopify.navigate(path);
                            return;
                        }
                    } catch (e) { /* fall through */ }
                    window.location.href = href;
                });
            }

            window.reachAuth = { token: token, withToken: withToken, navigate: navigate, shop: shop };

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
            // Skip links inside <ui-nav-menu> — App Bridge owns those clicks
            // and already keeps the admin sidebar in sync.
            document.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('a[href]') : null;
                if (!link || link.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                if (link.closest && link.closest('ui-nav-menu')) return;

                var hrefAttr = link.getAttribute('href') || '';
                if (!hrefAttr || hrefAttr.charAt(0) === '#') return;

                var url = new URL(hrefAttr, window.location.origin);
                if (url.origin !== window.location.origin || !shop) return;

                e.preventDefault();
                navigate(url.pathname + url.search + url.hash);
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
