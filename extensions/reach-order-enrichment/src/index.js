import { extension } from '@shopify/ui-extensions/checkout';

/**
 * Reach Order Enrichment — purchase.thank-you.block.render
 *
 * The Thank You page renders inside a sandbox that cannot run the storefront
 * tracker, so this extension forwards the confirmed order to the Reach app.
 * The app joins it with the visitor's click IDs (fbc/fbp) captured earlier
 * and enriches the server-side Purchase event sent to the Conversions API.
 *
 * `orderConfirmation` (OrderConfirmationApi) exposes the order id/number on
 * thank-you targets — the order row itself is created moments later, so only
 * the id is available here.
 */

// Public URL of the deployed Reach app.
const REACH_APP_URL = 'https://reach.whatify.in';

export default extension('purchase.thank-you.block.render', (root, api) => {
  const { orderConfirmation } = api;

  if (!orderConfirmation || typeof orderConfirmation.subscribe !== 'function') {
    return;
  }

  const forward = (order) => {
    if (!order || order.id == null) return;

    const orderId = String(order.id).replace(/\D+/g, '');
    if (!orderId) return;

    try {
      fetch(REACH_APP_URL + '/api/enrich', {
        method: 'POST',
        mode: 'cors',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          data: {
            order_id: orderId,
            order_name: order.number != null ? String(order.number) : null,
            source: 'checkout-extension',
          },
        }),
      }).catch(() => {
        /* enrichment is best-effort */
      });
    } catch (e) {
      /* noop */
    }
  };

  orderConfirmation.subscribe(forward);
});
