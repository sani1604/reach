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
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
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
        <script src="https://unpkg.com/@shopify/app-bridge@3.7.10/umd/index.js"></script>
        <script>
            (function () {
                'use strict';
                // Degrades gracefully when App Bridge is unavailable (e.g. preview).
                if (!window.shopify) return;

                var shop = (window.shopify.shop && window.shopify.shop.replace(/^https?:\/\//, '')) || null;
                if (shop) window.__reachShop = shop;

                function attach(token) {
                    window.__reachSessionToken = token;
                }

                try {
                    if (typeof window.shopify.idToken === 'function') {
                        window.shopify.idToken().then(attach).catch(function () {});
                    }
                } catch (e) { /* noop */ }

                // Attach the session token to any XHR/fetch from the embedded app.
                var origFetch = window.fetch;
                window.fetch = function (input, init) {
                    init = init || {};
                    if (window.__reachSessionToken) {
                        if (init.headers instanceof Headers) {
                            if (!init.headers.has('Authorization')) init.headers.set('Authorization', 'Bearer ' + window.__reachSessionToken);
                        } else {
                            init.headers = init.headers || {};
                            if (!init.headers.Authorization) init.headers.Authorization = 'Bearer ' + window.__reachSessionToken;
                        }
                    }
                    return origFetch.call(this, input, init);
                };
            })();
        </script>
    @endif
</body>
</html>
