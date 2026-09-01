<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = [
        'shop_id',
        'event_name',
        'event_id',
        'dedup_key',
        'source',
        'order_id',
        'order_name',
        'currency',
        'value',
        'occurred_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'value'       => 'decimal:2',
            'occurred_at' => 'datetime',
            'payload'     => 'array',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
