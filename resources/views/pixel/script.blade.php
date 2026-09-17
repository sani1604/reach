@php
    $configEndpoint = url('/api/pixel-config');
    $trackEndpoint = url('/api/track');
@endphp
/* Reach storefront tracker — optional companion to the Customer Events web pixel.
   Loads the official OpenAI Measurement Pixel (oaiq) and dual-fires events to
   Reach (/api/track) so the dashboard + Conversions API stay in sync. */
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
    var oaiqReady = false;

    // Visitor identity bridge — a stable per-browser id the merchant's app
    // joins to click IDs (oppref/obref) for server-side cross-device matching.
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

    function readCookie(name) {
        try {
            var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
            return m ? decodeURIComponent(m[1]) : null;
        } catch (e) { return null; }
    }

    function clickIds() {
        var out = {};
        var oppref = readCookie('__oppref') || readCookie('_oppref') || readCookie('oppref');
        var obref = readCookie('__obref') || readCookie('_obref') || readCookie('obref');
        var fbc = readCookie('_fbc');
        var fbp = readCookie('_fbp');
        if (!oppref) {
            try {
                oppref = new URLSearchParams(window.location.search).get('oppref') ||
                    new URLSearchParams(window.location.search).get('op_pref');
            } catch (e) { /* noop */ }
        }
        if (oppref) out.oppref = oppref;
        if (obref) out.obref = obref;
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

    /**
     * Boot the official OpenAI Measurement Pixel (oaiq).
     * @see https://developers.openai.com/ads/measurement-pixel
     */
    function bootOpenAi(c) {
        if (!c || !c.enabled || !c.pixel_id) { return; }
        try {
            // Install the oaiq stub exactly as OpenAI documents it.
            if (!window.oaiq) {
                var q = function () { q.q.push(arguments); };
                q.q = [];
                window.oaiq = q;
                var s = document.createElement('script');
                s.async = true;
                s.src = c.browser_pixel_url || 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
                var f = document.getElementsByTagName('script')[0];
                if (f && f.parentNode) {
                    f.parentNode.insertBefore(s, f);
                } else {
                    document.head.appendChild(s);
                }
            }
            window.oaiq('init', { pixelId: c.pixel_id });
            oaiqReady = true;
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
                    keepalive: true,
                    mode: 'cors'
                });
            }
        } catch (e) { /* noop */ }
    }

    /**
     * Map Reach internal names → OpenAI event types + data shapes.
     */
    var OPENAI_EVENTS = {
        PageView:         { type: 'page_viewed',       dataType: 'contents' },
        ViewContent:      { type: 'contents_viewed',   dataType: 'contents' },
        AddToCart:        { type: 'items_added',       dataType: 'contents' },
        InitiateCheckout: { type: 'checkout_started',  dataType: 'contents' },
        Purchase:         { type: 'order_created',     dataType: 'contents' },
        page_viewed:      { type: 'page_viewed',       dataType: 'contents' },
        contents_viewed:  { type: 'contents_viewed',   dataType: 'contents' },
        items_added:      { type: 'items_added',       dataType: 'contents' },
        checkout_started: { type: 'checkout_started',  dataType: 'contents' },
        order_created:    { type: 'order_created',     dataType: 'contents' }
    };

    function toMinor(amount, currency) {
        if (amount == null || amount === '') return undefined;
        var n = Number(amount);
        if (!isFinite(n)) return undefined;
        var zero = { BIF:1, CLP:1, DJF:1, GNF:1, JPY:1, KMF:1, KRW:1, MGA:1, PYG:1, RWF:1, UGX:1, VND:1, VUV:1, XAF:1, XOF:1, XPF:1 };
        var factor = zero[String(currency || '').toUpperCase()] ? 1 : 100;
        return Math.round(n * factor);
    }

    function buildMeasurePayload(name, data) {
        var meta = OPENAI_EVENTS[name] || { type: name, dataType: 'contents' };
        var body = { type: meta.dataType };
        if (data.value != null || data.amount != null) {
            var cur = data.currency || 'USD';
            body.amount = data.amount_minor != null
                ? Number(data.amount_minor)
                : toMinor(data.amount != null ? data.amount : data.value, cur);
            body.currency = String(cur).toUpperCase();
        }
        if (data.contents || data.products) {
            var items = data.contents || data.products;
            body.contents = (items || []).map(function (p) {
                var item = {
                    id: String(p.id || ''),
                    name: p.title || p.name || undefined,
                    content_type: p.content_type || data.content_type || 'product',
                    quantity: p.quantity != null ? Number(p.quantity) : 1
                };
                if (p.price != null || p.amount != null || p.item_price != null) {
                    var cur = p.currency || data.currency || 'USD';
                    item.amount = toMinor(p.amount != null ? p.amount : (p.item_price != null ? p.item_price : p.price), cur);
                    item.currency = String(cur).toUpperCase();
                }
                return item;
            });
        } else if (data.content_ids && data.content_ids.length) {
            body.contents = data.content_ids.map(function (id) {
                return {
                    id: String(id),
                    content_type: data.content_type || 'product',
                    quantity: data.quantity != null ? Number(data.quantity) : 1
                };
            });
        }
        return { openaiType: meta.type, body: body };
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
            event: name,
            event_time: nowTs(),
            event_id: eventId,
            action_source: 'web',
            url: window.location.href,
            data: Object.assign({ event_id: eventId, event_time: nowTs(), url: window.location.href }, enriched, userData),
            user_data: userData
        };

        // Browser-side OpenAI Measurement Pixel (oaiq).
        whenReady(function (c) {
            if (!c || !c.enabled || !c.pixel_id || !window.oaiq) return;
            // Skip Purchase here — server webhook is authoritative; if the
            // caller really wants dual-fire they pass force_browser_purchase.
            if (name === 'Purchase' && !enriched.force_browser_purchase) return;
            try {
                var built = buildMeasurePayload(name, enriched);
                window.oaiq('measure', built.openaiType, built.body, { event_id: eventId });
            } catch (e) { /* noop */ }
        });

        // Always record + server-forward via Reach.
        post(ENDPOINTS.track, payload);
    }

    window.reach = window.reach || {};
    window.reach.track = fire;
    window.reach.enrich = enrich;
    // Back-compat alias some merchants may have wired up.
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
