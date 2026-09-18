<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Resolves which Shopify Partner app this request belongs to.
 *
 * Same Laravel codebase powers:
 *  - reach   (private / custom)  "Reach — OpenAI Ads Pixel"
 *  - pixelai (public App Store)  "PixelAI: ChatGPT & OpenAI Ads"
 *
 * Each app has its own Client ID/Secret, handle, and branding. Shop rows are
 * scoped by app_key so installing both on one store never clobbers tokens.
 */
class ShopifyApp
{
    public const CONTEXT_KEY = 'shopify_app_key';

    /**
     * Active app key for this process/request (`reach` | `pixelai`).
     */
    public static function key(): string
    {
        $fromContext = Context::get(self::CONTEXT_KEY);
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        $configured = (string) config('reach.app', 'reach');

        return self::isKnown($configured) ? $configured : 'reach';
    }

    public static function setKey(string $key): void
    {
        if (! self::isKnown($key)) {
            return;
        }

        Context::add(self::CONTEXT_KEY, $key);

        // Keep legacy config() readers in sync for this request.
        $brand = self::brand($key);
        config([
            'shopify.api_key'    => self::apiKey($key),
            'shopify.api_secret' => self::apiSecret($key),
            'shopify.app_handle' => $brand['handle'] ?? config('shopify.app_handle'),
            'app.name'           => $brand['name'] ?? config('app.name'),
        ]);
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, config('reach.apps', []));
    }

    /**
     * Branding bag for the active (or given) app.
     *
     * @return array{key:string,name:string,tagline:string,full_name:string,mark:string,handle:string,distribution:string,host?:string}
     */
    public static function brand(?string $key = null): array
    {
        $key = $key ?: self::key();
        $apps = config('reach.apps', []);

        return $apps[$key] ?? ($apps['reach'] ?? [
            'key' => 'reach',
            'name' => 'Reach',
            'tagline' => 'OpenAI Ads Pixel',
            'full_name' => 'Reach — OpenAI Ads Pixel',
            'mark' => 'R',
            'handle' => 'reach-openai-ads-pixel',
            'distribution' => 'private',
        ]);
    }

    public static function name(?string $key = null): string
    {
        return (string) (self::brand($key)['name'] ?? 'Reach');
    }

    public static function fullName(?string $key = null): string
    {
        return (string) (self::brand($key)['full_name'] ?? self::name($key));
    }

    public static function tagline(?string $key = null): string
    {
        return (string) (self::brand($key)['tagline'] ?? '');
    }

    public static function mark(?string $key = null): string
    {
        return (string) (self::brand($key)['mark'] ?? 'R');
    }

    public static function handle(?string $key = null): string
    {
        return (string) (self::brand($key)['handle'] ?? config('shopify.app_handle'));
    }

    public static function apiKey(?string $key = null): ?string
    {
        $key = $key ?: self::key();

        // Prefer per-app env, fall back to shared SHOPIFY_API_KEY for single-app deploys.
        $specific = match ($key) {
            'pixelai' => env('SHOPIFY_PIXELAI_API_KEY'),
            default   => env('SHOPIFY_REACH_API_KEY'),
        };

        if (is_string($specific) && $specific !== '') {
            return $specific;
        }

        // When SHOPIFY_APP matches, the generic key is for this app.
        if ((string) config('reach.app') === $key || ! env('SHOPIFY_PIXELAI_API_KEY')) {
            return config('shopify.api_key') ? (string) config('shopify.api_key') : env('SHOPIFY_API_KEY');
        }

        return env('SHOPIFY_API_KEY');
    }

    public static function apiSecret(?string $key = null): ?string
    {
        $key = $key ?: self::key();

        $specific = match ($key) {
            'pixelai' => env('SHOPIFY_PIXELAI_API_SECRET'),
            default   => env('SHOPIFY_REACH_API_SECRET'),
        };

        if (is_string($specific) && $specific !== '') {
            return $specific;
        }

        if ((string) config('reach.app') === $key || ! env('SHOPIFY_PIXELAI_API_SECRET')) {
            return config('shopify.api_secret') ? (string) config('shopify.api_secret') : env('SHOPIFY_API_SECRET');
        }

        return env('SHOPIFY_API_SECRET');
    }

    /**
     * Resolve app key from an HTTP request (host / JWT aud / explicit query).
     */
    public static function resolveFromRequest(Request $request): string
    {
        // 1) Explicit override (boot links, internal tools).
        $explicit = $request->query('app') ?: $request->input('app');
        if (is_string($explicit) && self::isKnown($explicit)) {
            return $explicit;
        }

        // 2) Session token audience → Partner Client ID.
        $jwt = $request->bearerToken()
            ?: $request->header('X-Shopify-Session-Token')
            ?: ($request->query('id_token') ?: $request->input('id_token'));
        if (is_string($jwt) && $jwt !== '') {
            $fromJwt = self::keyFromSessionToken($jwt);
            if ($fromJwt) {
                return $fromJwt;
            }
        }

        // 3) Request host matches a branded app host.
        $host = strtolower((string) $request->getHost());
        foreach (config('reach.apps', []) as $key => $brand) {
            $brandHost = strtolower((string) ($brand['host'] ?? ''));
            if ($brandHost !== '' && ($host === $brandHost || str_ends_with($host, '.'.$brandHost))) {
                return $key;
            }
        }

        // 4) Env default.
        return self::key();
    }

    /**
     * Map a session JWT `aud` claim to an app key (without full verify —
     * caller still verifies signature with the matching secret).
     */
    public static function keyFromSessionToken(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(self::b64((string) $parts[1]), true);
        if (! is_array($payload)) {
            return null;
        }

        $aud = $payload['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];

        foreach (array_keys(config('reach.apps', [])) as $key) {
            $apiKey = self::apiKey($key);
            if ($apiKey && in_array($apiKey, $audiences, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Verify webhook HMAC trying each configured app secret.
     * Returns the matching app key, or null.
     */
    public static function appKeyFromWebhookHmac(string $rawBody, ?string $hmacHeader): ?string
    {
        if (! $hmacHeader) {
            return null;
        }

        foreach (array_keys(config('reach.apps', [])) as $key) {
            $secret = self::apiSecret($key);
            if (! $secret) {
                continue;
            }
            $calculated = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
            if (hash_equals($hmacHeader, $calculated)) {
                return $key;
            }
        }

        // Fallback: currently configured single secret.
        $fallback = (string) config('shopify.api_secret');
        if ($fallback !== '') {
            $calculated = base64_encode(hash_hmac('sha256', $rawBody, $fallback, true));
            if (hash_equals($hmacHeader, $calculated)) {
                return self::key();
            }
        }

        return null;
    }

    /**
     * All app keys that have credentials configured.
     *
     * @return list<string>
     */
    public static function configuredKeys(): array
    {
        $keys = [];
        foreach (array_keys(config('reach.apps', [])) as $key) {
            if (self::apiKey($key) && self::apiSecret($key)) {
                $keys[] = $key;
            }
        }

        return $keys !== [] ? $keys : [self::key()];
    }

    protected static function b64(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($input, '-_', '+/'), true);
    }
}
