<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = [
        'shopify_domain',
        'access_token',
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
            'installed_at'    => 'datetime',
            'uninstalled_at'  => 'datetime',
            'events_reset_at' => 'datetime',
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

    public function isInstalled(): bool
    {
        return $this->uninstalled_at === null && $this->access_token !== null;
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

    public function pixelConfigured(): bool
    {
        return ! empty($this->pixel_id);
    }
}
