<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = [
        'shopify_domain',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'refresh_token_expires_at',
        'token_scopes',
        'plan',
        'plan_status',
        'pixel_id',              // OpenAI Ads Pixel ID (merchant credential)
        'capi_token',            // OpenAI Conversions API key
        'capi_url',              // optional CAPI endpoint override
        'web_pixel_id',          // Shopify WebPixel GID (Customer Events)
        'advertiser_api_key',    // optional OpenAI Ads Manager / Advertiser API key
        'installed_at',
        'uninstalled_at',
        'monthly_event_count',
        'events_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'installed_at'             => 'datetime',
            'uninstalled_at'           => 'datetime',
            'token_expires_at'         => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'events_reset_at'          => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    public function isInstalled(): bool
    {
        return $this->uninstalled_at === null && $this->access_token !== null;
    }

    /**
     * True when the offline access token is missing or about to expire and
     * needs a refresh-token grant (2026 expiring-token policy).
     */
    public function tokenNeedsRefresh(): bool
    {
        if (! $this->access_token) {
            return false;
        }

        if (! $this->token_expires_at) {
            return false; // legacy non-expiring token
        }

        // Refresh 5 minutes ahead of the deadline to avoid racing expiry.
        return $this->token_expires_at->lte(now()->addMinutes(5));
    }

    public function refreshTokenUsable(): bool
    {
        if (! $this->refresh_token) {
            return false;
        }

        return ! $this->refresh_token_expires_at
            || $this->refresh_token_expires_at->isFuture();
    }

    public function isOnPaidPlan(): bool
    {
        return in_array($this->plan, ['basic', 'growth'], true) && $this->plan_status === 'active';
    }

    public function onTrial(): bool
    {
        return in_array($this->plan, ['basic', 'growth'], true) && $this->plan_status === 'trial';
    }

    public function capiEndpoint(): ?string
    {
        return $this->capi_url ?: config('ads.capi_url');
    }

    public function eventsLimit(): int
    {
        $limit = config("ads.plans.{$this->plan}.events_limit", 50_000);

        return (int) $limit;
    }

    /**
     * OpenAI Ads Pixel ID is configured (merchant credential from Ads Manager).
     */
    public function pixelConfigured(): bool
    {
        return ! empty($this->pixel_id) && ! str_starts_with((string) $this->pixel_id, 'gid://');
    }

    /**
     * Ready to deliver events to OpenAI (pixel + CAPI key).
     */
    public function capiReady(): bool
    {
        return $this->pixelConfigured() && ! empty($this->capi_token);
    }

    /**
     * Shopify Customer Events web pixel has been activated for this store.
     */
    public function webPixelActive(): bool
    {
        return ! empty($this->web_pixel_id);
    }

    public function advertiserKeyConfigured(): bool
    {
        return ! empty($this->advertiser_api_key);
    }
}
