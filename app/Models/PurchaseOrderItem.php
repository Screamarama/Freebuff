<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'equipment_model_id', 'description',
        'quantity_ordered', 'quantity_received', 'unit_cost',
        'useful_life_months', 'salvage_value',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_cost' => 'decimal:2',
        'useful_life_months' => 'integer',
        'salvage_value' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The catalogue model a rental line orders. */
    public function equipmentModel(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class);
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

    /** What was ordered, for lines whose product or model was later removed. */
    public function label(): string
    {
        return $this->equipmentModel?->name ?? $this->product?->name ?? $this->description ?? 'Deleted item';
    }
}
