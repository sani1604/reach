# Protected Customer Data — approval for the commerce webhooks

`shopify app deploy` fails with:

> This app is not approved to subscribe to webhook topics containing protected
> customer data. See https://shopify.dev/docs/apps/launch/protected-customer-data

**Why:** these topics from `shopify.app.reach-openai-ads-pixel.toml` carry
customer PII (email, phone, name, address) inside the payload:

- `orders/create`
- `orders/paid`
- `checkouts/create`
- `refunds/create`

(That's why the error appears exactly **4 times** — one per topic.) Shopify
blocks the version until the app declares *why* it needs this data. The
compliance topics (`customers/data_request`, `customers/redact`, `shop/redact`)
are exempt — they're mandatory for every app.

> **Note:** there is no CLI flag to bypass this. The declaration is made once
> in the dashboard, and until Shopify approves the app, webhook payloads for
> unapproved fields are **redacted** (e.g. `phone: null`) rather than omitted.

---

## Step 1 — Select a distribution method (one-time prerequisite)

You can't request data access before the app has a distribution method:

- **Dev Dashboard** (dev.shopify.com): open the app → **Distribution** → pick
  *App Store*, *Custom*, or *Single store / draft link*.
- **Partner Dashboard** (partners.shopify.com): app → **Distribution** → same.

## Step 2 — Declare protected customer data + fields

Where the form lives depends on where your app was created:

- **Dev Dashboard:** app → **Configuration** → **Customer data** section →
  select the data types and fields.
- **Partner Dashboard:** app → **API access requests** (sidebar) →
  **Protected customer data access** → **Request access** →
  select **Protected customer data**, save; then select the **fields**, save;
  then complete **Data protection details**.

### What to select and paste (Reach uses the *minimum* needed)

Reach only uses **email** and **phone**. Do NOT request name or address —
Shopify approves the *minimum required* data, and unapproved fields are simply
redacted from payloads (Reach never reads them anyway).

**Protected customer data — reason:**

```text
Reach is an ads conversion-tracking app. Merchants install it to attribute
ad-driven purchases. It processes order and checkout webhook payloads to
(a) record purchase conversion value, currency, and line items in the
merchant's analytics dashboard, and (b) hash (SHA-256) the customer's email
and phone and forward them to the merchant's configured OpenAI Ads
Conversions API endpoint as attribution signals on the merchant's behalf.
Data is never sold or used for any other purpose. Visitor identifiers and
contact details are stored only while the app is installed, and all of them
are deleted when the app receives customers/redact or shop/redact.
```

**Email — reason:**

```text
The customer email from order/checkout webhooks is normalized and SHA-256
hashed, then forwarded to the merchant's configured OpenAI Ads Conversions
API endpoint as an attribution signal (the "em" field) so ad clicks can be
matched to purchases. The plain-text email is never displayed, exported, or
used for any other purpose, and is deleted on customers/redact or uninstall.
```

**Phone — reason:**

```text
The customer phone number from order/checkout webhooks is normalized (E.164)
and SHA-256 hashed, then forwarded to the merchant's configured OpenAI Ads
Conversions API endpoint as an attribution signal (the "ph" field) so ad
clicks can be matched to purchases — this is essential for cash-on-delivery
orders, the dominant checkout method in India. The plain-text number is
never displayed, exported, or used for any other purpose, and is deleted on
customers/redact or uninstall.
```

**Data protection details (answers that match this codebase):**

| Question | Answer |
|---|---|
| Do you sell/sharing data? | No — data only goes to the merchant's own configured Conversions API endpoint |
| Retention | Stored only while installed; `shop/redact` (48 h after uninstall) deletes the store's rows; `customers/redact` deletes that customer's rows within 30 days (immediately in this app) |
| Consent / opt-out | The app forwards purchase events solely for the merchant's ad attribution; merchant can stop delivery at any time by uninstalling or clearing the CAPI key in Settings |
| Automated decision-making | None — events are recorded and forwarded, no profiling or decisions |

## Step 3 — Deploy again

```bash
shopify app deploy
```

- **Development store / testing:** access to the declared data works
  immediately after saving the form — no review needed. Deploy succeeds.
- **Public App Store listing:** the declaration is evaluated during app
  review (the mandatory privacy webhooks and the redaction code in this repo
  are part of what reviewers check).

## Related code in this repo

| Requirement | Where |
|---|---|
| Mandatory privacy webhooks handled | `app/Http/Controllers/WebhookController.php` (`customers/data_request`, `customers/redact`, `shop/redact`) |
| Compliance topics declared | `shopify.app.reach-openai-ads-pixel.toml` (`compliance_topics`) |
| PII actually processed | `WebhookController::purchase()` — email/phone → hashed and forwarded via `EventForwarder` → `SendCapiEvent`; visitor rows in `visitors` table |
| Deletion on redact | `customerRedact()` / `shopRedact()` |
