<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reach') — OpenAI Ads Pixel for Shopify</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="app-header">
        <div class="brand">
            <span class="logo">R</span>
            Reach
        </div>
        <nav class="app-nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Performance</a>
            <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings*') ? 'active' : '' }}">Settings</a>
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
        <script src="https://unpkg.com/@shopify/app-bridge@3.7.10/umd/index.js"></script>
        <script>
            (function () {
                'use strict';
                if (!window.shopify) return;

                var shop = (window.shopify.shop && window.shopify.shop.replace(/^https?:\/\//, '')) || null;
                if (shop) window.__reachShop = shop;

                function attach(token) {
                    window.__reachSessionToken = token;
                }

                try {
                    if (typeof window.shopify.idToken === 'function') {
                        window.shopify.idToken().then(attach).catch(function () {});
                        setInterval(function () {
                            window.shopify.idToken().then(attach).catch(function () {});
                        }, 9000);
                    }
                } catch (e) { /* noop */ }

                // Intercept fetch
                var origFetch = window.fetch;
                window.fetch = function (input, init) {
                    init = init || {};
                    var token = window.__reachSessionToken;
                    if (token) {
                        if (init.headers instanceof Headers) {
                            if (!init.headers.has('Authorization')) init.headers.set('Authorization', 'Bearer ' + token);
                        } else {
                            init.headers = init.headers || {};
                            if (!init.headers.Authorization) init.headers.Authorization = 'Bearer ' + token;
                        }
                    }
                    return origFetch.call(this, input, init).then(function (response) {
                        if (response.status === 401 && shop) {
                            // If unauthenticated, try to refresh token or reload inside app iframe
                            window.location.reload();
                        }
                        return response;
                    });
                };

                // Intercept all link clicks and form submissions to ensure session token / shop query params are passed
                document.addEventListener('click', function (e) {
                    var target = e.target.closest('a');
                    if (!target || !target.href) return;
                    var url = new URL(target.href, window.location.origin);
                    if (url.origin === window.location.origin && shop) {
                        if (!url.searchParams.has('shop')) {
                            url.searchParams.set('shop', shop);
                            target.href = url.toString();
                        }
                    }
                });
            })();
        </script>
    @endif
</body>
</html>
