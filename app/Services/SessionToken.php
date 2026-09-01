<?php

namespace App\Services;

/**
 * Verifies Shopify App Bridge session tokens (HS256 JWTs signed with the app
 * secret). Claims follow the Shopify embedded-app spec:
 *
 *   iss  => https://{shop}/admin
 *   dest => https://{shop}
 *   aud  => {API_KEY}
 *   exp / nbf / iat / jti / sid
 */
class SessionToken
{
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $signature = $this->base64UrlDecode($signatureB64);
        $expected = hash_hmac(
            'sha256',
            "{$headerB64}.{$payloadB64}",
            (string) config('shopify.api_secret'),
            true
        );

        if (! is_string($signature) || ! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode((string) $this->base64UrlDecode($payloadB64), true);
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['aud'] ?? null) !== config('shopify.api_key')) {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }
        if (($payload['nbf'] ?? 0) > time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Extract the shop domain from the token's dest/iss claims.
     */
    public function shopDomain(array $claims): ?string
    {
        $origin = $claims['dest'] ?? $claims['iss'] ?? null;
        if (! $origin) {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }

    protected function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'));
    }
}
