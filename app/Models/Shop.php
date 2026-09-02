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
        'pixel_id',
        'capi_token',
        'capi_url',
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
        // The endpoint is controlled by Reach, not by the merchant. Ignore
        // any legacy per-shop value (including old localhost/demo URLs).
        return config('ads.capi_url');
    }

    public function eventsLimit(): int
    {
        $limit = config("ads.plans.{$this->plan}.events_limit", 50_000);

        return (int) $limit;
    }

    public function pixelConfigured(): bool
    {
        return ! empty($this->pixel_id);
    }
}
