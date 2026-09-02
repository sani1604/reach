<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loading Reach…</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    {{-- App Bridge 4 — exposes `window.shopify` (idToken, etc.).
         The legacy unpkg UMD build exposes a different global and silently
         broke session-token auth in this app. --}}
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
</head>
<body class="landing">
    <div class="wrap">
        <div class="hero" style="padding-top: 96px; text-align:center;">
            <div class="brand" style="justify-content:center; margin-bottom:16px;">
                <span class="logo">R</span> Reach
            </div>
            <p class="lede" id="boot-status">Loading your dashboard…</p>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var shop = @json($shop);
        var target = @json($target ?: '/dashboard');
        var host = @json($host);
        var status = document.getElementById('boot-status');

        function say(msg) { if (status) status.textContent = msg; }

        function goTo(path, idToken) {
            var url = new URL(path, window.location.origin);
            url.searchParams.set('shop', shop);
            if (host) url.searchParams.set('host', host);
            if (idToken) url.searchParams.set('id_token', idToken);
            window.location.href = url.toString();
        }

        function escapeIframe(url) {
            try {
                if (window.top && window.top !== window.self) {
                    window.top.location.href = url;
                } else {
                    window.location.href = url;
                }
            } catch (e) {
                // Cross-origin access to top — use the standard escape hatch.
                window.location.href = url;
            }
        }

        function freshToken() {
            if (window.shopify && typeof window.shopify.idToken === 'function') {
                return window.shopify.idToken();
            }
            return Promise.reject(new Error('App Bridge unavailable'));
        }

        function boot() {
            freshToken().then(function (idToken) {
                // Register / refresh the offline token with Shopify, then
                // enter the app. No OAuth redirect involved.
                return fetch('{{ route('auth.token-exchange') }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + idToken
                    },
                    body: JSON.stringify({ shop: shop })
                }).then(function (res) { return res.json(); });
            }).then(function (data) {
                if (data && data.ok) {
                    return freshToken().then(function (token) { goTo(target, token); });
                }

                if (data && data.reason === 'not_installed') {
                    say('Opening installation…');
                    escapeIframe(data.install || ('/auth/install?shop=' + encodeURIComponent(shop)));
                    return;
                }

                say('Could not verify your session. Retrying…');
                setTimeout(boot, 1500);
            }).catch(function () {
                say('Could not reach Shopify. Retrying…');
                setTimeout(boot, 2000);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    })();
    </script>
</body>
</html>
