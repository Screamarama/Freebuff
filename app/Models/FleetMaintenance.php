<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stint away for repair: why, who has it, when it should be back, what it
 * cost and what is being done. Open while the unit is out of service.
 */
class FleetMaintenance extends Model
{
    protected $fillable = [
        'business_id', 'product_id', 'reason', 'vendor', 'started_on',
        'expected_return_on', 'completed_on', 'cost', 'notes', 'recorded_by_id',
    ];

    protected $casts = [
        'started_on' => 'date',
        'expected_return_on' => 'date',
        'completed_on' => 'date',
        'cost' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function isOpen(): bool
    {
        return $this->completed_on === null;
    }

    /** Calendar days out, counting the day it left; open jobs count to today. */
    public function daysOut(): int
    {
        $end = $this->completed_on ?? now();

        return max(0, (int) $this->started_on->copy()->startOfDay()->diffInDays($end->copy()->startOfDay())) + 1;
    }

    /** Past its expected return date and still away. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->expected_return_on !== null
            && $this->expected_return_on->lt(now()->startOfDay());
    }

    public function label(): string
    {
        return $this->isOpen() ? 'In maintenance' : 'Returned';
    }
}
