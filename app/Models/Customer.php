<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'customer_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'id_number',
        'date_of_birth',
        'notes',
        'is_active',
        'account_type',
        'credit_limit',
        'tax_exemption_number',
        'tax_exempt_copy_on_file',
        'tax_exemption_expires_on',
        'tax_exemption_reason',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
        'tax_exempt_copy_on_file' => 'boolean',
        'tax_exemption_expires_on' => 'date',
    ];

    /**
     * Tax is left off this customer's agreements only with both a certificate
     * number and the copy on file, and only while that certificate is current;
     * a number alone proves nothing, and an expired one no longer exempts.
     */
    public function isTaxExempt(?CarbonInterface $on = null): bool
    {
        return $this->hasTaxExemptionCertificate() && ! $this->taxExemptionHasExpired($on);
    }

    /** A number and the copy on file, whether or not the certificate is current. */
    public function hasTaxExemptionCertificate(): bool
    {
        return filled($this->tax_exemption_number) && $this->tax_exempt_copy_on_file;
    }

    /**
     * Valid through its expiry date; the day after, it no longer applies. The
     * date that matters is the one given — a rental's own start date — because
     * a certificate good on the rental date exempts it even after it lapses.
     */
    public function taxExemptionHasExpired(?CarbonInterface $on = null): bool
    {
        return $this->tax_exemption_expires_on !== null
            && $this->tax_exemption_expires_on->lt($on ?? today());
    }

    /** True when this customer may run a balance on a charge account. */
    public function isChargeAccount(): bool
    {
        return $this->account_type === 'charge';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /** Single-line mailing address for contracts, skipping blank parts. */
    public function getFullAddressAttribute(): string
    {
        $locality = trim(
            ($this->city ? $this->city . ', ' : '')
            . ($this->state ?? '')
            . ($this->postal_code ? ' ' . $this->postal_code : '')
        );

        return implode(', ', array_filter([$this->address, $locality, $this->country]));
    }
}
