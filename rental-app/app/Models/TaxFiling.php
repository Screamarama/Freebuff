<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sales-tax month marked as filed, with the figures it was filed with.
 */
class TaxFiling extends Model
{
    protected $fillable = [
        'business_id',
        'period',
        'filed_at',
        'superseded_at',
        'filed_by_id',
        'snapshot',
    ];

    protected $casts = [
        'filed_at' => 'datetime',
        'superseded_at' => 'datetime',
        'snapshot' => 'array',
    ];

    /** The version a month is currently filed with. */
    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_id');
    }
}
