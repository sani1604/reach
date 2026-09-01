import { extend, useExtensionData } from '@shopify/checkout-ui-extensions-react';

/**
 * Reach Order Enrichment — checkout UI extension (purchase.thank-you target).
 *
 * Reads the confirmed order from the checkout and exposes it on the page as
 * `window.__reachOrderData`, which pixel.js consumes to POST /api/enrich.
 * This joins the order to the visitor's click IDs (fbc/fbp) so the
 * server-side Purchase event gains cross-device matching signals.
 */
extend('Checkout::ThankYou', (root) => {
  const { checkout } = useExtensionData();

  const orderId = checkout && checkout.order && checkout.order.id;
  const orderName = checkout && checkout.order && checkout.order.name;

  // Surface order data for pixel.js (which is loaded by the web pixel extension).
  if (orderId) {
    window.__reachOrderData = {
      order_id: String(orderId),
      order_name: orderName ? String(orderName) : undefined,
    };

    // If the tracker hasn't loaded yet, nudge it.
    if (window.reach && typeof window.reach.enrich === 'function') {
      window.reach.enrich(window.__reachOrderData);
    }
  }

  // No UI — this extension only enriches attribution.
  root.mount(null);
});
