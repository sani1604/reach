# One codebase → two Shopify Partner apps

| | **Reach** (private) | **PixelAI** (public) |
|--|--|--|
| Partner name | Reach — OpenAI Ads Pixel | PixelAI: ChatGPT & OpenAI Ads |
| Distribution | Custom / single-store | App Store public |
| TOML | `shopify.app.reach-openai-ads-pixel.toml` | `shopify.app.pixelai.toml` |
| `SHOPIFY_APP` | `reach` | `pixelai` |
| DB `shops.app_key` | `reach` | `pixelai` |

Same Laravel code, extensions, webhooks, and dashboard. Different **Client ID / Secret**, **handle**, and **branding**.

---

## Recommended layouts

### A) Two hosts (simplest)

```
reach.whatify.in     →  SHOPIFY_APP=reach     + Reach credentials
pixelai.whatify.in   →  SHOPIFY_APP=pixelai   + PixelAI credentials
```

Same git deploy; different `.env` per host. No credential collision.

### B) One host, both apps

```
SHOPIFY_APP=reach                          # default for bare requests
SHOPIFY_REACH_API_KEY=…
SHOPIFY_REACH_API_SECRET=…
SHOPIFY_PIXELAI_API_KEY=…
SHOPIFY_PIXELAI_API_SECRET=…
SHOPIFY_PIXELAI_HANDLE=…
REACH_APP_HOST=reach.whatify.in
```

Session JWT `aud` and webhook HMAC pick the right app automatically. Shop rows stay isolated via `(app_key, shopify_domain)`.

---

## Setup checklist

### 1. Partner Dashboard

Create / keep both apps. For each:

- App URL → your Laravel `application_url` (root, not `/dashboard`)
- Allowed redirection URLs → `/auth/callback`, `/auth/install`
- Scopes → `read_orders,read_products,write_pixels,read_customer_events`
- Embed app in Shopify admin = ON

### 2. Link & deploy extensions (per app)

```bash
# Private Reach
shopify app config use shopify.app.reach-openai-ads-pixel.toml
shopify app config link          # once — binds client_id
shopify app deploy

# Public PixelAI
shopify app config use shopify.app.pixelai.toml
shopify app config link          # paste PixelAI client id when prompted
# Edit shopify.app.pixelai.toml: application_url + redirect_urls + client_id
shopify app deploy
```

Each deploy registers the **web pixel** + **order enrichment** extensions on that Partner app.

### 3. Server env

```bash
# Reach host example
SHOPIFY_APP=reach
APP_NAME=Reach
SHOPIFY_API_KEY=<reach client id>
SHOPIFY_API_SECRET=<reach secret>
SHOPIFY_APP_HANDLE=reach-openai-ads-pixel

# PixelAI host example
SHOPIFY_APP=pixelai
APP_NAME=PixelAI
SHOPIFY_API_KEY=<pixelai client id>
SHOPIFY_API_SECRET=<pixelai secret>
SHOPIFY_APP_HANDLE=<handle from partner dashboard>
```

### 4. Migrate

```bash
php artisan migrate
# adds shops.app_key + unique(app_key, shopify_domain)
```

Existing rows default to `app_key=reach`.

### 5. Install

- **Reach** — install link / custom distribution on the target store  
- **PixelAI** — App Store listing or install link  

A store can run **both**; each gets its own shop row, pixel, feed token, and CAPI credentials.

---

## How resolution works

1. `ResolveShopifyApp` middleware → host / `?app=` / JWT `aud` / `SHOPIFY_APP`
2. `SessionToken` verifies JWT against the matching app secret
3. `VerifyShopifyWebhook` tries every secret; binds the winner
4. `Shop::findForApp()` / `upsertForApp()` always scope by `app_key`
5. Blade branding via `ShopifyApp::name()` / `mark()` / `tagline()`

---

## Do not

- Point both Partner apps at different **code** forks — keep one repo  
- Share one `shops` row across apps (tokens / web_pixel_id would clash)  
- Deploy extensions once and expect both Partner apps to get them — run `shopify app deploy` **per TOML**
