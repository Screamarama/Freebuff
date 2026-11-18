<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax change made after an agreement was billed: the delta against what
 * the tax was before, dated when it happened.
 */
class TaxAdjustment extends Model
{
    protected $fillable = [
        'rental_contract_id',
        'amount',
        'adjusted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'adjusted_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }
}
