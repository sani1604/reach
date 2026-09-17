<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $fillable = [
        'shop_id',
        'vid',
        'fbc',
        'fbp',
        'oppref',   // OpenAI ad-click attribution id
        'obref',    // OpenAI opaque browser reference
        'email',
        'phone',
        'order_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
