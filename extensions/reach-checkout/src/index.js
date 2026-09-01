export default async function render(root, api) {
  try {
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
  } catch (e) {}

  if (root && typeof root.mount === 'function') {
    root.mount(null);
  }
}
