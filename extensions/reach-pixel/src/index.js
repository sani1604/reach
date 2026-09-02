import { register } from '@shopify/web-pixels-extension';

/**
 * Reach — web pixel extension (Customer Events).
 *
 * Runs inside Shopify's sandboxed pixel runtime (runtime_context = strict):
 * no DOM access, so we never inject <script> tags — events are POSTed
 * straight to the Reach app with fetch (keepalive), which is what the app's
 * /api/track and /api/enrich endpoints expect.
 *
 * Event mapping (OpenAI Ads / Meta taxonomy):
 *
 *   page_viewed             -> PageView
 *   product_viewed          -> ViewContent
 *   product_added_to_cart   -> AddToCart
 *   checkout_started        -> InitiateCheckout
 *   checkout_completed      -> (enrichment only — Purchase is fired
 *                              server-side from the orders/* webhooks so
 *                              COD + prepaid orders are never double-counted)
 */

const DEFAULT_APP_URL = 'https://reach.whatify.in';

register(({ analytics, browser, settings, context }) => {
  // settings.config is written by the app at webPixelCreate time:
  // {"app_url":"https://…","shop":"store.myshopify.com"}
  let appUrl = DEFAULT_APP_URL;
  let shop = '';
  try {
    const parsed = JSON.parse(settings && settings.config ? settings.config : '{}');
    if (parsed.app_url) appUrl = String(parsed.app_url);
    if (parsed.shop) shop = String(parsed.shop).toLowerCase();
  } catch (e) {
    // Legacy plain-URL setting or missing config — fall back to default.
    if (settings && settings.config && settings.config.indexOf('http') === 0) {
      appUrl = String(settings.config).replace(/\/+$/, '');
    }
  }

  const ctx = context || {};
  const doc = ctx.document || {};

  // Stable visitor id, stored in the pixel's partitioned storage.
  let vid = null;
  try {
    vid = browser.localStorage.getItem('_reach_vid');
    if (!vid) {
      vid = 'v' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      browser.localStorage.setItem('_reach_vid', vid);
    }
  } catch (e) {
    vid = null;
  }

  // Best-effort Meta-style click ids — the strict sandbox may not expose
  // document.cookie; the server-side visitor bridge covers those cases.
  function readCookie(name) {
    try {
      const match = String(doc.cookie || '').match(
        new RegExp('(?:^|; )' + name + '=([^;]*)')
      );
      return match ? decodeURIComponent(match[1]) : null;
    } catch (e) {
      return null;
    }
  }

  function clickIds() {
    const ids = {};
    const fbc = readCookie('_fbc');
    const fbp = readCookie('_fbp');
    if (fbc) ids.fbc = fbc;
    if (fbp) ids.fbp = fbp;
    return ids;
  }

  function send(path, payload) {
    if (!shop) return;
    try {
      payload.shop = shop;
      if (vid) payload.vid = vid;
      fetch(appUrl + path, {
        method: 'POST',
        mode: 'cors',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }).catch(function () {
        /* network errors are non-fatal in the pixel */
      });
    } catch (e) {
      /* noop */
    }
  }

  function track(eventName, data) {
    send('/api/track', {
      event: eventName,
      data: Object.assign(
        {
          event_time: Math.floor(Date.now() / 1000),
          event_id: 'px-' + Math.random().toString(36).slice(2) + '-' + Date.now().toString(36),
        },
        data || {},
        clickIds()
      ),
    });
  }

  function amount(money) {
    try {
      return money && money.amount != null ? Number(money.amount) : undefined;
    } catch (e) {
      return undefined;
    }
  }

  function currency(money) {
    return money && money.currencyCode ? money.currencyCode : undefined;
  }

  if (analytics && typeof analytics.subscribe === 'function') {
    analytics.subscribe('page_viewed', function (event) {
      track('PageView', {
        url:
          (event && event.context && event.context.document &&
            event.context.document.location &&
            event.context.document.location.href) || doc.location || undefined,
        referrer: doc.referrer || undefined,
      });
    });

    analytics.subscribe('product_viewed', function (event) {
      const variant = (event.data && event.data.productVariant) || {};
      track('ViewContent', {
        content_ids: [String(variant.id || '')],
        content_type: 'product',
        value: amount(variant.price),
        currency: currency(variant.price),
      });
    });

    analytics.subscribe('product_added_to_cart', function (event) {
      const line = (event.data && event.data.cartLine) || {};
      const merchandise = line.merchandise || {};
      track('AddToCart', {
        content_ids: [String(merchandise.id || '')],
        content_type: 'product',
        value: amount(line.cost && line.cost.totalAmount),
        currency: currency(line.cost && line.cost.totalAmount),
        quantity: line.quantity || undefined,
      });
    });

    analytics.subscribe('checkout_started', function (event) {
      const checkout = (event.data && event.data.checkout) || {};
      track('InitiateCheckout', {
        value: amount(checkout.totalPrice),
        currency: currency(checkout.totalPrice),
        num_items: checkout.lineItems ? checkout.lineItems.length : undefined,
      });
    });

    // Order confirmation: hand the order id + click ids to the app so the
    // server-side Purchase event gains cross-device matching signals.
    analytics.subscribe('checkout_completed', function (event) {
      const checkout = (event.data && event.data.checkout) || {};
      const order = checkout.order || {};
      const orderId = order.id ? String(order.id).replace(/\D+/g, '') : null;

      send('/api/enrich', {
        data: Object.assign(
          {
            order_id: orderId,
            order_name: order.name || null,
            value: amount(checkout.totalPrice),
            currency: currency(checkout.totalPrice),
          },
          clickIds()
        ),
      });
    });
  }
});
