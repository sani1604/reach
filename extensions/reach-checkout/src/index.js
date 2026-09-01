import { extension } from '@shopify/ui-extensions/checkout';

/**
 * Reach Order Enrichment — checkout UI extension (purchase.thank-you target).
 *
 * Reads the confirmed order from the checkout and exposes it on the page as
 * `window.__reachOrderData`, which pixel.js consumes to POST /api/enrich.
 * This joins the order to the visitor's click IDs (fbc/fbp) so the
 * server-side Purchase event gains cross-device matching signals.
 */
export default extension('purchase.thank-you', (root, api) => {
  const checkout = api.checkout;
  const orderId = checkout && checkout.order && checkout.order.id;
  const orderName = checkout && checkout.order && checkout.order.name;

  if (orderId && typeof window !== 'undefined') {
    window.__reachOrderData = {
      order_id: String(orderId),
      order_name: orderName ? String(orderName) : undefined,
    };

    if (window.reach && typeof window.reach.enrich === 'function') {
      window.reach.enrich(window.__reachOrderData);
    }
  }

  root.mount(null);
});
