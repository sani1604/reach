<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Charge extends Model
{
    protected $fillable = [
        'shop_id',
        'charge_id',
        'type',
        'plan',
        'status',
        'amount',
        'currency',
        'billing_on',
        'activated_on',
        'cancelled_on',
        'trial_ends_on',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'billing_on'     => 'datetime',
            'activated_on'   => 'datetime',
            'cancelled_on'   => 'datetime',
            'trial_ends_on'  => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
