<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The money side of a rental agreement. Charges are the agreement's document
 * total (rental and sale lines, tax, discount and late fee — deposits and
 * damage are tracked separately); payments are the receipts still in effect.
 *
 * Amounts are added up in cents so repeated part-payments cannot drift.
 */
class AgreementLedger
{
    /**
     * What the agreement bills: its document total plus any damage charged to
     * the rented items at return. The late fee is already inside the total.
     */
    public function charges(RentalContract $contract): float
    {
        return $this->fromCents(
            $this->toCents($contract->total_amount) + $this->toCents($this->damageCharges($contract))
        );
    }

    /** Damage charged per item — kept out of the agreement's own total. */
    public function damageCharges(RentalContract $contract): float
    {
        return $this->fromCents($this->toCents($contract->items()->sum('damage_fee')));
    }

    /**
     * Money received that still counts: rent and any deposit applied to fees.
     * A held deposit is not rent paid, so it stays out of this figure; voided
     * receipts and their reversals do not count either.
     */
    public function paid(RentalContract $contract): float
    {
        return $this->fromCents(
            $this->toCents($this->rentPaid($contract)) + $this->toCents($contract->deposit_applied)
        );
    }

    /** Rent receipts still in effect — paid money that is not a deposit. */
    public function rentPaid(RentalContract $contract): float
    {
        return $this->fromCents($this->toCents(
            $contract->payments()->effective()->where('kind', 'payment')->sum('amount')
        ));
    }

    public function balance(RentalContract $contract): float
    {
        return $this->fromCents($this->toCents($this->charges($contract)) - $this->toCents($this->paid($contract)));
    }

    /**
     * The deposit at a glance, in one query: taken in, kept against fees, paid
     * back, and still held.
     *
     * @return array{received: float, applied: float, refunded: float, remaining: float}
     */
    public function depositSummary(RentalContract $contract): array
    {
        $totals = $contract->payments()
            ->effective()
            ->whereIn('kind', ['deposit_received', 'deposit_top_up', 'deposit_refund'])
            ->selectRaw("SUM(CASE WHEN kind IN ('deposit_received', 'deposit_top_up') THEN amount ELSE 0 END) AS received")
            ->selectRaw("SUM(CASE WHEN kind = 'deposit_refund' THEN amount ELSE 0 END) AS refunded")
            ->first();

        $received = $this->toCents($totals->received ?? 0);
        $refunded = abs($this->toCents($totals->refunded ?? 0));
        $applied = $this->toCents($contract->deposit_applied);

        return [
            'received' => $this->fromCents($received),
            'applied' => $this->fromCents($applied),
            'refunded' => $this->fromCents($refunded),
            'remaining' => $this->fromCents($received - $applied - $refunded),
        ];
    }

    /** Every receipt on the agreement, newest first, voids included. */
    public function payments(RentalContract $contract): Collection
    {
        return $contract->payments()
            ->with(['clerk', 'accountPayment'])
            ->latest('payment_date')
            ->latest('id')
            ->get();
    }

    public function record(RentalContract $contract, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            // Lock the agreement so two clerks cannot both accept the last payment.
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'amount' => 'Cancelled agreements cannot take payments.',
                ]);
            }

            $amount = $this->toCents($data['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter an amount greater than zero.',
                ]);
            }

            $balance = $this->toCents($this->balance($contract));

            if ($amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => $balance <= 0
                        ? 'This agreement is already paid in full.'
                        : 'That is more than the $'.$this->money($balance).' still due.',
                ]);
            }

            $payment = Payment::create([
                'business_id' => $contract->business_id,
                'reference_type' => 'rental',
                'reference_id' => $contract->id,
                'amount' => $this->fromCents($amount),
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_date' => now(),
                'clerk_id' => $actor->id,
                'receipt_number' => $this->nextReceiptNumber($contract->business_id),
            ]);

            AuditLog::record('payment.recorded', [
                'name' => $contract->contract_number,
                'receipt_number' => $payment->receipt_number,
                'amount' => $this->money($amount),
                'payment_method' => $payment->methodLabel(),
            ], $actor, $contract->business_id, $contract);

            return $payment;
        });
    }

    /**
     * Take a deposit at pickup. It is a liability, so it does not reduce the
     * rent balance — it is only applied to fees when the items come back.
     */
    public function recordDeposit(RentalContract $contract, array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($contract->status, ['returned', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'deposit_amount' => 'Closed agreements cannot take a deposit.',
                ]);
            }

            $amount = $this->toCents($data['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'deposit_amount' => 'Enter a deposit greater than zero.',
                ]);
            }

            $payment = Payment::create([
                'business_id' => $contract->business_id,
                'reference_type' => 'rental',
                'reference_id' => $contract->id,
                'kind' => 'deposit_received',
                'amount' => $this->fromCents($amount),
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'notes' => $data['notes'] ?? 'Deposit held at pickup',
                'payment_date' => now(),
                'clerk_id' => $actor->id,
                'receipt_number' => $this->nextReceiptNumber($contract->business_id),
            ]);

            // `deposit_paid` mirrors the hold still on the books, so it has to
            // fall when the deposit is applied or refunded, not only rise.
            $contract->update([
                'deposit_paid' => $this->depositSummary($contract)['remaining'],
            ]);

            AuditLog::record('deposit.recorded', [
                'name' => $contract->contract_number,
                'receipt_number' => $payment->receipt_number,
                'amount' => $this->money($amount),
                'payment_method' => $payment->methodLabel(),
            ], $actor, $contract->business_id, $contract);

            return $payment;
        });
    }

    /**
     * Close the agreement at return: store the late and damage fees, keep as
     * much of the deposit as the fees need, and refund the rest.
     *
     * @param  array{returned_on: string, late_fee?: mixed, damage?: array<int, mixed>, refund_method: string, refund_reference?: ?string}  $data
     * @return array{applied: float, refunded: float, due: float}
     */
    public function settleDeposit(RentalContract $contract, array $data, User $actor): array
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'late_fee' => 'Cancelled agreements cannot be settled.',
                ]);
            }

            $held = max(0, $this->toCents($this->depositSummary($contract)['remaining']));

            // Returned without settling leaves the deposit to refund here;
            // returning again after it is spent has nothing left to settle.
            if ($contract->status === 'returned' && $held === 0) {
                throw ValidationException::withMessages([
                    'late_fee' => 'This agreement is already settled.',
                ]);
            }

            $lateFee = $this->toCents($data['late_fee'] ?? 0);
            $damage = $data['damage'] ?? [];

            $contract->load('items');

            foreach ($contract->items as $item) {
                if (array_key_exists($item->id, $damage)) {
                    $item->update([
                        'damage_fee' => $this->fromCents(max(0, $this->toCents($damage[$item->id]))),
                    ]);
                }
            }

            $contract->update([
                'late_fee' => $this->fromCents(max(0, $lateFee)),
                'actual_return_date' => $data['returned_on'],
                'status' => 'returned',
            ]);

            // The document total follows the late fee, as it does on edit.
            $contract->syncTotals();

            $due = max(0, $this->toCents($this->balance($contract)));
            $applied = min($held, $due);
            $refund = $held - $applied;

            // Keep the deposit against the fees it covered, on top of anything
            // kept by an earlier settlement.
            $contract->update([
                'deposit_applied' => $this->fromCents($this->toCents($contract->deposit_applied) + $applied),
            ]);

            if ($refund > 0) {
                Payment::create([
                    'business_id' => $contract->business_id,
                    'reference_type' => 'rental',
                    'reference_id' => $contract->id,
                    'kind' => 'deposit_refund',
                    'amount' => $this->fromCents(-$refund),
                    'payment_method' => $data['refund_method'],
                    'transaction_id' => $data['refund_reference'] ?? null,
                    'notes' => 'Deposit refunded at return',
                    'payment_date' => now(),
                    'clerk_id' => $actor->id,
                    'receipt_number' => $this->nextReceiptNumber($contract->business_id),
                ]);
            }

            // The hold is now spent and paid back; nothing is left in it.
            $contract->update([
                'deposit_paid' => $this->depositSummary($contract)['remaining'],
            ]);

            AuditLog::record('deposit.settled', [
                'name' => $contract->contract_number,
                'held' => $this->money($held),
                'applied' => $this->money($applied),
                'refunded' => $this->money($refund),
                'late_fee' => $this->money($lateFee),
            ], $actor, $contract->business_id, $contract);

            return [
                'applied' => $this->fromCents($applied),
                'refunded' => $this->fromCents($refund),
                'due' => $this->balance($contract->fresh()),
            ];
        });
    }

    /**
     * Cancel an agreement and unwind its money: rent received comes back, any
     * deposit still held comes back, and what the agreement billed is waived.
     * Cancellation is not a return, so no fees survive it. Running this again
     * on an already-cancelled agreement refunds money that is somehow still
     * held, so an old cancellation cannot leave cash with no way out.
     *
     * @return float the total refunded (rent plus deposit)
     */
    public function cancelAgreement(RentalContract $contract, ?string $refundMethod, string $reason, User $actor): float
    {
        return DB::transaction(function () use ($contract, $refundMethod, $reason, $actor) {
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status === 'returned') {
                throw ValidationException::withMessages([
                    'refund_method' => 'Returned agreements settle their deposit with the return.',
                ]);
            }

            if ($contract->charged_to_account_at !== null && $contract->charge_reversed_at === null) {
                throw ValidationException::withMessages([
                    'refund_method' => 'Reverse the account charge before cancelling this agreement.',
                ]);
            }

            $held = max(0, $this->toCents($this->depositSummary($contract)['remaining']));
            $rent = max(0, $this->toCents($this->rentPaid($contract)));

            if ($contract->status === 'cancelled' && $held === 0 && $rent === 0) {
                throw ValidationException::withMessages([
                    'refund_method' => 'This agreement is already cancelled and holds no money.',
                ]);
            }

            if (($held + $rent) > 0 && $refundMethod === null) {
                throw ValidationException::withMessages([
                    'refund_method' => 'Choose how the money held is refunded.',
                ]);
            }

            $receipts = [];

            if ($rent > 0) {
                $receipts[] = $this->refundReceipt($contract, $rent, 'payment', 'Rent refunded at cancellation', $refundMethod, $actor);
            }

            if ($held > 0) {
                $receipts[] = $this->refundReceipt($contract, $held, 'deposit_refund', 'Deposit refunded at cancellation', $refundMethod, $actor);
            }

            // What was billed is waived, so a cancelled agreement owes nothing.
            $waived = $this->toCents($this->charges($contract));

            $contract->items()->where('damage_fee', '!=', 0)->update(['damage_fee' => 0]);

            $contract->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'late_fee' => 0,
                'total_amount' => 0,
                'deposit_paid' => $this->depositSummary($contract)['remaining'],
            ]);

            AuditLog::record('agreement.cancelled', array_filter([
                'name' => $contract->contract_number,
                'reason' => $reason,
                'waived' => $this->money($waived),
                'rent_refunded' => $this->money($rent),
                'deposit_refunded' => $this->money($held),
                'receipts' => $receipts === [] ? null : implode(', ', $receipts),
            ]), $actor, $contract->business_id, $contract);

            return $this->fromCents($rent + $held);
        });
    }

    /** Post a negative receipt for money handed back at cancellation. */
    private function refundReceipt(
        RentalContract $contract,
        int $cents,
        string $kind,
        string $notes,
        string $method,
        User $actor,
    ): string {
        return Payment::create([
            'business_id' => $contract->business_id,
            'reference_type' => 'rental',
            'reference_id' => $contract->id,
            'kind' => $kind,
            'amount' => $this->fromCents(-$cents),
            'payment_method' => $method,
            'notes' => $notes,
            'payment_date' => now(),
            'clerk_id' => $actor->id,
            'receipt_number' => $this->nextReceiptNumber($contract->business_id),
        ])->receipt_number;
    }

    /**
     * Take a deposit top-up after the return, so a reduction in what was kept
     * can be put back. The money arrives as a new receipt and is kept against
     * the fees, so `received = applied + refunded + remaining` still holds.
     * Only deposit that was refunded and not yet kept can come back.
     *
     * @param  array{amount: mixed, payment_method: string, transaction_id?: ?string, reason: string}  $data
     */
    public function topUpDeposit(RentalContract $contract, array $data, User $actor): Payment
    {
        $reason = $this->requiredReason($data['reason'] ?? null, 'A short reason is required for a deposit top-up.');

        return DB::transaction(function () use ($contract, $data, $actor, $reason) {
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($contract->status !== 'returned') {
                throw ValidationException::withMessages([
                    'top_up' => 'Only a settled return can take a deposit top-up.',
                ]);
            }

            $amount = $this->toCents($data['amount'] ?? 0);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Enter a top-up greater than zero.',
                ]);
            }

            $summary = $this->depositSummary($contract);
            $applied = $this->toCents($summary['applied']);
            $refunded = $this->toCents($summary['refunded']);
            $available = max(0, $refunded - $applied);

            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => $available === 0
                        ? 'Nothing was refunded on this return that could be kept again.'
                        : 'At most $'.$this->money($available).' can be topped up — the deposit refunded and not yet kept.',
                ]);
            }

            $payment = Payment::create([
                'business_id' => $contract->business_id,
                'reference_type' => 'rental',
                'reference_id' => $contract->id,
                'kind' => 'deposit_top_up',
                'amount' => $this->fromCents($amount),
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'notes' => 'Deposit topped up after return — '.$reason,
                'payment_date' => now(),
                'clerk_id' => $actor->id,
                'receipt_number' => $this->nextReceiptNumber($contract->business_id),
            ]);

            // The money in is kept at once, so the hold column does not move.
            $contract->update(['deposit_applied' => $this->fromCents($applied + $amount)]);

            AuditLog::record('deposit.top_up', [
                'name' => $contract->contract_number,
                'receipt_number' => $payment->receipt_number,
                'amount' => $this->money($amount),
                'applied' => $this->money($applied).' → '.$this->money($applied + $amount),
                'reason' => $reason,
            ], $actor, $contract->business_id, $contract);

            return $payment;
        });
    }

    /**
     * Pay the customer out when the agreement ends up owing them money — an
     * adjustment cut the fees below what was already collected. Posted as a
     * negative receipt so `paid` falls back to the charges and the history
     * stays whole.
     */
    public function refundCredit(RentalContract $contract, array $data, User $actor): Payment
    {
        $reason = $this->requiredReason($data['reason'] ?? null, 'A short reason is required to refund a credit.');

        return DB::transaction(function () use ($contract, $data, $actor, $reason) {
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $credit = -$this->toCents($this->balance($contract));

            if ($credit <= 0) {
                throw ValidationException::withMessages([
                    'credit' => 'This agreement does not owe the customer anything.',
                ]);
            }

            $payment = Payment::create([
                'business_id' => $contract->business_id,
                'reference_type' => 'rental',
                'reference_id' => $contract->id,
                'kind' => 'payment',
                'amount' => $this->fromCents(-$credit),
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'notes' => 'Credit refunded — '.$reason,
                'payment_date' => now(),
                'clerk_id' => $actor->id,
                'receipt_number' => $this->nextReceiptNumber($contract->business_id),
            ]);

            AuditLog::record('payment.refunded', [
                'name' => $contract->contract_number,
                'receipt_number' => $payment->receipt_number,
                'amount' => $this->money($credit),
                'payment_method' => $payment->methodLabel(),
                'reason' => $reason,
            ], $actor, $contract->business_id, $contract);

            return $payment;
        });
    }

    /**
     * Void a receipt by posting a reversing entry, never by deleting the row.
     * The original is stamped voided_at with the reason and stops counting; the
     * reversal keeps the pair visible in the history.
     */
    /**
     * Correct a settled return. The fees and the deposit kept are amended on
     * the agreement and the change is audited with its reason; receipts already
     * issued are never edited. Handing back more of the deposit posts a new
     * refund receipt, so `received = applied + refunded` keeps holding.
     *
     * @param  array{late_fee?: mixed, deposit_applied?: mixed, damage?: array<int, mixed>, refund_method: string, reason: string}  $data
     * @return array{applied: float, refunded: float, due: float}
     */
    public function adjustSettlement(RentalContract $contract, array $data, User $actor): array
    {
        $reason = $this->requiredReason($data['reason'] ?? null, 'A short reason is required to adjust a return.');

        return DB::transaction(function () use ($contract, $data, $actor, $reason) {
            $contract = RentalContract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->load('items');

            if ($contract->status !== 'returned') {
                throw ValidationException::withMessages([
                    'adjust' => 'Only a settled return can be adjusted.',
                ]);
            }

            $before = [
                'late_fee' => $this->toCents($contract->late_fee),
                'applied' => $this->toCents($contract->deposit_applied),
                'damage' => $contract->items->sum(fn ($item) => $this->toCents($item->damage_fee)),
            ];

            $lateFee = max(0, $this->toCents($data['late_fee'] ?? 0));
            $damage = $data['damage'] ?? [];

            foreach ($contract->items as $item) {
                if (array_key_exists($item->id, $damage)) {
                    $item->update([
                        'damage_fee' => $this->fromCents(max(0, $this->toCents($damage[$item->id]))),
                    ]);
                }
            }

            // Only what is still held and not already handed back can be kept.
            $summary = $this->depositSummary($contract);
            $held = $this->toCents($summary['received']);
            $refundedSoFar = $this->toCents($summary['refunded']);
            $maxApplied = $held - $refundedSoFar;
            $applied = max(0, $this->toCents($data['deposit_applied'] ?? 0));

            if ($applied > $maxApplied) {
                throw ValidationException::withMessages([
                    'deposit_applied' => 'The deposit kept cannot exceed the $'.$this->money(max(0, $maxApplied)).' still held.',
                ]);
            }

            $refunded = 0;

            // Keeping less of the deposit means the customer is handed the rest
            // now, as a new receipt — the original refund stays untouched.
            if ($applied < $before['applied']) {
                $refunded = $before['applied'] - $applied;

                Payment::create([
                    'business_id' => $contract->business_id,
                    'reference_type' => 'rental',
                    'reference_id' => $contract->id,
                    'kind' => 'deposit_refund',
                    'amount' => $this->fromCents(-$refunded),
                    'payment_method' => $data['refund_method'],
                    'notes' => 'Deposit refunded on adjustment — '.$reason,
                    'payment_date' => now(),
                    'clerk_id' => $actor->id,
                    'receipt_number' => $this->nextReceiptNumber($contract->business_id),
                ]);
            }

            $contract->update([
                'late_fee' => $this->fromCents($lateFee),
                'deposit_applied' => $this->fromCents($applied),
                // The hold column is just what is left of it: no re-read needed.
                'deposit_paid' => $this->fromCents($held - $applied - $refundedSoFar - $refunded),
            ]);

            $contract->syncTotals();

            AuditLog::record('deposit.adjusted', [
                'name' => $contract->contract_number,
                'reason' => $reason,
                'late_fee' => $this->money($before['late_fee']).' → '.$this->money($lateFee),
                'damage' => $this->money($before['damage']).' → '.$this->money($this->toCents($contract->items->sum('damage_fee'))),
                'applied' => $this->money($before['applied']).' → '.$this->money($applied),
                'refunded' => $this->money($refunded),
            ], $actor, $contract->business_id, $contract);

            return [
                'applied' => $this->fromCents($applied),
                'refunded' => $this->fromCents($refunded),
                'due' => $this->balance($contract->fresh()),
            ];
        });
    }

    public function void(Payment $payment, User $actor, string $reason): Payment
    {
        $reason = $this->requiredReason($reason, 'A short reason is required to void a receipt.');

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->isDeposit()) {
                throw ValidationException::withMessages([
                    'payment' => 'Deposit receipts are settled at the return, not voided.',
                ]);
            }

            if ($payment->isReversal()) {
                throw ValidationException::withMessages([
                    'payment' => 'A reversing entry cannot itself be voided.',
                ]);
            }

            if ($payment->isVoided()) {
                throw ValidationException::withMessages([
                    'payment' => 'Receipt '.$payment->receipt_number.' is already voided.',
                ]);
            }

            $reversal = Payment::create([
                'business_id' => $payment->business_id,
                'reference_type' => $payment->reference_type,
                'reference_id' => $payment->reference_id,
                'amount' => $this->fromCents(-$this->toCents($payment->amount)),
                'payment_method' => $payment->payment_method,
                'transaction_id' => $payment->transaction_id,
                'notes' => 'Reversal of '.$payment->receipt_number.' — '.$reason,
                'payment_date' => now(),
                'clerk_id' => $actor->id,
                'receipt_number' => $this->nextReceiptNumber($payment->business_id),
                'reversal_of_id' => $payment->id,
                // Keep the reversal attached to the account receipt the original
                // allocation came from, so the whole receipt stays one trail.
                'account_payment_id' => $payment->account_payment_id,
            ]);

            $payment->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            AuditLog::record('payment.voided', [
                'receipt_number' => $payment->receipt_number,
                'reversal_number' => $reversal->receipt_number,
                'amount' => $this->money($this->toCents($payment->amount)),
                'reason' => $reason,
            ], $actor, $payment->business_id);

            return $reversal;
        });
    }

    /** RCP-YYYYMMDD-0001, restarting each tenant each day. */
    public function nextReceiptNumber(int $businessId): string
    {
        $prefix = 'RCP-'.now()->format('Ymd').'-';

        $sequence = Payment::query()
            ->where('business_id', $businessId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->count() + 1;

        do {
            $number = $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Payment::query()
            ->where('business_id', $businessId)
            ->where('receipt_number', $number)
            ->exists());

        return $number;
    }

    /** Money movements all need a stated reason; this is the one rule for it. */
    private function requiredReason(mixed $reason, string $message): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return $reason;
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function fromCents(int $cents): float
    {
        return $cents / 100;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
