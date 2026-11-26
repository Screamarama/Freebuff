<?php

namespace App\Models;

use App\Services\Availability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Product extends Model
{
    /** In the bookable fleet. */
    public const FLEET_ACTIVE = 'active';

    /** Received from a purchase order, waiting to be set up and committed. */
    public const FLEET_SERVICE_HOLD = 'service_hold';

    /** Sold or scrapped: out of the fleet, kept in history. */
    public const FLEET_DISPOSED = 'disposed';

    protected $fillable = [
        'business_id', 'equipment_model_id', 'purchase_order_item_id', 'equipment_number', 'sku', 'manufacturer', 'model_number', 'serial_number',
        'purchase_order', 'purchase_date', 'useful_life_months', 'salvage_value', 'depreciation_started_on',
        'name', 'description', 'category', 'product_type',
        'rental_price_hour', 'rental_price_day', 'rental_price_week', 'rental_price_month', 'deposit_amount',
        'sale_price', 'used_sale_price', 'cost_price', 'stock_quantity', 'available_quantity',
        'image_url', 'image_path', 'is_active', 'fleet_status', 'is_featured'
    ];
    
    protected $casts = [
        'purchase_date' => 'date',
        'salvage_value' => 'decimal:2',
        'depreciation_started_on' => 'date',
        'rental_price_hour' => 'decimal:2',
        'rental_price_day' => 'decimal:2',
        'rental_price_week' => 'decimal:2',
        'rental_price_month' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'used_sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
    ];
    
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** True when the item is rented out. */
    public function isRental(): bool
    {
        return $this->product_type === 'rental';
    }

    /**
     * Rentals are free stock minus what today's reservations and live
     * agreements hold; the stored column is only the sale count.
     */
    public function getAvailableQuantityAttribute(): int
    {
        if ($this->isSale()) {
            return (int) ($this->attributes['available_quantity'] ?? 0);
        }

        // Loaded together with the product by withHeldToday(), so a list needs
        // no further query per row.
        if (array_key_exists('held_reservations', $this->attributes)) {
            return max(0, (int) $this->stock_quantity
                - (int) $this->attributes['held_reservations']
                - (int) $this->attributes['held_items']);
        }

        return app(Availability::class)->availableToday($this);
    }

    /** Load today's holdings alongside the product for derived availability. */
    public function scopeWithHeldToday(Builder $query): void
    {
        [$reserved, $onAgreements] = app(Availability::class)->heldTodaySubqueries();

        $query->select('products.*')
            ->selectSub($reserved, 'held_reservations')
            ->selectSub($onAgreements, 'held_items');
    }

    /** True when the item is sold. */
    public function isSale(): bool
    {
        return $this->product_type === 'sale';
    }

    /** Waiting in service hold for its rates and terms to be set. */
    public function isInServiceHold(): bool
    {
        return $this->fleet_status === self::FLEET_SERVICE_HOLD;
    }

    /** Sold or scrapped, so no longer part of the fleet. */
    public function isDisposed(): bool
    {
        return $this->fleet_status === self::FLEET_DISPOSED;
    }

    /** The sale or scrap record, when the unit has left the fleet. */
    public function disposal(): HasOne
    {
        return $this->hasOne(FleetDisposal::class);
    }

    public function scopeInServiceHold(Builder $query): void
    {
        $query->where('fleet_status', self::FLEET_SERVICE_HOLD);
    }

    /**
     * What an <img> should point at: the uploaded file when there is one,
     * otherwise any pasted image URL. Root-relative so it works on any host.
     */
    public function getImageSourceAttribute(): ?string
    {
        if ($this->image_path) {
            return '/storage/'.ltrim($this->image_path, '/');
        }

        return $this->image_url ?: null;
    }

    /** "Manufacturer Model (serial)" for listings, skipping missing parts. */
    public function getEquipmentLabelAttribute(): string
    {
        $model = trim(implode(' ', array_filter([$this->manufacturer, $this->model_number])));

        return trim($model . ($this->serial_number ? " (SN: {$this->serial_number})" : ''));
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** The catalogue model this unit was bought as. */
    public function equipmentModel(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class);
    }

    /** The purchase order line this unit was received on. */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * Straight-line depreciation only applies to rental units that carry a cost,
     * a useful life in months, and a date to start counting from.
     */
    public function monthlyDepreciation(): ?float
    {
        $cost = (float) $this->cost_price;
        $months = (int) $this->useful_life_months;

        if ($cost <= 0 || $months <= 0) {
            return null;
        }

        $salvage = min((float) ($this->salvage_value ?? 0), $cost);

        return round(($cost - $salvage) / $months, 2);
    }

    /** Cost less depreciation to date, never below salvage or above cost. */
    public function bookValue(?Carbon $asOf = null): ?float
    {
        $monthly = $this->monthlyDepreciation();

        if ($monthly === null) {
            return null;
        }

        $start = $this->depreciation_started_on ?? $this->purchase_date;

        if (! $start) {
            return null;
        }

        $asOf = $asOf ?? Carbon::now();
        $elapsed = (int) floor(max(0, $start->startOfDay()->diffInMonths($asOf->copy()->startOfDay())));

        $cost = (float) $this->cost_price;
        $salvage = min((float) ($this->salvage_value ?? 0), $cost);
        $value = $cost - ($monthly * min($elapsed, (int) $this->useful_life_months));

        return round(max($salvage, min($cost, $value)), 2);
    }
}
