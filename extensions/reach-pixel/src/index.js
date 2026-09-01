import { register } from '@shopify/web-pixels-extension';

/**
 * Reach — web pixel extension (Customer Events).
 *
 * Loads the Reach tracker and maps Shopify's standard events onto the
 * OpenAI Ads taxonomy:
 *
 *   page_viewed             -> PageView
 *   product_viewed          -> ViewContent
 *   product_added_to_cart   -> AddToCart
 *   checkout_started        -> InitiateCheckout
 *
 * Purchase is tracked server-side via the orders/* webhooks, so it is
 * deliberately not fired from here (avoids double-counting).
 */

// TODO: replace with your deployed Reach app URL.
const PIXEL_URL = 'https://YOUR-APP-DOMAIN.example.com/pixel.js';

let trackerLoaded = false;

function loadTracker() {
  if (trackerLoaded) return;
  trackerLoaded = true;
  try {
    const script = document.createElement('script');
    script.async = true;
    script.src = PIXEL_URL;
    document.head.appendChild(script);
  } catch (e) {
    /* noop */
  }
}

function track(eventName, data) {
  loadTracker();

  const fire = () => {
    if (window.reach && typeof window.reach.track === 'function') {
      window.reach.track(eventName, data || {});
      return true;
    }
    return false;
  };

  if (fire()) return;

  let tries = 0;
  const interval = setInterval(() => {
    if (fire() || ++tries > 20) {
      clearInterval(interval);
    }
  }, 100);
}

register(({ analytics }) => {
  if (!analytics || typeof analytics.subscribe !== 'function') return;

  analytics.subscribe('page_viewed', (event) => {
    track('PageView', {
      url:
        (event.context &&
          event.context.document &&
          event.context.document.location) ||
        (typeof window !== 'undefined' ? window.location.href : ''),
    });
  });

  analytics.subscribe('product_viewed', (event) => {
    const variant = event.data && event.data.productVariant;
    const price = variant && variant.price;
    track('ViewContent', {
      content_ids: [String((variant && variant.id) || '')],
      content_type: 'product',
      value: price ? Number(price.amount) : undefined,
      currency: price ? price.currencyCode : undefined,
    });
  });

  analytics.subscribe('product_added_to_cart', (event) => {
    const data = event.data || {};
    const line = data.cartLine || {};
    const merchandise = line.merchandise || {};
    const cost = line.cost && line.cost.totalAmount;
    track('AddToCart', {
      content_ids: [String(merchandise.id || '')],
      content_type: 'product',
      value: cost ? Number(cost.amount) : undefined,
      currency: cost ? cost.currencyCode : undefined,
    });
  });

  analytics.subscribe('checkout_started', (event) => {
    const checkout = event.data && event.data.checkout;
    track('InitiateCheckout', {
      value: checkout && checkout.totalPrice ? Number(checkout.totalPrice.amount) : undefined,
      currency: checkout && checkout.totalPrice ? checkout.totalPrice.currencyCode : undefined,
      num_items: checkout && checkout.lineItems ? checkout.lineItems.length : undefined,
    });
  });
});
