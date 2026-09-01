@php
    $configEndpoint = url('/api/pixel-config');
    $trackEndpoint = url('/api/track');
@endphp
/* Reach storefront tracker — loaded by the web pixel extension. */
(function (window, document) {
    'use strict';

    var ENDPOINTS = {
        config: {!! json_encode($configEndpoint) !!},
        track: {!! json_encode($trackEndpoint) !!}
    };

    var shop = new URLSearchParams(window.location.search).get('shop') ||
        (window.Shopify && window.Shopify.shop) || null;

    var cfg = null;
    var queue = [];

    // Visitor identity bridge — a stable per-browser id the merchant's app
    // joins to click IDs (fbc/fbp) for server-side cross-device matching.
    var VID_KEY = '_reach_vid';
    function vid() {
        try {
            var v = localStorage.getItem(VID_KEY);
            if (!v) { v = guid(); localStorage.setItem(VID_KEY, v); }
            return v;
        } catch (e) { return null; }
    }

    function guid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function nowTs() { return Math.floor(Date.now() / 1000); }

    // Meta-style click identifiers — captured if present so OpenAI Ads can
    // match cross-device conversions.
    function readCookie(name) {
        try {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
            return m ? decodeURIComponent(m[1]) : null;
        } catch (e) { return null; }
    }

    function clickIds() {
        var out = {};
        var fbc = readCookie('_fbc');
        var fbp = readCookie('_fbp');
        if (fbc) out.fbc = fbc;
        if (fbp) out.fbp = fbp;
        return out;
    }

    function utm() {
        var out = {};
        try {
            var p = new URLSearchParams(window.location.search);
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
                var v = p.get(k);
                if (v) out[k] = v;
            });
        } catch (e) { /* noop */ }
        return out;
    }

    function flush() {
        while (queue.length) {
            var fn = queue.shift();
            try { fn(cfg); } catch (e) { /* noop */ }
        }
    }

    function whenReady(fn) {
        if (cfg) { fn(cfg); } else { queue.push(fn); }
    }

    function bootOpenAi(c) {
        if (!c || !c.enabled || !c.pixel_id) { return; }
        window.__oaq = window.__oaq || [];
        try {
            var s = document.createElement('script');
            s.async = true;
            s.src = c.browser_pixel_url + '/init.js?pixel_id=' + encodeURIComponent(c.pixel_id);
            document.head.appendChild(s);
        } catch (e) { /* noop */ }
    }

    function loadConfig() {
        if (!shop) {
            cfg = { enabled: false };
            flush();
            return;
        }
        fetch(ENDPOINTS.config + '?shop=' + encodeURIComponent(shop))
            .then(function (r) { return r.json(); })
            .then(function (c) { cfg = c; bootOpenAi(c); flush(); })
            .catch(function () { cfg = { enabled: false }; flush(); });
    }

    function post(path, payload) {
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(path, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
            } else {
                fetch(path, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                    keepalive: true
                });
            }
        } catch (e) { /* noop */ }
    }

    function fire(name, data) {
        data = data || {};
        var enriched = {};
        try { Object.assign(enriched, utm()); } catch (e) { /* noop */ }
        try { Object.assign(enriched, data); } catch (e) { /* noop */ }

        var eventId = enriched.event_id || guid();
        var userData = clickIds();

        var payload = {
            shop: shop,
            vid: vid(),
            event_name: name,
            event_time: nowTs(),
            event_id: eventId,
            action_source: 'website',
            url: window.location.href,
            data: enriched,
            user_data: userData
        };

        whenReady(function (c) {
            if (c && c.enabled && c.pixel_id && window.__oaq) {
                window.__oaq.push(['track', c.pixel_id, name, {
                    event_id: eventId,
                    event_time: payload.event_time,
                    custom_data: enriched,
                    user_data: userData
                }]);
            }
        });

        post(ENDPOINTS.track, payload);
    }

    window.reach = window.reach || {};
    window.reach.track = fire;
    window.reach.enrich = enrich;
    window.__oaq = window.__oaq || [];

    // Order-status enrichment: after checkout, Shopify's order-status page runs
    // this snippet via the Checkout Extensibility pixel so click IDs join the
    // server-side Purchase that the orders webhook recorded.
    var _enrichAttempted = false;
    function enrich(data) {
        if (_enrichAttempted) return;
        _enrichAttempted = true;
        try {
            var payload = {
                shop: shop,
                vid: vid(),
                data: Object.assign(clickIds(), data || {})
            };
            post(ENDPOINTS.track.replace(/\/track$/, '/enrich'), payload);
        } catch (e) { /* noop */ }
    }
    if (window.__reachOrderData) {
        enrich(window.__reachOrderData);
    }

    loadConfig();
})(window, document);
