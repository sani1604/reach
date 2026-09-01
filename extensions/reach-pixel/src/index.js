import { register } from '@shopify/web-pixels-extension';

register(({ analytics }) => {
  if (!analytics || typeof analytics.subscribe !== 'function') return;

  analytics.subscribe('page_viewed', (event) => {
    try {
      const script = document.createElement('script');
      script.async = true;
      script.src = 'https://reach.whatify.in/pixel.js';
      document.head.appendChild(script);
    } catch (e) {}
  });
});
