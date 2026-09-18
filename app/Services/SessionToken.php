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
 *
 * Multi-app: tries each configured Partner app secret/audience so the same
 * host can serve both Reach (private) and PixelAI (public).
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

        $header = json_decode((string) $this->base64UrlDecode($headerB64), true);
        if (! is_array($header) || strtolower((string) ($header['alg'] ?? '')) !== 'hs256') {
            return null;
        }

        $signature = $this->base64UrlDecode($signatureB64);
        if (! is_string($signature)) {
            return null;
        }

        $payload = json_decode((string) $this->base64UrlDecode($payloadB64), true);
        if (! is_array($payload)) {
            return null;
        }

        $leeway = 120;
        if (($payload['exp'] ?? 0) < time() - $leeway) {
            return null;
        }
        if (($payload['nbf'] ?? 0) > time() + $leeway) {
            return null;
        }

        $aud = $payload['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];

        // Prefer the app already resolved for this request, then every configured app.
        $keys = array_values(array_unique(array_filter([
            ShopifyApp::key(),
            ...ShopifyApp::configuredKeys(),
        ])));

        foreach ($keys as $appKey) {
            $secret = ShopifyApp::apiSecret($appKey);
            $apiKey = ShopifyApp::apiKey($appKey);
            if (! $secret || ! $apiKey) {
                continue;
            }

            $expected = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true);
            if (! hash_equals($expected, $signature)) {
                continue;
            }

            if (! in_array($apiKey, $audiences, true)) {
                continue;
            }

            // Bind the matching app for the rest of the request.
            ShopifyApp::setKey($appKey);

            return $payload;
        }

        return null;
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

        return $host ? ShopDomain::normalize(strtolower($host)) : null;
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
