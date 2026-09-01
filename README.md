# Reach — OpenAI Ads Pixel for Shopify India 🇮🇳

**From ChatGPT click to Shopify purchase — track the complete journey.**

A multi-tenant **Shopify public app** built on **Laravel** that installs the OpenAI Ads
pixel in one click and forwards customer events server-side via the OpenAI Conversions
API (Meta CAPI-style). Track **PageView → Product View → Add to Cart → Checkout → Purchase**
with browser + server dual-fire and a shared `event_id` for deduplication.

---

## Features

- **One-click install** — Shopify OAuth + offline token, embedded admin (App Bridge).
- **Auto-installed web pixel** — ships a Customer Events extension; no theme-code edits.
- **5 events** mapped to the OpenAI Ads / Meta taxonomy:
  `PageView`, `ViewContent`, `AddToCart`, `InitiateCheckout`, `Purchase`.
- **Browser + server dual-fire** — storefront pixel fires client-side; order/checkout
  webhooks forward server-side via CAPI.
- **Deduplication** — shared `event_id` + a unique `dedup_key` (order-based for Purchase),
  so COD and prepaid orders in India are each attributed exactly once.
- **Survives ad-blockers & Safari ITP** — revenue-critical events come from Shopify's
  servers, not the browser.
- **Live dashboard** — funnel, net revenue (purchases − refunds), top products, 14-day
  chart, recent events, and live counters (last hour / today / month) that poll every 15s.
- **Refund handling** — `refunds/create` webhooks record `PurchaseCancelled` events so net
  revenue stays accurate.
- **Click-ID capture & joining** — the pixel reads `_fbc`/`_fbp` cookies and a
  visitor-id bridge joins them to server-side Purchase events (via the order-status
  `/api/enrich` hook and email/phone/order lookup at webhook time).
- **Realtime feed** — a Server-Sent Events stream (`/dashboard/stream`) pushes new events
  to the Growth dashboard's live feed; shared-hosting friendly (DB poll, no Redis).
- **Affordable pricing** — Free (₹0) · Basic (₹499/mo) · Growth (₹1,999/mo), each with a
  7-day trial, billed through the Shopify Billing API.
- **Campaign attribution (Growth)** — the pixel captures UTM params; the Growth plan shows
  a top-campaigns breakdown.
- **Test-connection button** — sends a `TestEvent` to the Conversions API from Settings and
  reports success/failure (with per-shop endpoint override).
- **App Bridge session-token auth** — verifies Shopify session tokens (HS256 JWT) with a
  cookie-session fallback, so embedded admin requests stay authenticated.
- **Health alerts** — `reach:health` flags stores whose pixel stopped sending events.

---

## Architecture

```
                        ┌──────────────────────────────┐
                        │   Shopify storefront         │
                        │  (Customer Events)           │
                        │                              │
                        │  web pixel extension         │
                        │   ├─ maps standard events    │
                        │   └─ loads /pixel.js         │
                        └──────────────┬───────────────┘
                                       │ fetch /api/pixel-config
                                       │ sendBeacon /api/track
                                       ▼
┌─────────────────────────────────────────────────────────────┐
│                      Laravel app (Reach)                    │
│                                                             │
│  /auth/*      OAuth install + callback (offline token)      │
│  /webhooks    HMAC-verified Shopify webhooks                │
│                ├─ orders/create, orders/paid → Purchase     │
│                ├─ checkouts/create → InitiateCheckout       │
│                ├─ app_subscriptions/update → billing        │
│                └─ app/uninstalled → cleanup                 │
│  /api/*       pixel-config + track (CORS-open)              │
│  /dashboard, /settings, /billing   Blade + Tailwind-style UI│
│                                                             │
│  EventForwarder → EventDeduper (unique dedup_key)           │
│                 → SendCapiEvent job (queue, retries)        │
│                 → OpenAiCapiClient (Meta CAPI-style POST)   │
└─────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
                        OpenAI Ads Conversions API
```

### Stack

- **PHP 8.4 + Laravel 13**, MySQL/MariaDB, Redis (optional), database queue.
- **Shared-hosting friendly**: `QUEUE_CONNECTION=database` + a cron worker — no
  long-running daemons required.
- **No frontend build step**: plain Blade + a hand-written CSS design system.

---

## Event mapping

| Shopify source | Reach event | Delivery | Dedup key |
|---|---|---|---|
| `page_viewed` (Customer Events) | `PageView` | browser | `browser:PageView:{id}` |
| `product_viewed` (Customer Events) | `ViewContent` | browser | `browser:ViewContent:{id}` |
| `product_added_to_cart` (Customer Events) | `AddToCart` | browser | `browser:AddToCart:{id}` |
| `checkout_started` + `checkouts/create` webhook | `InitiateCheckout` | both | `checkout:{token}` |
| `orders/create` + `orders/paid` webhooks | `Purchase` | server | `purchase:{order_id}` |
| `refunds/create` webhook | `PurchaseCancelled` | server | `refund:{refund_id}` |

`orders/paid` re-fires for cash-on-delivery captures — the order-based dedup key
guarantees a single Purchase per order. Refunds are tracked separately (not in the
funnel) so net revenue = Purchase − PurchaseCancelled. Every delivery is also made
idempotent via Shopify's `X-Shopify-Webhook-Id` header.

### CAPI payload (Meta CAPI-style)

```json
{
  "data": [{
    "event_name": "Purchase",
    "event_time": 1725000000,
    "event_id": "purchase-12345",
    "action_source": "website",
    "user_data": {
      "em": "sha256-hashed-email",
      "ph": "sha256-hashed-phone"
    },
    "custom_data": {
      "currency": "INR",
      "value": 1499.00,
      "order_id": "12345",
      "contents": [{ "id": "1002", "quantity": 1, "item_price": 899.00, "title": "A2 Gir Cow Ghee 1L" }]
    }
  }]
}
```

The forwarder is isolated behind `OpenAiCapiClient` + `EventMapper`, so real OpenAI Ads
endpoint/auth can be dropped in via env or per-shop credentials without touching code.

---

## Local setup

```bash
bash /home/user/setup.sh          # installs PHP 8.4, MariaDB, Redis, Composer
cd reach
cp .env.example .env              # already done in this workspace
php artisan migrate
php artisan reach:demo            # seed demo-store.myshopify.com + 14 days of events
php artisan serve                 # http://localhost:8000
```

**Demo login:** open `http://localhost:8000` → "open the demo dashboard" (or visit
`/auth/login?shop=demo-store.myshopify.com`). Local `.env` points `OPENAI_CAPI_URL` at a
built-in mock (`/api/mock-capi`) so the full server-side pipeline runs end-to-end.

```bash
php artisan queue:work --queue=capi,default   # process CAPI + webhook-subscription jobs
php artisan test                              # 9 tests, 16 assertions
php artisan reach:health                      # detect stale pixels
```

---

## Connecting a real Shopify app

1. Create a **public/custom app** in the [Shopify Partner Dashboard](https://partners.shopify.com).
2. Set **App URL** → `https://your-domain.com` and **Allowed redirection URLs** →
   `https://your-domain.com/auth/callback`, `https://your-domain.com/auth/install`.
3. Paste credentials into `.env`:

   ```dotenv
   SHOPIFY_API_KEY=your-client-id
   SHOPIFY_API_SECRET=your-client-secret
   SHOPIFY_APP_HANDLE=your-app-handle     # used for the post-install admin redirect
   ```

4. Tunnel for local dev: `ngrok http 8000`, then update `APP_URL` and the
   Partner-Dashboard URLs to the ngrok host.
5. Install from a store: `/auth/install?shop=your-store.myshopify.com`.

### Shipping the extensions

Two extensions ship with Reach:

- **`extensions/reach-pixel/`** — the web pixel (Customer Events) that loads `pixel.js`
  and maps Shopify's standard events onto the OpenAI Ads taxonomy.
- **`extensions/reach-checkout/`** — a checkout UI extension (`purchase.thank-you` target)
  that hands the confirmed order id back to the pixel for click-ID enrichment.

With the Shopify CLI:

```bash
shopify app config link           # link to your app
shopify app deploy                # uploads the extensions
```

**Important:** replace the `PIXEL_URL` placeholder in
`extensions/reach-pixel/src/index.js` with your deployed app URL (e.g.
`https://your-domain.com/pixel.js`). Adjust the `analytics.subscribe` field names to the
live Customer Events payloads after testing in a development store.

---

## Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` | App credentials | — |
| `SHOPIFY_API_VERSION` | Admin API version | `2026-01` |
| `SHOPIFY_APP_SCOPES` | OAuth scopes | `read_orders,read_products` |
| `SHOPIFY_APP_HANDLE` | App handle for admin deep links | `reach` |
| `SHOPIFY_EMBEDDED` | Load App Bridge + session-token JS in the admin | `true` |
| `OPENAI_CAPI_URL` | Conversions API endpoint (per-shop override in Settings) | `https://capi.openai.com/v1/events` |
| `OPENAI_CAPI_TOKEN` | Fallback CAPI token (per-shop token preferred) | — |
| `OPENAI_BROWSER_PIXEL_URL` | Browser pixel loader origin | `https://pixel.openai.com` |
| `PLAN_BASIC_PRICE` / `PLAN_BASIC_CURRENCY` | Basic tier price | `499` / `INR` |
| `PLAN_GROWTH_PRICE` / `PLAN_GROWTH_CURRENCY` | Growth tier price | `1999` / `INR` |
| `SESSION_SAME_SITE` / `SESSION_SECURE_COOKIE` | Embedded-app cookies | `lax` / `false` |
| `SSE_MAX_SECONDS` / `SSE_INTERVAL` | Realtime feed runtime / poll interval | `55` / `2` |

---

## Deploying to shared cPanel hosting

Reach is designed to run on cheap shared hosting:

1. Upload the app to `public_html` (point the docroot at `public/`).
2. Create a MySQL database and update `.env`.
3. `php artisan migrate --force` and `php artisan config:cache` once deployed.

> **Note on the test-connection button:** it makes an outbound HTTPS call from your
> server to the Conversions API. Shared hosts with `allow_url_fopen` disabled or no
> outbound cURL will fail the test — verify outbound cURL works on your plan.
4. Set up the queue worker via cron (database driver — no daemon needed):

   ```cron
   * * * * * cd /home/USER/public_html && php artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/worker.log 2>&1
   ```

5. Schedule the health check daily:

   ```cron
   0 9 * * * cd /home/USER/public_html && php artisan reach:health >> storage/logs/health.log 2>&1
   ```

6. **Production cookies:** set `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true`
   (requires HTTPS) so the embedded admin keeps your session.
7. Configure `MAIL_*` (SMTP) to enable the Basic-plan pixel-break email alerts.

---

## What's next (roadmap)

- [x] Per-shop CAPI URL override + "test connection" button in Settings.
- [x] App Bridge session-token auth (HS256 JWT) with session fallback.
- [x] Growth plan + UTM campaign capture.
- [x] Refund handling — `refunds/create` → `PurchaseCancelled`, net-revenue reporting.
- [x] `fbc` / `fbp` click-ID capture + joining to server-side Purchase events.
- [x] Webhook delivery idempotency via `X-Shopify-Webhook-Id`.
- [x] Live dashboard counters (last hour / today / month) with 15s polling.
- [x] Realtime event feed via Server-Sent Events (Growth plan).
- [ ] Per-event email/Slack alerting on pixel breakage (beyond the daily health check).
- [ ] Shopify CLI deploy + Partner app wiring walkthrough (needs your real credentials).
- [ ] Live "test purchase" simulation tool for merchants onboarding.
