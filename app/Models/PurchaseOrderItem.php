<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'description',
        'quantity_ordered', 'quantity_received', 'unit_cost',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Still owed by the supplier (the backordered quantity). */
    public function outstanding(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }

    public function lineTotal(): float
    {
        return round($this->quantity_ordered * (float) $this->unit_cost, 2);
    }

    /** Product name at the time of ordering, for lines whose product was deleted. */
    public function label(): string
    {
        return $this->product?->name ?? $this->description ?? 'Deleted product';
    }
}
