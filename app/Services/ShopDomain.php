<?php

namespace App\Services;

/**
 * Normalize and validate Shopify store domains.
 *
 * Only accepts bare handles or *.myshopify.com hosts — never arbitrary
 * attacker-controlled hostnames (open redirect / SSRF risk).
 */
class ShopDomain
{
    /**
     * @return string|null Lowercase shop.myshopify.com domain, or null if invalid.
     */
    public static function normalize(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        // Strip scheme / path / port noise.
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];
        $domain = explode('?', $domain, 2)[0];
        $domain = explode('#', $domain, 2)[0];
        $domain = explode(':', $domain, 2)[0]; // drop port
        $domain = rtrim($domain, '.');

        // Bare handle → append myshopify.com (Shopify embedded header form).
        if ($domain !== '' && ! str_contains($domain, '.')) {
            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $domain)) {
                return null;
            }

            return $domain.'.myshopify.com';
        }

        // Only *.myshopify.com is a valid shop identity for this app.
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain)) {
            return null;
        }

        return $domain;
    }

    /**
     * True when the host is a Shopify admin origin we may frame / trust.
     */
    public static function isShopifyAdminHost(?string $host): bool
    {
        if (! $host) {
            return false;
        }

        $host = strtolower($host);

        return (bool) preg_match(
            '/(^|\.)(admin\.shopify\.com|myshopify\.com|admin\.spin\.dev)$/',
            $host
        );
    }

    /**
     * Decode + validate the OAuth `host` query param (base64 of admin host/path).
     * Returns a safe admin base URL host/path fragment, or null.
     */
    public static function safeAdminHostParam(?string $hostParam): ?string
    {
        if (! $hostParam) {
            return null;
        }

        $decoded = base64_decode(strtr($hostParam, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            // Shopify also sends standard base64.
            $decoded = base64_decode($hostParam, true);
        }
        if ($decoded === false || $decoded === '') {
            return null;
        }

        // Expected shapes: "admin.shopify.com/store/handle" or "{shop}.myshopify.com/admin"
        $decoded = strtolower(trim($decoded));
        $decoded = preg_replace('#^https?://#', '', $decoded) ?? $decoded;
        $decoded = explode('?', $decoded, 2)[0];
        $decoded = explode('#', $decoded, 2)[0];
        $decoded = ltrim($decoded, '/');

        if ($decoded === '' || str_contains($decoded, '..') || str_contains($decoded, '@')) {
            return null;
        }

        $host = explode('/', $decoded, 2)[0];
        if (! self::isShopifyAdminHost($host)) {
            return null;
        }

        // Whitelist path characters only.
        if (! preg_match('#^[a-z0-9._/-]+$#', $decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Safe in-app redirect path (no scheme, no //, no external host).
     */
    public static function safeAppPath(?string $path, string $default = '/dashboard'): string
    {
        if (! is_string($path) || $path === '') {
            return $default;
        }

        $path = trim($path);
        if ($path === '' || str_starts_with($path, '//') || str_contains($path, '://')) {
            return $default;
        }

        // Allow only relative app paths.
        if ($path[0] !== '/') {
            $path = '/'.$path;
        }

        // Block path traversal / control chars.
        if (str_contains($path, '..') || preg_match('/[\x00-\x1f]/', $path)) {
            return $default;
        }

        // Strip query/hash from the path portion for navigation targets when needed;
        // keep simple query strings that are internal.
        if (! preg_match('#^/[a-zA-Z0-9/_\-.?=&%]*$#', $path)) {
            return $default;
        }

        return $path;
    }
}
