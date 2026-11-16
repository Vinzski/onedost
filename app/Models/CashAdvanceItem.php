<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One leg of the planned Itinerary of Travel. */
class CashAdvanceItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'position',
        'date',
        'place',
        'departure_time',
        'arrival_time',
        'transport_means',
        'transport_cost',
        'per_diem',
        'other_cost',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'transport_cost' => 'decimal:2',
            'per_diem' => 'decimal:2',
            'other_cost' => 'decimal:2',
        ];
    }

    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    public function total(): float
    {
        return (float) $this->transport_cost + (float) $this->per_diem + (float) $this->other_cost;
    }
}
