<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reach') — OpenAI Ads Pixel for Shopify</title>
    @if (config('shopify.embedded') && config('shopify.api_key'))
        <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: '5' }}">
    @if (config('shopify.embedded'))
        {{-- App Bridge 4: ui-nav-menu, ui-title-bar, idToken, navigate --}}
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    @endif
</head>
<body class="{{ config('shopify.embedded') ? 'is-embedded' : 'is-standalone' }}">
    @php
        $pageTitle = trim($__env->yieldContent('title') ?: 'Reach');
        $planLabel = ucfirst($shop->plan ?? 'Free').' plan';
        $planTone = ($shop->plan ?? 'free') === 'free' ? 'info' : 'success';
    @endphp

    @if (config('shopify.embedded'))
        <script>window.__reachShop = @js(($shop->shopify_domain ?? session('shop')));</script>

        {{-- Admin sidebar nav (desktop) / title-bar dropdown (mobile) --}}
        <ui-nav-menu>
            <a href="{{ url('/dashboard') }}" rel="home">Home</a>
            <a href="{{ url('/dashboard') }}">Dashboard</a>
            <a href="{{ url('/performance') }}">Performance</a>
            <a href="{{ url('/feed') }}">Product Feed</a>
            <a href="{{ url('/settings') }}">Settings</a>
            <a href="{{ url('/billing') }}">Billing</a>
        </ui-nav-menu>

        {{-- Native Shopify admin title bar — replaces our custom header strip --}}
        <ui-title-bar title="{{ $pageTitle }}">
            <a href="{{ url('/billing') }}">{{ $planLabel }}</a>
            @hasSection('title-bar-actions')
                @yield('title-bar-actions')
            @endif
        </ui-title-bar>
    @else
        {{-- Standalone / local demo only — not shown inside Shopify admin --}}
        <header class="app-header">
            <div class="brand"><span class="logo">R</span> Reach</div>
            <nav class="app-nav" aria-label="App">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard*') ? 'active' : '' }}">Dashboard</a>
                <a href="{{ route('performance') }}" class="{{ request()->routeIs('performance') ? 'active' : '' }}">Performance</a>
                <a href="{{ route('feed') }}" class="{{ request()->routeIs('feed*') ? 'active' : '' }}">Product Feed</a>
                <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings*') ? 'active' : '' }}">Settings</a>
                <a href="{{ route('billing') }}" class="{{ request()->routeIs('billing*') ? 'active' : '' }}">Billing</a>
            </nav>
            <span class="plan-badge {{ ($shop->plan ?? 'free') === 'free' ? 'free' : '' }}">{{ $planLabel }}</span>
        </header>
    @endif

    <main class="app-main polaris-page">
        @if (session('saved'))
            <div class="alert success" role="status">Saved.</div>
        @endif
        @if (session('error'))
            <div class="alert error" role="alert">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>

    @if (config('shopify.embedded'))
        <script>
        (function () {
            'use strict';

            var shop = window.__reachShop || null;

            function token() {
                try {
                    if (window.shopify && typeof window.shopify.idToken === 'function') {
                        return window.shopify.idToken();
                    }
                } catch (e) { /* noop */ }
                return Promise.reject(new Error('App Bridge unavailable'));
            }

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

            document.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('a[href]') : null;
                if (!link || link.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                // App Bridge owns nav-menu + title-bar links.
                if (link.closest && (link.closest('ui-nav-menu') || link.closest('ui-title-bar'))) return;

                var hrefAttr = link.getAttribute('href') || '';
                if (!hrefAttr || hrefAttr.charAt(0) === '#') return;

                var url = new URL(hrefAttr, window.location.origin);
                if (url.origin !== window.location.origin || !shop) return;

                e.preventDefault();
                navigate(url.pathname + url.search + url.hash);
            }, true);

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
                    HTMLFormElement.prototype.submit.call(form);
                });
            }, true);
        })();
        </script>
    @endif
</body>
</html>
