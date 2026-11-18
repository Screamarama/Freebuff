<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\RentalContract;
use App\Services\AgreementLedger;
use App\Services\CustomerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgreementPaymentController extends Controller
{
    public function store(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $ledger->record($rentalContract, $validated, $request->user());

        return redirect()
            ->route('rental-agreements.show', $rentalContract)
            ->with('success', 'Receipt '.$payment->receipt_number.' recorded — $'
                .number_format((float) $payment->amount, 2).' by '.$payment->methodLabel().'.');
    }

    /**
     * Take the deposit at pickup. It is held, not spent, so the rent balance
     * does not move until the items come back.
     */
    public function deposit(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        $validated = $request->validate([
            'deposit_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'transaction_id' => ['nullable', 'string', 'max:100'],
        ]);

        $payment = $ledger->recordDeposit($rentalContract, [
            'amount' => $validated['deposit_amount'],
            'payment_method' => $validated['payment_method'],
            'transaction_id' => $validated['transaction_id'] ?? null,
        ], $request->user());

        return redirect()
            ->route('rental-agreements.show', $rentalContract)
            ->with('success', 'Deposit '.$payment->receipt_number.' held — $'
                .number_format((float) $payment->amount, 2).' by '.$payment->methodLabel().'.');
    }

    /**
     * Return the items and settle the deposit: late and damage fees keep as
     * much of it as they need, the remainder is refunded.
     */
    public function settle(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        $validated = $request->validate([
            'returned_on' => ['required', 'date'],
            'late_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'damage' => ['nullable', 'array'],
            'damage.*' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'refund_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'refund_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $ledger->settleDeposit($rentalContract, $validated, $request->user());

        $message = 'Agreement returned — deposit applied $'.number_format($result['applied'], 2)
            .', refunded $'.number_format($result['refunded'], 2).'.';

        if ($result['due'] > 0) {
            $message .= ' $'.number_format($result['due'], 2).' still due.';
        }

        return redirect()->route('rental-agreements.show', $rentalContract)->with('success', $message);
    }

    /**
     * Bill the agreement's balance to the customer's charge account.
     */
    public function chargeToAccount(Request $request, RentalContract $rentalContract, CustomerAccount $account): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        $contract = $account->charge($rentalContract, $request->user());

        return redirect()
            ->route('rental-agreements.show', $contract)
            ->with('success', 'Charged to the customer account — due '
                .$contract->due_on?->format('Y-m-d').'.');
    }

    /**
     * Undo a charge that should never have gone on the customer's account.
     */
    public function reverseCharge(Request $request, RentalContract $rentalContract, CustomerAccount $account): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (Gate::denies('reverseCharge', $rentalContract)) {
            abort(403, 'Only admins and managers can reverse an account charge.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $account->reverseCharge($rentalContract, $request->user(), $validated['reason']);

        return redirect()
            ->route('rental-agreements.show', $rentalContract)
            ->with('success', 'The account charge was reversed — '.$validated['reason'].'.');
    }

    /**
     * Fix a settled return: fees and the deposit kept, with a reason. The
     * receipts already issued stay as they are.
     */
    public function adjustSettlement(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (Gate::denies('adjustSettlement', $rentalContract)) {
            abort(403, 'Only admins and managers can adjust a settled return.');
        }

        $validated = $request->validate([
            'late_fee' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'deposit_applied' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'damage' => ['nullable', 'array'],
            'damage.*' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'refund_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $result = $ledger->adjustSettlement($rentalContract, $validated, $request->user());

        $message = 'Return adjusted — deposit applied $'.number_format($result['applied'], 2);

        if ($result['refunded'] > 0) {
            $message .= ', refunded $'.number_format($result['refunded'], 2);
        }

        $message .= ', $'.number_format($result['due'], 2).' still due.';

        return redirect()->route('rental-agreements.show', $rentalContract)->with('success', $message);
    }

    /**
     * Take more deposit after the return, so a reduction in what was kept can
     * be put back on the books.
     */
    public function topUpDeposit(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (Gate::denies('topUpDeposit', $rentalContract)) {
            abort(403, 'Only admins and managers can take a deposit top-up.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $payment = $ledger->topUpDeposit($rentalContract, $validated, $request->user());

        return redirect()
            ->route('rental-agreements.show', $rentalContract)
            ->with('success', 'Deposit top-up '.$payment->receipt_number.' taken — $'
                .number_format((float) $payment->amount, 2).' by '.$payment->methodLabel().'.');
    }

    /**
     * Hand back money the agreement owes the customer, after an adjustment cut
     * the fees below what was collected.
     */
    public function refundCredit(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (Gate::denies('refundCredit', $rentalContract)) {
            abort(403, 'Only admins and managers can refund a credit.');
        }

        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $payment = $ledger->refundCredit($rentalContract, $validated, $request->user());

        return redirect()
            ->route('rental-agreements.show', $rentalContract)
            ->with('success', 'Credit of $'.number_format(abs((float) $payment->amount), 2)
                .' refunded — receipt '.$payment->receipt_number.'.');
    }

    /**
     * Voiding moves money backwards, so it is a manager's call, not whoever
     * happens to be at the counter. PaymentPolicy is the one place that rule
     * lives; the agreement page asks the same policy before showing the form.
     */
    public function void(Request $request, RentalContract $rentalContract, Payment $payment, AgreementLedger $ledger, CustomerAccount $accounts): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (Gate::denies('void', $payment)) {
            abort(403, 'Only admins and managers can void a receipt.');
        }

        // A deposit is settled at the return; voiding it would strand the fees
        // it was applied to.
        if ($payment->isDeposit()) {
            abort(422, 'Deposit receipts are settled at the return, not voided.');
        }

        abort_unless(
            $payment->reference_type === 'rental' && (int) $payment->reference_id === (int) $rentalContract->id,
            404
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        // This row is one allocation of a customer-level account receipt: the
        // receipt and its other allocations have to move with it, or the
        // receipt would claim money it no longer has.
        if ($payment->account_payment_id !== null) {
            $receipt = $payment->accountPayment;
            abort_if($receipt === null, 404);

            $count = $accounts->voidPayment($receipt, $request->user(), $validated['reason']);

            return redirect()
                ->route('rental-agreements.show', $rentalContract)
                ->with('success', 'Account receipt '.$receipt->receipt_number.' voided — '
                    .$count.' '.($count === 1 ? 'charge is' : 'charges are').' unpaid again.');
        }

        $reversal = $ledger->void($payment, $request->user(), $validated['reason']);

        return redirect()
            ->route('rental-agreements.show', $rentalContract)
            ->with('success', 'Receipt '.$payment->receipt_number.' voided by reversing entry '
                .$reversal->receipt_number.'.');
    }

    private function ensureTenantOwns(Request $request, ?int $businessId): void
    {
        if ($request->user()->role === 'super_admin') {
            return;
        }

        if ($businessId !== $request->user()->business_id) {
            abort(404);
        }
    }
}
