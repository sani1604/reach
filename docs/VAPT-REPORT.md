# Reach — VAPT findings & fixes

**Date:** 2026-09-18  
**Scope:** Shopify embedded app + public pixel API + product feed + CAPI  
**Branch:** `arena/01a0b035-reach`

## Summary

| Severity | Count | Status |
|----------|------:|--------|
| Critical | 1 | Fixed |
| High     | 3 | Fixed |
| Medium   | 5 | Fixed |
| Low / Info | 3 | Fixed / noted |

---

## Critical

### C1 — Authentication bypass via spoofable headers
**Before:** `VerifyShopifyRequest` trusted `X-Shopify-Shop-Domain` and a Shopify `Referer` alone to load any installed shop’s dashboard (settings, billing, feed token, live metrics).  
**Impact:** Unauthenticated attacker could read merchant dashboards and mutate settings if they knew the `*.myshopify.com` domain.  
**Fix:** Only App Bridge session JWT **or** a matching server session cookie authenticates. Spoofable headers are hints only and must match the authenticated shop.  
**Files:** `app/Http/Middleware/VerifyShopifyRequest.php`  
**Tests:** `ReachSecurityTest::test_spoofed_shop_header_cannot_access_dashboard`

---

## High

### H1 — Open redirect via OAuth `host` param
**Before:** `base64_decode($host)` was concatenated into a redirect URL with no host allow-list.  
**Fix:** `ShopDomain::safeAdminHostParam()` — only `admin.shopify.com` / `*.myshopify.com` / `admin.spin.dev`.  
**Files:** `ShopifyAuthController`, `ShopDomain`

### H2 — SSRF via merchant `capi_url`
**Before:** Settings accepted any URL; `OpenAiCapiClient` POSTed CAPI events (with bearer token) to it.  
**Fix:** HTTPS + OpenAI Ads host allow-list at save-time and at send-time; private IPs / localhost blocked.  
**Files:** `SettingsController`, `OpenAiCapiClient`  
**Tests:** `test_capi_client_blocks_ssrf_hosts`, `test_settings_rejects_ssrf_capi_url`

### H3 — CSRF exemptions too broad on embedded POSTs
**Before:** `settings/*` and `billing/*` were globally CSRF-excepted. Combined with C1 this enabled state-changing requests.  
**Fix:** Exceptions reduced to webhooks, token-exchange, and public `api/*`. Custom `VerifyCsrfToken` accepts a **valid session JWT** as CSRF proof when third-party cookies are blocked in the admin iframe.  
**Files:** `bootstrap/app.php`, `VerifyCsrfToken`

---

## Medium

### M1 — Public `/api/track` abuse (no rate limit / free-form events)
**Fix:** Per-IP and per-shop rate limits; allow-listed event taxonomy only; payload size bound; shop domain validated as `*.myshopify.com`.  
**Files:** `ApiController`

### M2 — Pixel config shop enumeration
**Before:** Unknown shops returned `shop` echo with `enabled:false`.  
**Fix:** Uniform `{enabled:false}` without confirming existence; light rate limit.  

### M3 — Feed token comparison / download abuse
**Fix:** `hash_equals` on token; length bounds; per-IP rate limit; domain normalize.  
**Files:** `ProductFeedController`

### M4 — Open redirect via boot `to=` path
**Fix:** `ShopDomain::safeAppPath()` — relative in-app paths only.  

### M5 — GDPR data_request logged full PII
**Fix:** Log counts only, never email/phone/click ids.  
**Files:** `WebhookController`

---

## Low / Info

### L1 — Secrets in model serialization
**Fix:** `Shop::$hidden` includes `access_token`, `refresh_token`, `capi_token`, `advertiser_api_key`, `feed_token`.

### L2 — Missing security headers
**Fix:** `SecurityHeaders` middleware — `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS.

### L3 — At-rest encryption of tokens
**Noted:** Not enabled in this pass (would break existing plaintext rows without a dual-read migration). Prefer disk/KMS encryption on the host. Follow-up ticket recommended.

---

## Residual risk / operator notes

1. **Public pixel API is intentionally unauthenticated** (storefront CORS). Rate limits reduce flood risk; spoofed events for a known shop domain remain possible (same class as any browser pixel).  
2. **Feed URL is a capability secret** — treat `feed_token` like an API key; rotate from the Feed page if leaked.  
3. **Session cookie auth** still works for local/demo; production embedded traffic should prefer App Bridge JWT.  
4. After deploy: `php artisan config:clear && php artisan view:clear` and hard-refresh the admin app.

## Verification

```bash
php artisan test --filter=ReachSecurity
php artisan test --filter=ShopDomain
php artisan test --filter=OpenAiAttribution
```
