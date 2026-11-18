<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's charge account. Agreements billed to it stay owed until the
 * customer pays, so the balance is the sum of what those agreements still
 * carry; the limit is a ceiling on that balance at the moment of charging.
 */
class CustomerAccount
{
    /** Days a charge runs before it is due. Statements come later. */
    public const TERMS_DAYS = 30;

    /** How overdue money is grouped on the receivables list. */
    public const AGING_BUCKETS = [
        'current' => 'Not yet due',
        'days_1_30' => '1–30 days',
        'days_31_60' => '31–60 days',
        'days_61_plus' => '61+ days',
    ];

    public function __construct(private AgreementLedger $ledger)
    {
    }

    /**
     * What the account owes and is owed, in one pass over its open charges.
     * An agreement that ends in credit is money the customer is owed back, so
     * it is reported apart from the debt rather than silently dropped.
     *
     * @return array{owed: float, credited: float, net: float}
     */
    public function position(Customer $customer): array
    {
        $owed = 0;
        $credited = 0;

        RentalContract::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('charged_to_account_at')
            ->whereNull('charge_reversed_at')
            ->where('status', '!=', 'cancelled')
            ->get()
            ->each(function (RentalContract $contract) use (&$owed, &$credited) {
                $cents = (int) round($this->ledger->balance($contract) * 100);

                if ($cents >= 0) {
                    $owed += $cents;
                } else {
                    $credited -= $cents;
                }
            });

        return [
            'owed' => round($owed / 100, 2),
            'credited' => round($credited / 100, 2),
            'net' => round(($owed - $credited) / 100, 2),
        ];
    }

    /** Everything still owed on agreements billed to the account. */
    public function openBalance(Customer $customer): float
    {
        return $this->position($customer)['owed'];
    }

    /** The limit less what is owed net of credits; null when no limit is set. */
    public function availableCredit(Customer $customer): ?float
    {
        if ($customer->credit_limit === null) {
            return null;
        }

        return round((float) $customer->credit_limit - $this->position($customer)['net'], 2);
    }

    /**
     * Open charge balances by customer, grouped by how late they are. Each
     * charge ages from its own due date; a missing one falls back to the terms.
     * Rows come back most overdue first.
     *
     * @return array{rows: array<int, array{customer: Customer, open: float, credited: float, overdue: float, oldest_due_on: ?\Carbon\Carbon, buckets: array<string, float>}>, totals: array<string, float>, open: float, credited: float, overdue: float}
     */
    public function aging(int $businessId, ?int $customerId = null): array
    {
        $contracts = RentalContract::query()
            ->where('business_id', $businessId)
            ->whereNotNull('charged_to_account_at')
            ->whereNull('charge_reversed_at')
            ->where('status', '!=', 'cancelled')
            ->when($customerId, fn ($query, $id) => $query->where('customer_id', $id))
            ->with('customer')
            ->get();

        $today = now()->startOfDay();
        $rows = [];
        $totalCredited = 0;

        foreach ($contracts as $contract) {
            $signed = $this->cents($this->ledger->balance($contract));

            if ($signed === 0) {
                continue;
            }

            $customerId = $contract->customer_id;
            $rows[$customerId] ??= [
                'customer' => $contract->customer,
                'credited' => 0,
                'oldest_due_on' => null,
                'buckets' => array_fill_keys(array_keys(self::AGING_BUCKETS), 0),
            ];

            // Credit is shown beside the debt, never netted into it.
            if ($signed < 0) {
                $rows[$customerId]['credited'] += -$signed;
                $totalCredited += -$signed;

                continue;
            }

            $due = ($contract->due_on ?? $contract->charged_to_account_at?->copy()->addDays(self::TERMS_DAYS))
                ?->copy()->startOfDay();

            $daysLate = $due && $due->lt($today) ? (int) abs($due->diffInDays($today)) : 0;
            $bucket = match (true) {
                $daysLate === 0 => 'current',
                $daysLate <= 30 => 'days_1_30',
                $daysLate <= 60 => 'days_31_60',
                default => 'days_61_plus',
            };

            $rows[$customerId]['buckets'][$bucket] += $signed;

            if ($due && ($rows[$customerId]['oldest_due_on'] === null || $due->lt($rows[$customerId]['oldest_due_on']))) {
                $rows[$customerId]['oldest_due_on'] = $due;
            }
        }

        $totals = array_fill_keys(array_keys(self::AGING_BUCKETS), 0.0);

        foreach ($rows as $id => $row) {
            // round() keeps these floats even when the cents divide exactly.
            $row['buckets'] = array_map(fn (int $cents) => round($cents / 100, 2), $row['buckets']);
            $row['overdue'] = round(
                $row['buckets']['days_1_30'] + $row['buckets']['days_31_60'] + $row['buckets']['days_61_plus'],
                2
            );
            $row['open'] = round(array_sum($row['buckets']), 2);
            $row['credited'] = round($row['credited'] / 100, 2);

            foreach ($row['buckets'] as $key => $amount) {
                $totals[$key] += $amount;
            }

            $rows[$id] = $row;
        }

        usort($rows, fn (array $a, array $b) => ($a['oldest_due_on']?->timestamp ?? PHP_INT_MAX)
            <=> ($b['oldest_due_on']?->timestamp ?? PHP_INT_MAX));

        $totals = array_map(fn (float $amount) => round($amount, 2), $totals);

        return [
            'rows' => $rows,
            'totals' => $totals,
            'open' => round(array_sum($totals), 2),
            'credited' => round($totalCredited / 100, 2),
            'overdue' => round(array_sum($totals) - $totals['current'], 2),
        ];
    }

    /**
     * Every charge and receipt still on the account, oldest first, with a
     * running balance. Amounts are positive for a charge and negative for
     * money in, so the last balance is what the customer owes (or is owed).
     * Fees added after billing appear as their own line, dated when they were
     * assessed (the return), because the statement keeps the original charge.
     *
     * @return array{lines: array<int, array{date: mixed, description: string, amount: float, currency: ?string, balance: ?float, excluded: bool, note: ?string}>, closing: float, currency: ?string}
     */
    public function statement(Customer $customer): array
    {
        $contracts = RentalContract::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('charged_to_account_at')
            ->where('status', '!=', 'cancelled')
            // Reversed charges and voided receipts keep their line so a reprint
            // never silently loses something the customer was given.
            ->with([
                'payments' => fn ($query) => $query->whereNull('reversal_of_id')->with('accountPayment'),
                'items:id,contract_id,damage_fee',
            ])
            ->orderBy('charged_to_account_at')
            ->orderBy('id')
            ->get();

        $lines = [];
        $businessCurrency = $customer->business?->currency;

        foreach ($contracts as $contract) {
            $current = round($this->ledger->charges($contract), 2);
            $reversed = $contract->charge_reversed_at !== null;

            // The charge line is the figure billed, not a recomputed total:
            // a fee added afterwards must not rewrite a line already issued.
            $billed = round((float) ($contract->charged_amount ?? $current), 2);
            $tax = round(max(0, (float) ($contract->charged_tax_amount ?? 0)), 2);

            $chargedIn = $contract->currency ?? $businessCurrency;
            $reversal = $reversed ? 'Reversed — '.$contract->charge_reversed_reason : null;

            // The charge line carries the price; tax gets its own line beside
            // it, so the two still add up to what was billed.
            $lines[] = $this->line(
                $contract->charged_to_account_at,
                'Charge — '.$contract->contract_number,
                round($billed - $tax, 2),
                $chargedIn,
                $reversed,
                $reversal
            );

            if ($tax > 0) {
                // The label is the one billed, not whatever the tenant is now.
                $taxLabel = $contract->charged_tax_label
                    ?? $customer->business?->taxLabel()
                    ?? 'Sales tax';

                $lines[] = $this->line(
                    $contract->charged_to_account_at,
                    $taxLabel.' — '.$contract->contract_number,
                    $tax,
                    $chargedIn,
                    $reversed,
                    $reversal
                );
            }

            foreach ($contract->payments as $payment) {
                if ($payment->kind !== 'payment') {
                    continue;
                }

                // Receipts are stored positive and money paid out negative;
                // the statement shows money in as a credit against the debt.
                // An allocation carries no number of its own: the account
                // receipt it came from does.
                $amount = round((float) $payment->amount, 2);
                $receipt = $payment->receipt_number ?? $payment->accountPayment?->receipt_number;

                $lines[] = $this->line(
                    $payment->payment_date,
                    ($amount < 0 ? 'Refund — ' : 'Payment — ').$receipt,
                    -$amount,
                    $payment->currency ?? $businessCurrency,
                    $payment->isVoided(),
                    $payment->isVoided() ? 'Voided — '.$payment->void_reason : null
                );
            }


            // A reversed charge keeps its struck-through lines, including any
            // adjustment made before the reversal, so a reprint still shows the
            // figure that was credited and matches the sales-tax report.
            $adjustment = round($current - $billed, 2);

            if ($adjustment !== 0.0) {
                $lines[] = $this->line(
                    $contract->actual_return_date ?? $contract->charged_to_account_at,
                    $this->changeLabel($contract, $adjustment).' — '.$contract->contract_number,
                    $adjustment,
                    $chargedIn,
                    $reversed,
                    $reversal
                );
            }

            // No deposit belongs to a dead charge.
            if ($reversed) {
                continue;
            }

            $applied = round((float) $contract->deposit_applied, 2);

            if ($applied > 0) {
                $lines[] = $this->line(
                    $contract->actual_return_date ?? $contract->charged_to_account_at,
                    'Deposit applied — '.$contract->contract_number,
                    -$applied,
                    $chargedIn
                );
            }
        }

        // Day granularity, so a date-only return never sorts before the charge
        // posted earlier that same day. Ties keep the order the lines were
        // built in: charge, receipts, then any adjustment to the charge.
        usort($lines, fn (array $a, array $b) => $a['date']->toDateString() <=> $b['date']->toDateString());

        $closing = 0;

        foreach ($lines as $index => $line) {
            if ($line['excluded']) {
                // Struck-through lines do not move the running balance.
                $lines[$index]['balance'] = null;

                continue;
            }

            $closing += $line['amount'];
            $lines[$index]['balance'] = round($closing, 2);
        }

        // Totals read in the currency of the newest live line, so a reprint of
        // a USD history stays in USD even after the business switches setting.
        $currency = $customer->business?->currency;

        foreach (array_reverse($lines) as $line) {
            if (! $line['excluded']) {
                $currency = $line['currency'] ?? $currency;

                break;
            }
        }

        return ['lines' => $lines, 'closing' => round($closing, 2), 'currency' => $currency];
    }

    /**
     * What moved the total after billing, named: a tax change, a late fee, item
     * damage, or a price edit, so the statement says why the line exists.
     */
    private function changeLabel(RentalContract $contract, float $adjustment): string
    {
        $tax = round((float) $contract->tax_amount - (float) ($contract->charged_tax_amount ?? 0), 2);
        $lateFee = round((float) $contract->late_fee, 2);
        $damage = round((float) $contract->items->sum('damage_fee'), 2);

        $parts = array_keys(array_filter([
            'tax' => $tax != 0,
            'late fee' => $lateFee > 0,
            'damage' => $damage > 0,
        ]));

        // Anything left unexplained (a price edit) still gets a truthful label.
        if (abs($adjustment - $tax - $lateFee - $damage) > 0.004) {
            $parts[] = $adjustment > 0 ? 'price change' : 'charges reduced';
        }

        if ($parts === []) {
            return $adjustment > 0 ? 'Additional charge' : 'Credit';
        }

        return ucfirst(implode(' and ', $parts));
    }

    /** One statement line; an excluded line prints struck through and never counts. */
    private function line(
        mixed $date,
        string $description,
        float $amount,
        ?string $currency,
        bool $excluded = false,
        ?string $note = null
    ): array {
        return [
            'date' => $date,
            'description' => $description,
            'amount' => round($amount, 2),
            'currency' => $currency,
            'balance' => null,
            'excluded' => $excluded,
            'note' => $note,
        ];
    }

    /**
     * Bill the agreement's outstanding balance to the customer's account. No
     * money moves — the balance simply becomes receivable, with a due date.
     */
    public function charge(RentalContract $contract, User $actor): RentalContract
    {
        return DB::transaction(function () use ($contract, $actor) {
            $contract = $this->lockedContract($contract);

            if ($contract->status === 'cancelled') {
                $this->refuse('Cancelled agreements cannot be charged.');
            }

            if ($contract->isOnAccount()) {
                $this->refuse('This agreement is already on the account.');
            }

            $customer = $contract->customer;

            if (! $customer?->isChargeAccount()) {
                $this->refuse('This customer is a cash account. Set them to “Charge account” before billing them.');
            }

            $due = $this->ledger->balance($contract);

            if ($due <= 0) {
                $this->refuse('There is nothing left to charge on this agreement.');
            }

            $available = $this->availableCredit($customer);

            if ($available !== null && $due > $available + 0.001) {
                $this->refuse('That is $'.number_format($due, 2).', but only $'
                    .number_format(max(0, $available), 2).' of the $'
                    .number_format((float) $customer->credit_limit, 2).' limit is available.');
            }

            $contract->update([
                'charged_to_account_at' => now(),
                // The agreement's charges at billing, not the balance: earlier
                // receipts are statement lines of their own, and later fees are
                // measured against this figure.
                'charged_amount' => $this->ledger->charges($contract),
                // The tax inside that figure, so a reprint can show it apart.
                'charged_tax_amount' => round((float) $contract->tax_amount, 2),
                // The sale itself, frozen so the sales-tax report keeps the
                // figure this month reported even if the items are edited later.
                'charged_sales_amount' => round(max(0, (float) $contract->subtotal - (float) $contract->discount_amount), 2),
                // The exemption certificate this sale relied on, so a later edit
                // to the customer cannot change the support behind it.
                'tax_exemption_number' => $customer->tax_exemption_number,
                'tax_exemption_expires_on' => $customer->tax_exemption_expires_on,
                'tax_exemption_reason' => $customer->tax_exemption_reason,
                'charged_tax_label' => $customer->business?->taxLabel(),
                // The currency this charge is billed in, kept for every reprint.
                'currency' => $customer->business?->currency,
                'due_on' => now()->addDays(self::TERMS_DAYS)->toDateString(),
                // A fresh charge outranks an earlier reversal; the old reason is
                // replaced rather than left behind looking current.
                'charge_reversed_at' => null,
                'charge_reversed_reason' => null,
            ]);

            AuditLog::record('account.charged', [
                'name' => $contract->contract_number,
                'customer' => $customer->full_name,
                'amount' => number_format($due, 2),
                'due_on' => $contract->due_on?->toDateString(),
            ], $actor, $contract->business_id, $contract);

            return $contract;
        });
    }

    /**
     * Take a charge back off the account. The original charge stays in the
     * history; the reversal says when and why, and the balance stops counting.
     */
    public function reverseCharge(RentalContract $contract, User $actor, string $reason): RentalContract
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->refuse('A short reason is required to reverse a charge.', 'reason');
        }

        return DB::transaction(function () use ($contract, $actor, $reason) {
            $contract = $this->lockedContract($contract);

            if ($contract->charge_reversed_at !== null) {
                $this->refuse('This charge was already reversed.');
            }

            if (! $contract->isOnAccount()) {
                $this->refuse('This agreement is not on the account.');
            }

            // Money already applied would be left with nothing owed, so the
            // receipt has to be voided (or refunded) before the charge comes off.
            $paid = $this->ledger->paid($contract);

            if ($paid > 0) {
                $this->refuse('$'.number_format($paid, 2).' has already been paid against this charge. '
                    .'Void the payment first if it was wrong.');
            }

            $contract->update([
                'charge_reversed_at' => now(),
                'charge_reversed_reason' => $reason,
            ]);

            AuditLog::record('account.charge_reversed', [
                'name' => $contract->contract_number,
                'customer' => $contract->customer?->full_name,
                'amount' => number_format($this->ledger->balance($contract), 2),
                'reason' => $reason,
            ], $actor, $contract->business_id, $contract);

            return $contract;
        });
    }

    /**
     * One receipt against the account, split across its open charges oldest
     * first. The receipt row belongs to the customer; each agreement it pays
     * gets an allocation row, so that agreement's own balance falls.
     */
    public function recordPayment(Customer $customer, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($customer, $data, $actor) {
            $customer = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            $amount = $this->cents($data['amount'] ?? 0);

            if ($amount <= 0) {
                $this->refuse('Enter an amount greater than zero.', 'amount');
            }

            // One balance read per charge, reused for both the total and the split.
            $open = $this->openCharges($customer);
            $dueByCharge = [];
            $due = 0;

            foreach ($open as $contract) {
                $dueByCharge[$contract->id] = max(0, $this->cents($this->ledger->balance($contract)));
                $due += $dueByCharge[$contract->id];
            }

            if ($due <= 0) {
                $this->refuse('This account has nothing outstanding to pay.');
            }

            if ($amount > $due) {
                $this->refuse('That is more than the $'.number_format($due / 100, 2).' outstanding on this account.', 'amount');
            }

            $receipt = Payment::create([
                'business_id' => $customer->business_id,
                'reference_type' => 'customer',
                'reference_id' => $customer->id,
                'kind' => 'account_payment',
                'amount' => $amount / 100,
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_date' => now(),
                'clerk_id' => $actor->id,
                'receipt_number' => $this->ledger->nextReceiptNumber($customer->business_id),
            ]);

            $remaining = $amount;
            $paidCharges = 0;

            foreach ($open as $contract) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($dueByCharge[$contract->id], $remaining);

                if ($take <= 0) {
                    continue;
                }

                Payment::create([
                    'business_id' => $customer->business_id,
                    'reference_type' => 'rental',
                    'reference_id' => $contract->id,
                    'kind' => 'payment',
                    'amount' => $take / 100,
                    'payment_method' => $data['payment_method'],
                    'transaction_id' => $data['transaction_id'] ?? null,
                    'notes' => 'Allocated from account receipt '.$receipt->receipt_number,
                    'payment_date' => now(),
                    'clerk_id' => $actor->id,
                    'account_payment_id' => $receipt->id,
                ]);

                $remaining -= $take;
                $paidCharges++;
            }

            AuditLog::record('account.payment_recorded', [
                'name' => $customer->full_name,
                'receipt_number' => $receipt->receipt_number,
                'amount' => number_format($amount / 100, 2),
                'payment_method' => $receipt->methodLabel(),
                'charges' => $paidCharges,
            ], $actor, $customer->business_id);

            return $receipt;
        });
    }

    /**
     * Void a whole account receipt: every allocation is reversed through the
     * ledger's usual void, then the receipt itself is stamped. Voiding a single
     * allocation on its own would leave the receipt claiming money it no longer
     * has, so the pair moves together.
     */
    public function voidPayment(Payment $accountPayment, User $actor, string $reason): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->refuse('A short reason is required to void a receipt.', 'reason');
        }

        return DB::transaction(function () use ($accountPayment, $actor, $reason) {
            $receipt = Payment::query()->whereKey($accountPayment->getKey())->lockForUpdate()->firstOrFail();

            if (! $receipt->isAccountPayment()) {
                $this->refuse('That receipt is not an account payment.');
            }

            if ($receipt->isVoided()) {
                $this->refuse('Account receipt '.$receipt->receipt_number.' is already voided.');
            }

            $allocations = Payment::query()
                ->where('account_payment_id', $receipt->id)
                ->effective()
                ->get();

            foreach ($allocations as $allocation) {
                $this->ledger->void($allocation, $actor, $reason);
            }

            $receipt->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            AuditLog::record('account.payment_voided', [
                'name' => Customer::find($receipt->reference_id)?->full_name,
                'receipt_number' => $receipt->receipt_number,
                'amount' => number_format((float) $receipt->amount, 2),
                'charges' => $allocations->count(),
                'reason' => $reason,
            ], $actor, $receipt->business_id);

            return $allocations->count();
        });
    }

    /** This customer's open charges, oldest first, locked for the split. */
    private function openCharges(Customer $customer)
    {
        return RentalContract::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('charged_to_account_at')
            ->whereNull('charge_reversed_at')
            ->where('status', '!=', 'cancelled')
            ->orderBy('charged_to_account_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function lockedContract(RentalContract $contract): RentalContract
    {
        return RentalContract::query()
            ->whereKey($contract->getKey())
            ->lockForUpdate()
            ->firstOrFail()
            ->load('customer');
    }

    /** Refuse a charge-account change with a message the page can show. */
    private function refuse(string $message, string $key = 'account'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
