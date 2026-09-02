import '@shopify/ui-extensions/preact';
import {render} from 'preact';

const REACH_APP_URL = 'https://reach.whatify.in';

function Extension() {
  const {orderConfirmation} = shopify;

  if (
    !orderConfirmation ||
    typeof orderConfirmation.subscribe !== 'function'
  ) {
    return null;
  }

  const forward = (confirmation) => {
    const order = confirmation?.order;

    if (!order?.id) {
      return;
    }

    const orderId = String(order.id).replace(/\D+/g, '');

    if (!orderId) {
      return;
    }

    try {
      fetch(`${REACH_APP_URL}/api/enrich`, {
        method: 'POST',
        mode: 'cors',
        keepalive: true,
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          data: {
            order_id: orderId,
            source: 'checkout-extension',
          },
        }),
      }).catch(() => {
        // Best effort only.
      });
    } catch (e) {
      // Best effort only.
    }
  };

  orderConfirmation.subscribe(forward);

  return null;
}

export default function extension() {
  render(<Extension />, document.body);
}