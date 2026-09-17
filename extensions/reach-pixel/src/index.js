import { register } from '@shopify/web-pixels-extension';

/**
 * Reach — web pixel extension (Customer Events).
 *
 * Runs inside Shopify's sandboxed pixel runtime (runtime_context = strict):
 * no DOM access, so we never inject <script> tags — events are POSTed
 * straight to the Reach app with fetch (keepalive). The Laravel app then
 * (a) records them on the dashboard and (b) forwards them server-side to
 * the OpenAI Ads Conversions API. Dual-fire + shared event_id = OpenAI
 * dedup; server path survives ad-blockers and Safari ITP.
 *
 * NOTE: browser.cookie / localStorage are ASYNC in the Shopify pixel runtime
 * (they return Promises). Always await them.
 *
 * Event mapping (OpenAI Ads taxonomy via Reach internal names):
 *
 *   page_viewed             -> PageView         -> page_viewed
 *   product_viewed          -> ViewContent      -> contents_viewed
 *   product_added_to_cart   -> AddToCart        -> items_added
 *   checkout_started        -> InitiateCheckout -> checkout_started
 *   checkout_completed      -> (enrichment only — Purchase/order_created is
 *                              fired server-side from the orders/* webhooks)
 */

const DEFAULT_APP_URL = 'https://reach.whatify.in';

register(({ analytics, browser, settings, init, context }) => {
  // settings.config is written by the app at webPixelCreate time:
  // {"app_url":"https://…","shop":"store.myshopify.com","pixel_id":"…"}
  let appUrl = DEFAULT_APP_URL;
  let shop = '';
  let pixelId = null;
  try {
    const raw = settings && settings.config ? settings.config : '{}';
    const parsed = typeof raw === 'string' ? JSON.parse(raw) : (raw || {});
    if (parsed.app_url) appUrl = String(parsed.app_url).replace(/\/+$/, '');
    if (parsed.shop) shop = String(parsed.shop).toLowerCase();
    if (parsed.pixel_id) pixelId = String(parsed.pixel_id);
  } catch (e) {
    if (settings && settings.config && String(settings.config).indexOf('http') === 0) {
      appUrl = String(settings.config).replace(/\/+$/, '');
    }
  }

  const ctx = context || (init && init.context) || {};
  const doc = ctx.document || {};

  // Fall back to the document origin host if shop wasn't baked into settings.
  if (!shop) {
    try {
      const href =
        (init && init.context && init.context.document && init.context.document.location &&
          init.context.document.location.href) ||
        (doc.location && (doc.location.href || doc.location)) ||
        '';
      if (href) {
        const host = new URL(String(href)).hostname.toLowerCase();
        if (host) shop = host;
      }
    } catch (e) {
      /* noop */
    }
  }

  // Stable visitor id (async storage).
  let vidPromise = (async function resolveVid() {
    let v = null;
    try {
      v = await browser.cookie.get('_reach_vid');
    } catch (e) {
      /* noop */
    }
    if (!v) {
      try {
        v = await browser.localStorage.getItem('_reach_vid');
      } catch (e) {
        /* noop */
      }
    }
    if (!v) {
      v = 'v' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    try {
      await browser.localStorage.setItem('_reach_vid', v);
    } catch (e) {
      /* noop */
    }
    try {
      await browser.cookie.set('_reach_vid=' + v + '; Max-Age=31536000; Path=/; SameSite=Lax');
    } catch (e) {
      try {
        await browser.cookie.set('_reach_vid', v);
      } catch (e2) {
        /* noop */
      }
    }
    return v;
  })();

  async function readCookie(name) {
    try {
      if (browser && browser.cookie && typeof browser.cookie.get === 'function') {
        const v = await browser.cookie.get(name);
        if (v) return v;
      }
    } catch (e) {
      /* fall through */
    }
    try {
      const match = String(doc.cookie || '').match(
        new RegExp('(?:^|; )' + name + '=([^;]*)')
      );
      return match ? decodeURIComponent(match[1]) : null;
    } catch (e) {
      return null;
    }
  }

  /**
   * Capture OpenAI Ads attribution ids + multi-channel UTMs + legacy click ids.
   * oppref / oai_click_id / chatgpt_aid / oai_cid — ChatGPT ad click id
   * obref — opaque browser reference cookie set by the Measurement Pixel
   * utm_* — ChatGPT search, WhatsApp, and partner campaign tags
   */
  async function clickIds() {
    const ids = {};
    const oppref =
      (await readCookie('__oppref')) ||
      (await readCookie('_oppref')) ||
      (await readCookie('oppref')) ||
      (await readCookie('oai_click_id')) ||
      (await readCookie('chatgpt_aid')) ||
      (await readCookie('oai_cid'));
    const obref =
      (await readCookie('__obref')) ||
      (await readCookie('_obref')) ||
      (await readCookie('obref'));
    const fbc = await readCookie('_fbc');
    const fbp = await readCookie('_fbp');
    if (oppref) {
      ids.oppref = oppref;
      ids.oai_click_id = oppref;
    }
    if (obref) ids.obref = obref;
    if (fbc) ids.fbc = fbc;
    if (fbp) ids.fbp = fbp;

    // Landing URL: OpenAI click ids + multi-channel UTMs (ChatGPT / WhatsApp / partners).
    try {
      const href =
        (doc.location && (doc.location.href || doc.location)) ||
        '';
      if (href) {
        const u = new URL(String(href));
        const sp = u.searchParams;
        if (!ids.oppref) {
          const fromQuery =
            sp.get('oppref') ||
            sp.get('op_pref') ||
            sp.get('oai_click_id') ||
            sp.get('chatgpt_aid') ||
            sp.get('oai_cid') ||
            sp.get('oai_aid');
          if (fromQuery) {
            ids.oppref = fromQuery;
            ids.oai_click_id = fromQuery;
            // Persist so later pages / thank-you can join without the query string.
            try {
              await browser.cookie.set(
                '__oppref=' + encodeURIComponent(fromQuery) +
                  '; Max-Age=2592000; Path=/; SameSite=Lax'
              );
            } catch (e2) {
              /* noop */
            }
          }
        }
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
          const v = sp.get(k);
          if (v) ids[k] = v;
        });
        // WhatsApp / Meta click ids sometimes ride on wa_me / fbclid.
        const wa = sp.get('wa_me') || sp.get('whatsapp_id');
        if (wa) ids.whatsapp_id = wa;
        const fbclid = sp.get('fbclid');
        if (fbclid && !ids.fbc) ids.fbclid = fbclid;
      }
    } catch (e) {
      /* noop */
    }

    return ids;
  }

  async function send(path, payload) {
    if (!shop) return;
    try {
      const vid = await vidPromise;
      payload.shop = shop;
      if (vid) payload.vid = vid;
      if (pixelId) payload.pixel_id = pixelId;
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

  async function track(eventName, data) {
    const ids = await clickIds();
    const eventId =
      'px-' + Math.random().toString(36).slice(2) + '-' + Date.now().toString(36);

    const body = Object.assign(
      {
        event_time: Math.floor(Date.now() / 1000),
        event_id: eventId,
        action_source: 'web',
      },
      data || {},
      ids
    );

    // Ensure source_url is always present for OpenAI CAPI web events.
    if (!body.url && !body.source_url) {
      try {
        body.url =
          (doc.location && (doc.location.href || doc.location)) ||
          undefined;
      } catch (e) {
        /* noop */
      }
    }

    await send('/api/track', {
      event: eventName,
      event_name: eventName,
      event_id: eventId,
      event_time: body.event_time,
      data: body,
      user_data: ids,
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

  function pageUrl(event) {
    try {
      return (
        (event &&
          event.context &&
          event.context.document &&
          event.context.document.location &&
          event.context.document.location.href) ||
        (doc.location && (doc.location.href || doc.location)) ||
        undefined
      );
    } catch (e) {
      return undefined;
    }
  }

  if (analytics && typeof analytics.subscribe === 'function') {
    analytics.subscribe('page_viewed', function (event) {
      track('PageView', {
        url: pageUrl(event),
        referrer: (doc && doc.referrer) || undefined,
        content_type: 'page',
      });
    });

    analytics.subscribe('product_viewed', function (event) {
      const variant = (event.data && event.data.productVariant) || {};
      const product = variant.product || {};
      track('ViewContent', {
        url: pageUrl(event),
        content_ids: [String(variant.id || product.id || '')].filter(Boolean),
        content_type: 'product',
        value: amount(variant.price),
        currency: currency(variant.price),
        products: [
          {
            id: String(variant.id || product.id || ''),
            title: variant.title || product.title || undefined,
            price: amount(variant.price),
            quantity: 1,
          },
        ],
      });
    });

    analytics.subscribe('product_added_to_cart', function (event) {
      const line = (event.data && event.data.cartLine) || {};
      const merchandise = line.merchandise || {};
      const product = merchandise.product || {};
      track('AddToCart', {
        url: pageUrl(event),
        content_ids: [String(merchandise.id || product.id || '')].filter(Boolean),
        content_type: 'product',
        value: amount(line.cost && line.cost.totalAmount),
        currency: currency(line.cost && line.cost.totalAmount),
        quantity: line.quantity || undefined,
        products: [
          {
            id: String(merchandise.id || product.id || ''),
            title: merchandise.title || product.title || undefined,
            price: amount(
              merchandise.price ||
                (line.cost && line.cost.totalAmount)
            ),
            quantity: line.quantity || 1,
          },
        ],
      });
    });

    analytics.subscribe('checkout_started', function (event) {
      const checkout = (event.data && event.data.checkout) || {};
      const lineItems = checkout.lineItems || [];
      track('InitiateCheckout', {
        url: pageUrl(event),
        value: amount(checkout.totalPrice),
        currency: currency(checkout.totalPrice),
        num_items: lineItems.length || undefined,
        content_ids: lineItems
          .map(function (li) {
            const m = (li && li.variant) || (li && li.merchandise) || {};
            return String(m.id || (li && li.id) || '');
          })
          .filter(Boolean),
        products: lineItems.map(function (li) {
          const m = (li && li.variant) || (li && li.merchandise) || {};
          return {
            id: String(m.id || (li && li.id) || ''),
            title: (li && li.title) || m.title || undefined,
            price: amount(
              (li && li.finalLinePrice) ||
                (m && m.price) ||
                null
            ),
            quantity: (li && li.quantity) || 1,
          };
        }),
      });
    });

    // Order confirmation: hand the order id + click ids to the app so the
    // server-side Purchase event gains cross-device matching signals. We do
    // NOT fire Purchase from the browser — orders/* webhooks own that event.
    analytics.subscribe('checkout_completed', async function (event) {
      const checkout = (event.data && event.data.checkout) || {};
      const order = checkout.order || {};
      const orderId = order.id ? String(order.id).replace(/\D+/g, '') : null;
      const ids = await clickIds();

      await send('/api/enrich', {
        data: Object.assign(
          {
            order_id: orderId,
            order_name: order.name || null,
            value: amount(checkout.totalPrice),
            currency: currency(checkout.totalPrice),
            email:
              checkout.email ||
              (checkout.billingAddress && checkout.billingAddress.email) ||
              null,
            // India phone-first EMQ: prefer checkout.phone (OTP / WhatsApp).
            phone:
              checkout.phone ||
              (checkout.billingAddress && checkout.billingAddress.phone) ||
              (checkout.shippingAddress && checkout.shippingAddress.phone) ||
              null,
            city:
              (checkout.billingAddress && checkout.billingAddress.city) ||
              (checkout.shippingAddress && checkout.shippingAddress.city) ||
              null,
            region:
              (checkout.billingAddress &&
                (checkout.billingAddress.province || checkout.billingAddress.provinceCode)) ||
              null,
            country:
              (checkout.billingAddress &&
                (checkout.billingAddress.countryCodeV2 || checkout.billingAddress.countryCode)) ||
              'IN',
            postal_code:
              (checkout.billingAddress && checkout.billingAddress.zip) || null,
          },
          ids
        ),
        user_data: ids,
      });
    });

    // payment_info_submitted — often fires for Indian 1-click checkouts
    // (GoKwik / Shopflo / Fastrr / Razorpay Magic) that skip standard events.
    analytics.subscribe('payment_info_submitted', function (event) {
      const checkout = (event.data && event.data.checkout) || {};
      track('AddPaymentInfo', {
        url: pageUrl(event),
        value: amount(checkout.totalPrice),
        currency: currency(checkout.totalPrice) || 'INR',
        checkout_token: checkout.token || undefined,
      });
    });
  }

  /**
   * India quick-checkout bridges (GoKwik, Shopflo, Fastrr, Razorpay Magic).
   * These widgets often complete outside standard Shopify checkout_* events.
   * We listen for postMessage + custom DOM-less hooks they emit into the
   * pixel sandbox via analytics custom events when available.
   */
  function trackQuickCheckout(provider, detail) {
    detail = detail || {};
    const name = detail.event || detail.type || 'InitiateCheckout';
    const mapped =
      name === 'purchase' || name === 'order_created' || name === 'checkout_completed'
        ? null // Purchase stays server-authoritative
        : name === 'add_to_cart'
          ? 'AddToCart'
          : name === 'payment_info'
            ? 'AddPaymentInfo'
            : 'InitiateCheckout';

    if (!mapped) {
      // Enrich only when an order id is present.
      if (detail.order_id || detail.orderId) {
        clickIds().then(function (ids) {
          send('/api/enrich', {
            data: Object.assign(
              {
                order_id: String(detail.order_id || detail.orderId || '').replace(/\D+/g, '') || null,
                order_name: detail.order_name || detail.orderName || null,
                value: detail.value != null ? Number(detail.value) : undefined,
                currency: detail.currency || 'INR',
                phone: detail.phone || null,
                email: detail.email || null,
                checkout_provider: provider,
              },
              ids
            ),
            user_data: ids,
          });
        });
      }
      return;
    }

    track(mapped, {
      value: detail.value != null ? Number(detail.value) : undefined,
      currency: detail.currency || 'INR',
      checkout_provider: provider,
      phone: detail.phone || undefined,
      content_ids: detail.content_ids || undefined,
    });
  }

  // Custom event name used by partner widgets / theme snippets:
  //   analytics.publish('reach:quick_checkout', { provider: 'gokwik', ... })
  if (analytics && typeof analytics.subscribe === 'function') {
    ['reach:quick_checkout', 'gokwik:checkout', 'shopflo:checkout', 'fastrr:checkout', 'razorpay:magic_checkout'].forEach(
      function (evtName) {
        try {
          analytics.subscribe(evtName, function (event) {
            const detail = (event && event.data) || event || {};
            const provider =
              detail.provider ||
              (evtName.indexOf('gokwik') >= 0
                ? 'gokwik'
                : evtName.indexOf('shopflo') >= 0
                  ? 'shopflo'
                  : evtName.indexOf('fastrr') >= 0
                    ? 'fastrr'
                    : evtName.indexOf('razorpay') >= 0
                      ? 'razorpay_magic'
                      : 'quick_checkout');
            trackQuickCheckout(provider, detail);
          });
        } catch (e) {
          /* custom topics may not exist — ignore */
        }
      }
    );
  }
});
