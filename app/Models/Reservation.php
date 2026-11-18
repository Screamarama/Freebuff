<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    /**
     * Hours before starts_at when the item flips from "available"
     * to "locked for prep". Creating a reservation intentionally
     * does NOT touch products.stock_quantity / available_quantity.
     */
    public const HOLD_BEFORE_HOURS = 24;

    protected $fillable = [
        'business_id',
        'product_id',
        'customer_id',
        'created_by',
        'quantity',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'total_amount',
        'billing_unit',
        'price_breakdown',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rentalContract(): HasOne
    {
        return $this->hasOne(RentalContract::class);
    }

    /**
     * Reservations that still block stock for overlap checks. A reservation
     * whose window has passed without being converted or cancelled is stale
     * and stops holding units.
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ['upcoming', 'active'])
            ->where('ends_at', '>=', now());
    }

    /**
     * Hold state for display:
     * - cancelled/completed -> 'closed'
     * - now in [starts_at, ends_at] -> 'in_use' (out on rent)
     * - now within 24h before starts_at -> 'locked' (prep window, not rentable)
     * - otherwise -> 'available' (booked but still rentable until the window)
     */
    public function holdState(?Carbon $now = null): string
    {
        if (in_array($this->status, ['cancelled', 'completed'], true)) {
            return 'closed';
        }

        $now ??= now();
        $startsAt = $this->starts_at instanceof Carbon ? $this->starts_at : Carbon::parse($this->starts_at);
        $endsAt = $this->ends_at instanceof Carbon ? $this->ends_at : Carbon::parse($this->ends_at);
        $lockFrom = $startsAt->copy()->subHours(static::HOLD_BEFORE_HOURS);

        if ($now->gte($startsAt) && $now->lte($endsAt)) {
            return 'in_use';
        }

        if ($now->gte($lockFrom) && $now->lt($startsAt)) {
            return 'locked';
        }

        if ($now->gt($endsAt)) {
            return 'closed';
        }

        return 'available';
    }

    public function holdLabel(?Carbon $now = null): string
    {
        return match ($this->holdState($now)) {
            'in_use' => 'Out on rent',
            'locked' => 'Locked for prep (within 24h)',
            'closed' => ucfirst((string) $this->status),
            default => 'Available until 24h before start',
        };
    }

    public function holdColor(?Carbon $now = null): string
    {
        return match ($this->holdState($now)) {
            'in_use' => 'bg-red-100 text-red-800',
            'locked' => 'bg-amber-100 text-amber-800',
            'closed' => 'bg-gray-100 text-gray-600',
            default => 'bg-green-100 text-green-800',
        };
    }

    /** When the item stops being rentable for this reservation. */
    public function lockFrom(): Carbon
    {
        $startsAt = $this->starts_at instanceof Carbon ? $this->starts_at : Carbon::parse($this->starts_at);

        return $startsAt->copy()->subHours(static::HOLD_BEFORE_HOURS);
    }

    /** Whole hours for the reservation period, rounded up, minimum 1. */
    public function durationHours(): int
    {
        $startsAt = $this->starts_at instanceof Carbon ? $this->starts_at : Carbon::parse($this->starts_at);
        $endsAt = $this->ends_at instanceof Carbon ? $this->ends_at : Carbon::parse($this->ends_at);

        return max(1, (int) ceil($startsAt->diffInMinutes($endsAt) / 60));
    }

    /**
     * Closest-block rental fee: greedy months (30d) / weeks (7d) / days / hours
     * breakdown, falling back when a rate is not set. Multiplied by quantity.
     *
     * @return array{total: float, unit: string, breakdown: string, parts: array}
     */
    public static function calculateFee(Product $product, $startsAt, $endsAt, int $quantity = 1): array
    {
        $startsAt = $startsAt instanceof Carbon ? $startsAt : Carbon::parse($startsAt);
        $endsAt = $endsAt instanceof Carbon ? $endsAt : Carbon::parse($endsAt);
        $quantity = max(1, $quantity);
        $hours = max(1, (int) ceil($startsAt->diffInMinutes($endsAt) / 60));

        $rates = [
            'month' => $product->rental_price_month !== null ? (float) $product->rental_price_month : null,
            'week' => $product->rental_price_week !== null ? (float) $product->rental_price_week : null,
            'day' => $product->rental_price_day !== null ? (float) $product->rental_price_day : null,
            'hour' => $product->rental_price_hour !== null ? (float) $product->rental_price_hour : null,
        ];

        if (array_filter($rates, fn ($r) => $r !== null) === []) {
            return ['total' => 0.0, 'unit' => 'none', 'breakdown' => 'No rental rate set for this item', 'parts' => []];
        }

        // Greedy split: months of 720h, weeks of 168h, days of 24h, remainder hours.
        $months = intdiv($hours, 720);
        $rem = $hours % 720;
        $weeks = intdiv($rem, 168);
        $rem %= 168;
        $days = intdiv($rem, 24);
        $hoursLeft = $rem % 24;

        // Break larger units without a rate down into smaller units.
        if ($rates['month'] === null && $months > 0) {
            $days += $months * 30;
            $months = 0;
        }
        if ($rates['week'] === null && ($weeks > 0 || $months > 0)) {
            $days += $weeks * 7;
            $weeks = 0;
            if ($months > 0) {
                $days += $months * 30;
                $months = 0;
            }
        }
        if ($rates['day'] === null && ($days > 0 || $weeks > 0 || $months > 0)) {
            $hoursLeft += $days * 24 + $weeks * 168 + $months * 720;
            $days = $weeks = $months = 0;
        }

        // Roll smaller units without a rate up into the next available larger unit.
        if ($rates['hour'] === null && $hoursLeft > 0) {
            if ($rates['day'] !== null) {
                $days += (int) ceil($hoursLeft / 24);
                $hoursLeft = 0;
            } elseif ($rates['week'] !== null) {
                $weeks += (int) ceil($hoursLeft / 168);
                $hoursLeft = 0;
            } elseif ($rates['month'] !== null) {
                $months += (int) ceil($hoursLeft / 720);
                $hoursLeft = 0;
            }
        }
        if ($rates['day'] === null && $days > 0 && $hoursLeft === 0) {
            if ($rates['week'] !== null) {
                $weeks += (int) ceil($days / 7);
                $days = 0;
            } elseif ($rates['month'] !== null) {
                $months += (int) ceil($days / 30);
                $days = 0;
            } elseif ($rates['hour'] !== null) {
                $hoursLeft += $days * 24;
                $days = 0;
            }
        }

        $parts = [];
        $perUnit = 0.0;
        foreach (['month' => $months, 'week' => $weeks, 'day' => $days, 'hour' => $hoursLeft] as $unit => $count) {
            if ($count > 0 && $rates[$unit] !== null) {
                $parts[] = ['unit' => $unit, 'count' => $count, 'rate' => $rates[$unit]];
                $perUnit += $count * $rates[$unit];
            }
        }

        // Safety net: anything left unpriced rolls up to the cheapest available unit.
        $pricedHours = $months * 720 + $weeks * 168 + $days * 24 + $hoursLeft;
        if ($parts === [] || $pricedHours < $hours) {
            $missing = $hours - $pricedHours;
            foreach (['hour', 'day', 'week', 'month'] as $unit) {
                if ($rates[$unit] !== null) {
                    $size = ['hour' => 1, 'day' => 24, 'week' => 168, 'month' => 720][$unit];
                    $count = (int) ceil($missing / $size);
                    $parts[] = ['unit' => $unit, 'count' => $count, 'rate' => $rates[$unit]];
                    $perUnit += $count * $rates[$unit];
                    break;
                }
            }
        }

        $labels = [];
        foreach ($parts as $part) {
            $labels[] = $part['count'] . ' ' . $part['unit'] . ($part['count'] > 1 ? 's' : '')
                . ' @ $' . rtrim(rtrim(number_format($part['rate'], 2), '0'), '.');
        }
        $breakdown = implode(' + ', $labels);
        if ($quantity > 1) {
            $breakdown .= ' × ' . $quantity . ' unit' . ($quantity > 1 ? 's' : '');
        }

        $unit = count($parts) === 1 ? $parts[0]['unit'] : 'mixed';

        return ['total' => round($perUnit * $quantity, 2), 'unit' => $unit, 'breakdown' => $breakdown, 'parts' => $parts];
    }

    /** Recompute total_amount / billing_unit / price_breakdown from the product + dates. */
    public function recalculatePricing(): void
    {
        $product = $this->relationLoaded('product') ? $this->product : null;
        if (! $product && $this->product_id) {
            $product = Product::find($this->product_id);
        }
        if (! $product || ! $this->starts_at || ! $this->ends_at) {
            return;
        }

        $fee = static::calculateFee($product, $this->starts_at, $this->ends_at, (int) ($this->quantity ?? 1));
        $this->total_amount = $fee['total'];
        $this->billing_unit = $fee['unit'];
        $this->price_breakdown = $fee['breakdown'];
    }

    protected static function booted(): void
    {
        static::saving(function (Reservation $reservation) {
            // Keep the stored fee in sync even if a caller forgets to compute it.
            if ($reservation->isDirty(['product_id', 'quantity', 'starts_at', 'ends_at']) || $reservation->total_amount === null) {
                $reservation->recalculatePricing();
            }
        });
    }
}
