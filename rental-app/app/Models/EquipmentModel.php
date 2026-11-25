<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentModel extends Model
{
    protected $fillable = [
        'business_id', 'name', 'manufacturer', 'model_number', 'category', 'description',
        'rental_price_hour', 'rental_price_day', 'rental_price_week', 'rental_price_month',
        'deposit_amount', 'default_cost_price', 'useful_life_months', 'salvage_value',
        'is_active',
    ];

    protected $casts = [
        'rental_price_hour' => 'decimal:2',
        'rental_price_day' => 'decimal:2',
        'rental_price_week' => 'decimal:2',
        'rental_price_month' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'default_cost_price' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** The physical units of this model. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "Caterpillar 320 GC", falling back to the name alone. */
    public function label(): string
    {
        $make = trim(implode(' ', array_filter([$this->manufacturer, $this->model_number])));

        return $make !== '' ? $make : $this->name;
    }
}
