<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    /** Rental units are capital equipment; sales items are resale stock. */
    public const TYPE_RENTAL = 'rental';
    public const TYPE_SALES = 'sales';

    public const TYPES = [self::TYPE_RENTAL, self::TYPE_SALES];

    public const STATUS_ORDERED = 'ordered';
    public const STATUS_PARTIAL = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'business_id', 'type', 'number', 'supplier_name', 'status',
        'ordered_on', 'expected_on', 'notes', 'received_at',
    ];

    protected $casts = [
        'ordered_on' => 'date',
        'expected_on' => 'date',
        'received_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function scopeForBusiness(Builder $query, ?int $businessId): void
    {
        $query->where('business_id', $businessId);
    }

    public function isRental(): bool
    {
        return $this->type === self::TYPE_RENTAL;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_CANCELLED], true);
    }

    /** Units still owed by the supplier across every line. */
    public function outstandingQuantity(): int
    {
        return (int) $this->items->sum(fn (PurchaseOrderItem $item) => $item->outstanding());
    }

    /** Ordered value of the whole order, at the unit costs captured on it. */
    public function orderedValue(): float
    {
        return round((float) $this->items->sum(fn (PurchaseOrderItem $item) => $item->lineTotal()), 2);
    }

    /** Ordered value of the lines still outstanding. */
    public function outstandingValue(): float
    {
        return round((float) $this->items->sum(
            fn (PurchaseOrderItem $item) => $item->outstanding() * (float) $item->unit_cost
        ), 2);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ORDERED => 'Ordered',
            self::STATUS_PARTIAL => 'Partially received',
            self::STATUS_RECEIVED => 'Received',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    /**
     * Numbers are sequential per tenant and per type, so rental and sales
     * paperwork never share a series: PO-R-0001, PO-S-0001.
     */
    public static function nextNumber(int $businessId, string $type): string
    {
        $prefix = $type === self::TYPE_RENTAL ? 'PO-R-' : 'PO-S-';

        $last = static::query()
            ->where('business_id', $businessId)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** Re-derive the status from what has been received, after a receipt. */
    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $lines = $this->items()->get();

        if ($lines->isEmpty()) {
            return;
        }

        $fullyReceived = $lines->every(fn (PurchaseOrderItem $item) => $item->outstanding() === 0);
        $anythingReceived = $lines->contains(fn (PurchaseOrderItem $item) => $item->quantity_received > 0);

        $this->status = $fullyReceived
            ? self::STATUS_RECEIVED
            : ($anythingReceived ? self::STATUS_PARTIAL : self::STATUS_ORDERED);

        $this->received_at = $fullyReceived ? ($this->received_at ?? now()) : null;
        $this->save();
    }
}
