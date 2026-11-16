<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A photo for the Activity Report, stored on the local (private) disk. */
class LiquidationPhoto extends Model
{
    protected $fillable = [
        'path',
        'original_name',
        'position',
    ];

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class);
    }
}
