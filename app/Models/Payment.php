<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /**
     * Every method the schema allows. The agreement form offers the first
     * three today; the rest are here for the counter and charge accounts.
     */
    public const METHODS = [
        'cash' => 'Cash',
        'card' => 'Card',
        'check' => 'Check',
        'bank_transfer' => 'Bank transfer',
        'other' => 'Other',
    ];

    /**
     * Receipt kinds: rent paid, deposit taken in, deposit paid back out, and
     * one customer-level receipt split across several agreements.
     */
    public const KINDS = [
        'payment' => 'Payment',
        'deposit_received' => 'Deposit held',
        'deposit_refund' => 'Deposit refunded',
        'deposit_top_up' => 'Deposit top-up',
        'account_payment' => 'Account payment',
    ];

    /**
     * The methods a counter takes today. The schema carries the rest of
     * METHODS for transfers and catch-alls later.
     */
    public const COUNTER_METHODS = ['cash', 'card', 'check'];

    protected $fillable = [
        'business_id',
        'reference_type',
        'reference_id',
        'kind',
        'amount',
        'currency',
        'payment_method',
        'transaction_id',
        'notes',
        'payment_date',
        'clerk_id',
        'receipt_number',
        'reversal_of_id',
        'account_payment_id',
    ];

    /** Every receipt records the currency it was taken in, wherever it is created. */
    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->currency ??= Business::query()->whereKey($payment->business_id)->value('currency');
        });
    }

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function clerk(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clerk_id');
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'reference_id');
    }

    /** The receipt this row reverses, when the row is a reversing entry. */
    public function reversed(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** For an allocation, the customer-level receipt that paid it. */
    public function accountPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'account_payment_id');
    }

    /** The agreement split lines this receipt paid, as posted. */
    public function allocations(): HasMany
    {
        return $this->hasMany(self::class, 'account_payment_id')->whereNull('reversal_of_id');
    }

    /**
     * Rows that still count toward money received. A void drops the original
     * and its reversing entry from the sum — the pair nets to nothing, so
     * both are excluded and the history keeps them.
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('voided_at')->whereNull('reversal_of_id');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->payment_method]
            ?? ucfirst(str_replace('_', ' ', (string) $this->payment_method));
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', (string) $this->kind));
    }

    public function isDeposit(): bool
    {
        return in_array($this->kind, ['deposit_received', 'deposit_refund', 'deposit_top_up'], true);
    }

    /** The customer-level receipt row, as opposed to an allocation of one. */
    public function isAccountPayment(): bool
    {
        return $this->kind === 'account_payment';
    }
}
