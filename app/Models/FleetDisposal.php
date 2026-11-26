<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a unit left the fleet: sold on or scrapped, what it fetched and what it
 * was worth on that day. The book value is frozen here so later term changes
 * cannot rewrite the gain or loss.
 */
class FleetDisposal extends Model
{
    public const SOLD = 'sold';

    public const SCRAPPED = 'scrapped';

    protected $fillable = [
        'business_id', 'product_id', 'type', 'disposed_on', 'price',
        'book_value', 'notes', 'recorded_by_id',
    ];

    protected $casts = [
        'disposed_on' => 'date',
        'price' => 'decimal:2',
        'book_value' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /** Proceeds less book value, or null when the unit had no book value. */
    public function gain(): ?float
    {
        if ($this->book_value === null) {
            return null;
        }

        return round((float) $this->price - (float) $this->book_value, 2);
    }

    public function label(): string
    {
        return $this->type === self::SOLD ? 'Sold' : 'Scrapped';
    }
}
