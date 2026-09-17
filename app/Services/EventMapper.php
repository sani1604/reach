<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Maps Reach internal event names onto the OpenAI Ads Conversions API taxonomy.
 *
 * Internal (dashboard): PageView, ViewContent, AddToCart, InitiateCheckout, Purchase
 * OpenAI CAPI types:    page_viewed, contents_viewed, items_added, checkout_started, order_created
 *
 * @see https://developers.openai.com/ads/conversions-api
 * @see https://developers.openai.com/ads/supported-events
 */
class EventMapper
{
    /** ISO 4217 currencies with zero decimal places. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Build one OpenAI CAPI event object (the item inside `events: []`).
     */
    public function build(string $eventName, array $data = []): array
    {
        $type = $this->openAiType($eventName);
        $eventId = (string) ($data['event_id'] ?? Str::uuid());
        $timestampMs = $this->timestampMs($data);

        $event = [
            'id'            => $eventId,
            'type'          => $type,
            'timestamp_ms'  => $timestampMs,
            'action_source' => $this->actionSource($data),
        ];

        if ($type === 'custom') {
            $event['custom_event_name'] = $this->customEventName($eventName, $data);
        }

        if (! empty($data['oppref'])) {
            $event['oppref'] = (string) $data['oppref'];
        } elseif (! empty($data['user_data']['oppref'])) {
            $event['oppref'] = (string) $data['user_data']['oppref'];
        }

        $sourceUrl = $data['source_url'] ?? $data['url'] ?? null;
        if ($sourceUrl) {
            $event['source_url'] = (string) $sourceUrl;
        } elseif (($event['action_source'] ?? '') === 'web') {
            // Web events require source_url — fall back to the shop domain if known.
            if (! empty($data['shop_domain'])) {
                $event['source_url'] = 'https://'.ltrim((string) $data['shop_domain'], '/');
            }
        }

        $user = $this->userData((array) ($data['user_data'] ?? []), $data);
        if ($user) {
            $event['user'] = $user;
        }

        $event['data'] = $this->eventData($type, $data);

        return $event;
    }

    public function openAiType(string $eventName): string
    {
        $defaults = [
            'PageView'          => 'page_viewed',
            'ViewContent'       => 'contents_viewed',
            'AddToCart'         => 'items_added',
            'InitiateCheckout'  => 'checkout_started',
            'Purchase'          => 'order_created',
            'PurchaseCancelled' => 'custom',
            'TestEvent'         => 'custom',
        ];

        $map = array_merge($defaults, (array) (function_exists('config') ? config('ads.event_types', []) : []));

        return $map[$eventName] ?? (str_contains($eventName, '_')
            ? strtolower($eventName)
            : 'custom');
    }

    protected function customEventName(string $eventName, array $data): string
    {
        if (! empty($data['custom_event_name'])) {
            return $this->sanitizeCustomName((string) $data['custom_event_name']);
        }

        // PurchaseCancelled / TestEvent → purchase_cancelled / test_event
        $slug = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $eventName) ?? $eventName);
        $slug = preg_replace('/[^a-z0-9_-]+/', '_', $slug) ?: 'custom_event';

        return $this->sanitizeCustomName($slug);
    }

    protected function sanitizeCustomName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_-]+/', '_', $name) ?: 'custom_event';
        $name = trim($name, '_-');

        if ($name === '' || strlen($name) > 64) {
            $name = substr($name ?: 'custom_event', 0, 64);
        }

        // Must start/end with letter or digit.
        if (! preg_match('/^[a-z0-9].*[a-z0-9]$/', $name) && strlen($name) === 1) {
            // single char ok if alnum
        } elseif (! preg_match('/^[a-z0-9]/', $name)) {
            $name = 'e_'.$name;
        }

        return substr($name, 0, 64);
    }

    protected function actionSource(array $data): string
    {
        $src = strtolower((string) ($data['action_source'] ?? 'web'));

        // Legacy / internal aliases → OpenAI enum.
        $aliases = [
            'website'   => 'web',
            'browser'   => 'web',
            'app'       => 'mobile_app',
            'mobile'    => 'mobile_app',
            'store'     => 'physical_store',
            'phone'     => 'phone_call',
        ];
        $src = $aliases[$src] ?? $src;

        $allowed = ['web', 'mobile_app', 'offline', 'physical_store', 'phone_call', 'email', 'other'];

        return in_array($src, $allowed, true) ? $src : 'web';
    }

    protected function timestampMs(array $data): int
    {
        if (! empty($data['timestamp_ms'])) {
            return (int) $data['timestamp_ms'];
        }

        if (! empty($data['event_time'])) {
            $t = (int) $data['event_time'];

            // Already ms?
            return $t > 10_000_000_000 ? $t : $t * 1000;
        }

        return (int) round(microtime(true) * 1000);
    }

    /**
     * Build the `data` object. Most commerce events use type=contents.
     */
    protected function eventData(string $type, array $data): array
    {
        // customer_action events (app_*, lead_*, etc.) — we rarely emit these
        // from Shopify, but keep the shape correct if asked.
        $customerAction = in_array($type, [
            'app_installed', 'app_opened', 'lead_created',
            'registration_completed', 'appointment_scheduled',
        ], true);

        if ($customerAction) {
            $out = ['type' => 'customer_action'];
            $this->attachAmount($out, $data);

            return $out;
        }

        if ($type === 'custom') {
            $out = ['type' => 'custom'];
            $this->attachAmount($out, $data);
            $contents = $this->contents($data);
            if ($contents) {
                $out['contents'] = $contents;
            }

            return $out;
        }

        // Default: contents shape (page_viewed, contents_viewed, items_added,
        // checkout_started, order_created).
        $out = ['type' => 'contents'];
        $this->attachAmount($out, $data);
        $contents = $this->contents($data);
        if ($contents) {
            $out['contents'] = $contents;
        }

        return $out;
    }

    protected function attachAmount(array &$out, array $data): void
    {
        if (! isset($data['value']) && ! isset($data['amount'])) {
            return;
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'USD'));
        $raw = $data['amount'] ?? $data['value'];

        // If the caller already passed an integer minor-unit amount, keep it.
        if (isset($data['amount_minor'])) {
            $out['amount'] = (int) $data['amount_minor'];
        } elseif (is_int($raw) && ! isset($data['value'])) {
            $out['amount'] = $raw;
        } else {
            $out['amount'] = $this->toMinorUnits((float) $raw, $currency);
        }

        $out['currency'] = $currency;
    }

    /**
     * Convert a major-unit money value (e.g. 14.99) to the ISO 4217 minor unit
     * integer OpenAI expects (e.g. 1499 for USD).
     */
    public function toMinorUnits(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        $factor = in_array($currency, self::ZERO_DECIMAL, true) ? 1 : 100;

        return (int) round($amount * $factor);
    }

    protected function contents(array $data): array
    {
        $products = $data['products'] ?? $data['contents'] ?? null;
        if (! is_array($products) || ! $products) {
            // Fall back to content_ids if present.
            if (! empty($data['content_ids']) && is_array($data['content_ids'])) {
                return array_values(array_map(function ($id) use ($data) {
                    $item = [
                        'id'           => (string) $id,
                        'content_type' => (string) ($data['content_type'] ?? 'product'),
                    ];
                    if (! empty($data['quantity'])) {
                        $item['quantity'] = (int) $data['quantity'];
                    }

                    return $item;
                }, $data['content_ids']));
            }

            return [];
        }

        $currency = strtoupper((string) ($data['currency'] ?? 'USD'));

        return array_values(array_map(function ($p) use ($currency, $data) {
            $p = (array) $p;
            $item = [
                'id'           => (string) ($p['id'] ?? ''),
                'name'         => (string) ($p['title'] ?? $p['name'] ?? ''),
                'content_type' => (string) ($p['content_type'] ?? $data['content_type'] ?? 'product'),
                'quantity'     => (int) ($p['quantity'] ?? 1),
            ];

            if (isset($p['price']) || isset($p['amount']) || isset($p['item_price'])) {
                $price = (float) ($p['amount'] ?? $p['item_price'] ?? $p['price'] ?? 0);
                $itemCurrency = strtoupper((string) ($p['currency'] ?? $currency));
                $item['amount'] = $this->toMinorUnits($price, $itemCurrency);
                $item['currency'] = $itemCurrency;
            }

            // Drop empty name to keep payloads tidy.
            if ($item['name'] === '') {
                unset($item['name']);
            }

            return $item;
        }, $products));
    }

    /**
     * Normalize + SHA-256 hash user identifiers per OpenAI Ads rules.
     */
    protected function userData(array $u, array $data = []): array
    {
        $out = [];

        $emails = [];
        if (! empty($u['email'])) {
            $emails[] = $u['email'];
        }
        if (! empty($u['emails']) && is_array($u['emails'])) {
            $emails = array_merge($emails, $u['emails']);
        }
        $hashedEmails = [];
        foreach ($emails as $email) {
            $h = $this->hashEmail((string) $email);
            if ($h) {
                $hashedEmails[] = $h;
            }
        }
        // Accept pre-hashed values.
        if (! empty($u['emails_sha256']) && is_array($u['emails_sha256'])) {
            $hashedEmails = array_merge($hashedEmails, $u['emails_sha256']);
        } elseif (! empty($u['em'])) {
            $hashedEmails[] = $this->ensureHash((string) $u['em'], fn () => $this->hashEmail((string) $u['em']));
        }
        $hashedEmails = array_values(array_unique(array_filter($hashedEmails)));
        if ($hashedEmails) {
            $out['emails_sha256'] = array_slice($hashedEmails, 0, 3);
        }

        $phones = [];
        if (! empty($u['phone'])) {
            $phones[] = $u['phone'];
        }
        if (! empty($u['phones']) && is_array($u['phones'])) {
            $phones = array_merge($phones, $u['phones']);
        }
        $hashedPhones = [];
        foreach ($phones as $phone) {
            $h = $this->hashPhone((string) $phone);
            if ($h) {
                $hashedPhones[] = $h;
            }
        }
        if (! empty($u['phone_numbers_sha256']) && is_array($u['phone_numbers_sha256'])) {
            $hashedPhones = array_merge($hashedPhones, $u['phone_numbers_sha256']);
        } elseif (! empty($u['ph'])) {
            $hashedPhones[] = $this->ensureHash((string) $u['ph'], fn () => $this->hashPhone((string) $u['ph']));
        }
        $hashedPhones = array_values(array_unique(array_filter($hashedPhones)));
        if ($hashedPhones) {
            $out['phone_numbers_sha256'] = array_slice($hashedPhones, 0, 3);
        }

        $externalIds = [];
        if (! empty($u['external_id'])) {
            $externalIds[] = $this->hashExternalId((string) $u['external_id']);
        }
        if (! empty($u['vid'])) {
            $externalIds[] = $this->hashExternalId((string) $u['vid']);
        }
        if (! empty($data['vid'])) {
            $externalIds[] = $this->hashExternalId((string) $data['vid']);
        }
        if (! empty($u['external_ids_sha256']) && is_array($u['external_ids_sha256'])) {
            $externalIds = array_merge($externalIds, $u['external_ids_sha256']);
        }
        $externalIds = array_values(array_unique(array_filter($externalIds)));
        if ($externalIds) {
            $out['external_ids_sha256'] = array_slice($externalIds, 0, 3);
        }

        if (! empty($u['obref'])) {
            $out['obref'] = (string) $u['obref'];
        }
        if (! empty($u['client_ip_address']) || ! empty($u['ip_address'])) {
            $out['ip_address'] = (string) ($u['client_ip_address'] ?? $u['ip_address']);
        }
        if (! empty($u['client_user_agent']) || ! empty($u['user_agent'])) {
            $out['user_agent'] = (string) ($u['client_user_agent'] ?? $u['user_agent']);
        }
        if (! empty($u['country']) || ! empty($u['countries'])) {
            $countries = $u['countries'] ?? [(string) $u['country']];
            $out['countries'] = array_values(array_filter(array_map(
                fn ($c) => strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', (string) $c) ?? '', 0, 2)),
                (array) $countries
            )));
        }
        if (! empty($u['city']) || ! empty($u['cities'])) {
            $out['cities'] = array_values(array_filter(array_map(
                'strval',
                (array) ($u['cities'] ?? [$u['city']])
            )));
        }
        if (! empty($u['zip']) || ! empty($u['postal_code']) || ! empty($u['postal_codes'])) {
            $out['postal_codes'] = array_values(array_filter(array_map(
                'strval',
                (array) ($u['postal_codes'] ?? [$u['postal_code'] ?? $u['zip']])
            )));
        }
        if (! empty($u['region']) || ! empty($u['regions'])) {
            $out['regions'] = array_values(array_filter(array_map(
                'strval',
                (array) ($u['regions'] ?? [$u['region']])
            )));
        }

        // Optional name hashing (improves match rates when oppref is missing).
        if (! empty($u['first_name']) || ! empty($u['first_names_sha256'])) {
            $names = [];
            if (! empty($u['first_name'])) {
                $h = $this->hashName((string) $u['first_name']);
                if ($h) {
                    $names[] = $h;
                }
            }
            if (! empty($u['first_names_sha256']) && is_array($u['first_names_sha256'])) {
                $names = array_merge($names, $u['first_names_sha256']);
            }
            $names = array_values(array_unique(array_filter($names)));
            if ($names) {
                $out['first_names_sha256'] = array_slice($names, 0, 3);
            }
        }
        if (! empty($u['last_name']) || ! empty($u['last_names_sha256'])) {
            $names = [];
            if (! empty($u['last_name'])) {
                $h = $this->hashName((string) $u['last_name']);
                if ($h) {
                    $names[] = $h;
                }
            }
            if (! empty($u['last_names_sha256']) && is_array($u['last_names_sha256'])) {
                $names = array_merge($names, $u['last_names_sha256']);
            }
            $names = array_values(array_unique(array_filter($names)));
            if ($names) {
                $out['last_names_sha256'] = array_slice($names, 0, 3);
            }
        }

        return $out;
    }

    /**
     * Normalize a first/last name per OpenAI rules, then SHA-256.
     * Lowercase, strip whitespace + ASCII punctuation; keep non-ASCII.
     */
    protected function hashName(string $name): ?string
    {
        if ($this->isSha256($name)) {
            return strtolower($name);
        }

        $normalized = mb_strtolower($name, 'UTF-8');
        $normalized = preg_replace('/[\s\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]+/u', '', $normalized) ?? '';
        if ($normalized === '') {
            return null;
        }

        return hash('sha256', $normalized);
    }

    protected function hashEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! str_contains($email, '@')) {
            // Might already be a hash.
            return $this->isSha256($email) ? $email : null;
        }

        return hash('sha256', $email);
    }

    protected function hashPhone(string $phone): ?string
    {
        if ($this->isSha256($phone)) {
            return strtolower($phone);
        }

        // Keep country code, strip formatting, leading +, leading zeroes.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return hash('sha256', $digits);
    }

    protected function hashExternalId(string $id): ?string
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if ($this->isSha256($id)) {
            return strtolower($id);
        }

        return hash('sha256', $id);
    }

    protected function ensureHash(string $value, callable $hasher): ?string
    {
        if ($this->isSha256($value)) {
            return strtolower($value);
        }

        return $hasher();
    }

    protected function isSha256(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/i', $value);
    }
}
