<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalItem extends Model
{
    protected $fillable = [
        'contract_id',
        'product_id',
        'serial_number',
        'quantity',
        'rate_type',
        'unit_price',
        'duration',
        'subtotal',
        'returned_quantity',
        'damage_fee',
        'condition_notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'damage_fee' => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'contract_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isSale(): bool
    {
        return $this->rate_type === 'sale';
    }

    /**
     * One-line equipment description for the agreement, e.g.
     * "2 × Excavator — 3 days @ $45.00" or
     * "1 × Excavator (SN: ABC123) — 3 days @ $45.00".
     */
    public function describe(): string
    {
        $name = $this->product?->name ?? 'Item';
        $serial = trim((string) $this->serial_number);
        $name .= $serial === '' ? '' : " (SN: {$serial})";
        $price = '$' . number_format((float) $this->unit_price, 2);

        if ($this->isSale()) {
            return "{$this->quantity} × {$name} — Sale @ {$price}";
        }

        $unit = $this->rate_type . ((int) $this->duration > 1 ? 's' : '');

        return "{$this->quantity} × {$name} — {$this->duration} {$unit} @ {$price}";
    }

    /** Line subtotal: qty × duration × price for rentals, qty × price for sales. */
    public static function computeSubtotal(string $rateType, float $unitPrice, int $duration, int $quantity): float
    {
        $quantity = max(1, $quantity);
        if ($rateType === 'sale') {
            return round($quantity * $unitPrice, 2);
        }

        return round($quantity * max(1, $duration) * $unitPrice, 2);
    }
}
