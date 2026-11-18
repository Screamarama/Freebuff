<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalContract extends Model
{
    protected $fillable = [
        'business_id',
        'reservation_id',
        'contract_number',
        'customer_id',
        'clerk_id',
        'start_date',
        'end_date',
        'actual_return_date',
        'status',
        'cancellation_reason',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'tax_exempt',
        'discount_amount',
        'total_amount',
        'deposit_paid',
        'deposit_applied',
        'late_fee',
        'notes',
        'terms_conditions',
        'signed_at',
        'charged_to_account_at',
        'charged_amount',
        'charged_tax_amount',
        'charged_sales_amount',
        'tax_exemption_number',
        'tax_exemption_expires_on',
        'tax_exemption_reason',
        'charged_tax_label',
        'currency',
        'due_on',
        'charge_reversed_at',
        'charge_reversed_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'actual_return_date' => 'date',
        'signed_at' => 'datetime',
        'charged_to_account_at' => 'datetime',
        'charged_amount' => 'decimal:2',
        'charged_tax_amount' => 'decimal:2',
        'charged_sales_amount' => 'decimal:2',
        'tax_exemption_expires_on' => 'date',
        'due_on' => 'date',
        'charge_reversed_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:3',
        'tax_exempt' => 'boolean',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'deposit_paid' => 'decimal:2',
        'deposit_applied' => 'decimal:2',
        'late_fee' => 'decimal:2',
    ];

    /**
     * Re-sum the line items, tax them and rebuild the total, so every path that
     * changes items, fees, discount or tax lands on the same arithmetic.
     *
     * The rate is captured the first time an agreement is priced and reused
     * afterwards: editing the tenant's rates must never reprice a document a
     * customer already holds, and an exempt customer pays no tax at all.
     */
    public function syncTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('subtotal'), 2);
        // Tax the price the customer actually pays: a discount reduces the
        // taxable amount before the rate is applied.
        $taxable = round(max(0, $subtotal - (float) $this->discount_amount), 2);
        // Judged on the rental's own date: a certificate valid then still
        // exempts it, and one that lapsed by the start date does not.
        $exempt = (bool) $this->customer?->isTaxExempt($this->start_date);
        $rate = $exempt ? 0.0 : (float) ($this->tax_rate ?? $this->business?->taxRate() ?? 0.0);
        $tax = round($taxable * $rate / 100, 2);
        $previousTax = round((float) $this->tax_amount, 2);

        $this->update([
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            // Why no tax was charged, kept for the report: once priced exempt,
            // the agreement stays an exempt sale even if the certificate lapses.
            'tax_exempt' => $tax > 0 ? false : ($exempt || (bool) $this->tax_exempt),
            'total_amount' => round(
                $subtotal + $tax - (float) $this->discount_amount + (float) $this->late_fee,
                2
            ),
        ]);

        $this->recordTaxChange($previousTax, $tax);
    }

    /**
     * Date each tax change made after billing as its own entry, so the tax
     * report files it in the month it happened: a later change adds another
     * entry instead of overwriting the earlier month's adjustment.
     */
    private function recordTaxChange(float $previous, float $tax): void
    {
        if ($this->charged_to_account_at === null
            || $this->charge_reversed_at !== null
            || abs($tax - $previous) <= 0.004) {
            return;
        }

        $this->taxAdjustments()->create([
            'amount' => round($tax - $previous, 2),
            'adjusted_at' => now(),
        ]);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function clerk(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clerk_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RentalItem::class, 'contract_id');
    }

    /** Tax changes dated after this agreement was billed. */
    public function taxAdjustments(): HasMany
    {
        return $this->hasMany(TaxAdjustment::class)->orderBy('adjusted_at')->orderBy('id');
    }

    /** Receipts recorded against this agreement (voids included). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'reference_id')
            ->where('reference_type', 'rental');
    }

    /**
     * The sale the agreement reports: its subtotal less discount. Billed
     * agreements use the figure frozen at billing, so a later edit cannot move
     * a month that has already been reported.
     */
    public function salesBasis(): float
    {
        $current = round(max(0, (float) $this->subtotal - (float) $this->discount_amount), 2);

        return $this->charged_to_account_at !== null
            ? round((float) ($this->charged_sales_amount ?? $current), 2)
            : $current;
    }

    /**
     * The exemption the sale relied on. A billed sale uses the certificate as
     * it stood then; an unbilled one uses the customer's current certificate.
     * The customer is only consulted when nothing was frozen, which is the case
     * for rows billed before certificates were recorded on the agreement.
     */
    public function taxExemptionEvidence(): array
    {
        $customer = $this->customer;

        return [
            'number' => $this->tax_exemption_number ?? $customer?->tax_exemption_number,
            'expires_on' => $this->tax_exemption_expires_on ?? $customer?->tax_exemption_expires_on,
            'reason' => $this->tax_exemption_reason ?? $customer?->tax_exemption_reason,
        ];
    }

    /**
     * Whether this agreement is still billed to the customer's charge account.
     * A reversed charge keeps its charged_to_account_at for the history but
     * stops counting toward what the customer owes.
     */
    public function isOnAccount(): bool
    {
        return $this->charged_to_account_at !== null && $this->charge_reversed_at === null;
    }

    /**
     * Equipment list for the agreement's {{rented_items}} placeholder.
     * Serialized units each get their own line with the serial number;
     * non-serialized lines for the same product and rate are grouped
     * into one line with the summed quantity. Five or fewer lines print
     * inline; more move to an Appendix A (returned alongside) with a
     * pointer left in their place.
     *
     * @return array{inline: string, appendix: ?string}
     */
    public function equipmentBlock(): array
    {
        $entries = [];
        foreach ($this->items as $item) {
            if (trim((string) $item->serial_number) !== '') {
                $entries[] = ['item' => $item, 'quantity' => (int) $item->quantity];
                continue;
            }

            $key = implode('|', [$item->product_id, $item->rate_type, $item->unit_price, $item->duration]);
            if (! isset($entries[$key])) {
                $entries[$key] = ['item' => $item, 'quantity' => 0];
            }
            $entries[$key]['quantity'] += (int) $item->quantity;
        }

        $lines = [];
        foreach (array_values($entries) as $entry) {
            $entry['item']->quantity = $entry['quantity'];
            $lines[] = (count($lines) + 1) . '. ' . $entry['item']->describe();
        }

        if (count($lines) <= 5) {
            return ['inline' => implode("\n", $lines), 'appendix' => null];
        }

        return [
            'inline' => 'See Appendix A for the full equipment list.',
            'appendix' => "APPENDIX A — EQUIPMENT LIST\n" . implode("\n", $lines),
        ];
    }

}
