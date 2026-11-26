<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Support\Money;
use App\Services\CustomerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->where('is_active', true);

        if ($request->user()->role !== 'super_admin') {
            $query->where('business_id', $request->user()->business_id);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%' . $request->search . '%')
                    ->orWhere('last_name', 'like', '%' . $request->search . '%')
                    ->orWhere('customer_code', 'like', '%' . $request->search . '%')
                    ->orWhere('phone', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $customers = $query->latest()->paginate(12);

        return view('customers.index', compact('customers'));
    }

    public function create(Request $request): View
    {
        // Only the agreement flow sets this; opening the normal customer form
        // means any half-finished agreement draft has been abandoned.
        $fromAgreement = $request->boolean('from_agreement')
            || $request->old('return_to_agreement') === '1';

        if (! $fromAgreement) {
            $request->session()->forget('agreement_draft');
        }

        return view('customers.create', compact('fromAgreement'));
    }

    /** Abandoning a customer started from an agreement drops the saved draft. */
    public function cancelCreate(Request $request): RedirectResponse
    {
        $request->session()->forget('agreement_draft');

        return redirect()->route('customers.index');
    }

    public function store(CustomerRequest $request)
    {
        $businessId = $request->user()->business_id;
        $validated = $request->validated();

        $validated['business_id'] = $businessId;
        $validated['is_active'] = true;
        $validated['tax_exempt_copy_on_file'] = $request->boolean('tax_exempt_copy_on_file');

        abort_if(
            ! $this->maySetExemption($request) && (
                filled($validated['tax_exemption_number'] ?? null)
                || filled($validated['tax_exemption_expires_on'] ?? null)
                || filled($validated['tax_exemption_reason'] ?? null)
                || $validated['tax_exempt_copy_on_file']
            ),
            403,
            'Only an admin or manager can set a tax exemption.'
        );

        $customer = Customer::create($validated);

        $this->auditExemption($customer, [
            'tax_exemption_number' => null,
            'tax_exemption_expires_on' => null,
            'tax_exempt_copy_on_file' => 'No',
        ], $request);

        $draft = $request->session()->get('agreement_draft');

        if ($request->boolean('return_to_agreement') && is_array($draft)) {
            // Started from the agreement form: go back to it with this customer picked.
            $request->session()->put('agreement_draft', [...$draft, 'customer_id' => $customer->id]);

            return redirect()->route('rental-agreements.create', ['resume' => 1])->with('success', 'Customer created and selected.');
        }

        return redirect()->route('customers.index')->with('success', 'Customer created!');
    }

    public function show(Request $request, Customer $customer, CustomerAccount $accounts)
    {
        $this->ensureSameTenant($request, $customer);

        $position = $accounts->position($customer);

        return view('customers.show', [
            'customer' => $customer,
            'deleteBlockedReason' => $this->deletionBlockedReason($customer, $accounts),
            'accountBalance' => $position['owed'],
            'accountCredit' => $position['credited'],
            'accountAvailable' => $accounts->availableCredit($customer),
            'accountPayments' => Payment::query()
                ->where('business_id', $customer->business_id)
                ->where('reference_type', 'customer')
                ->where('reference_id', $customer->id)
                ->where('kind', 'account_payment')
                ->with(['clerk', 'allocations.rentalContract'])
                ->latest('payment_date')
                ->latest('id')
                ->get(),
        ]);
    }

    /**
     * Take one payment against the whole account and let the service split it
     * across the oldest open charges.
     */
    public function recordAccountPayment(Request $request, Customer $customer, CustomerAccount $accounts): RedirectResponse
    {
        $this->ensureSameTenant($request, $customer);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in(Payment::COUNTER_METHODS)],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $receipt = $accounts->recordPayment($customer, $validated, $request->user());

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Account receipt '.$receipt->receipt_number.' recorded — $'
                .number_format((float) $receipt->amount, 2).' by '.$receipt->methodLabel().'.');
    }

    /** One printable page: every charge and payment, the balance and its aging. */
    public function statement(Request $request, Customer $customer, CustomerAccount $accounts): View
    {
        $this->ensureSameTenant($request, $customer);

        $aging = $accounts->aging($customer->business_id, $customer->id);
        $statement = $accounts->statement($customer);

        return view('customers.statement', [
            'customer' => $customer,
            'statement' => $statement,
            'position' => $accounts->position($customer),
            'agingRow' => $aging['rows'][0] ?? null,
            'buckets' => CustomerAccount::AGING_BUCKETS,
            'currency' => $statement['currency'],
        ]);
    }

    /** Void a whole account receipt from the customer page. */
    public function voidAccountPayment(Request $request, Customer $customer, Payment $payment, CustomerAccount $accounts): RedirectResponse
    {
        $this->ensureSameTenant($request, $customer);

        abort_unless(
            $payment->isAccountPayment()
                && $payment->reference_type === 'customer'
                && (int) $payment->reference_id === (int) $customer->id,
            404
        );

        if (Gate::denies('void', $payment)) {
            abort(403, 'Only admins and managers can void a receipt.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $count = $accounts->voidPayment($payment, $request->user(), $validated['reason']);

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Account receipt '.$payment->receipt_number.' voided — '
                .$count.' '.($count === 1 ? 'charge is' : 'charges are').' unpaid again.');
    }

    public function edit(Request $request, Customer $customer)
    {
        $this->ensureSameTenant($request, $customer);

        return view('customers.edit', compact('customer'));
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $validated = $request->validated();

        if ($this->maySetExemption($request)) {
            $validated['tax_exempt_copy_on_file'] = $request->boolean('tax_exempt_copy_on_file');
        } else {
            // A clerk editing ordinary details sends no exemption fields, and any
            // attempt to move them is refused rather than silently ignored.
            abort_if(
                $this->exemptionChanged($request, $customer),
                403,
                'Only an admin or manager can change a tax exemption.'
            );

            unset(
                $validated['tax_exemption_number'],
                $validated['tax_exemption_expires_on'],
                $validated['tax_exemption_reason'],
                $validated['tax_exempt_copy_on_file']
            );
        }

        $before = $this->exemptionFields($customer);

        $customer->update($validated);

        $this->auditExemption($customer->fresh(), $before, $request);

        return redirect()->route('customers.index')->with('success', 'Customer updated!');
    }

    public function destroy(Request $request, Customer $customer, CustomerAccount $accounts)
    {
        $this->ensureSameTenant($request, $customer);

        if ($reason = $this->deletionBlockedReason($customer, $accounts)) {
            return redirect()->route('customers.show', $customer)->with('error', $reason);
        }

        $customer->update(['is_active' => false]);

        return redirect()->route('customers.index')->with('success', 'Customer deleted!');
    }

    /** Why this customer cannot be deleted, or null when they can. */
    private function deletionBlockedReason(Customer $customer, CustomerAccount $accounts): ?string
    {
        $openAgreement = RentalContract::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['returned', 'cancelled'])
            ->exists();

        if ($openAgreement) {
            return 'This customer has an open rental agreement; close it before deleting the customer.';
        }

        if (Reservation::blocking()->where('customer_id', $customer->id)->exists()) {
            return 'This customer has an upcoming reservation; cancel or convert it before deleting the customer.';
        }

        $owed = $accounts->position($customer)['net'];

        if ($owed > 0) {
            return 'This customer owes '.Money::format($owed, $customer->business?->currency)
                .' on their charge account; settle the balance before deleting the customer.';
        }

        return null;
    }

    /**
     * The exemption as the audit log shows it, in readable terms rather than
     * raw booleans and date strings.
     *
     * @return array<string, ?string>
     */
    private function exemptionFields(Customer $customer, bool $raw = false): array
    {
        return [
            'tax_exemption_number' => $customer->tax_exemption_number ?: null,
            'tax_exemption_expires_on' => $customer->tax_exemption_expires_on?->toDateString(),
            'tax_exemption_reason' => $customer->tax_exemption_reason ?: null,
            'tax_exempt_copy_on_file' => $raw ? (bool) $customer->tax_exempt_copy_on_file
                : ($customer->tax_exempt_copy_on_file ? 'Yes' : 'No'),
        ];
    }

    /**
     * Whether the request moves any exemption field. Only the fields it actually
     * sent are compared, so an ordinary edit that omits them changes nothing.
     */
    private function exemptionChanged(Request $request, Customer $customer): bool
    {
        $current = $this->exemptionFields($customer, raw: true);

        foreach (['tax_exemption_number', 'tax_exemption_expires_on', 'tax_exemption_reason'] as $field) {
            if ($request->has($field) && ($request->input($field) ?: null) !== $current[$field]) {
                return true;
            }
        }

        return $request->has('tax_exempt_copy_on_file')
            && $request->boolean('tax_exempt_copy_on_file') !== $current['tax_exempt_copy_on_file'];
    }

    /** Exemptions are a financial control: only admin and manager may move them. */
    private function maySetExemption(Request $request): bool
    {
        return in_array($request->user()->role, ['super_admin', 'admin', 'manager'], true);
    }

    /**
     * Changing what exempts a customer changes what tax they are charged, so
     * every edit is recorded with the old and new value.
     */
    private function auditExemption(Customer $customer, array $before, Request $request): void
    {
        $after = $this->exemptionFields($customer);
        $changes = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        if ($changes === []) {
            return;
        }

        AuditLog::record('customer.tax_exemption_updated', [
            'name' => $customer->full_name,
            'changes' => $changes,
        ], $request->user(), $customer->business_id, $customer);
    }

    private function ensureSameTenant(Request $request, Customer $customer): void
    {
        if ($request->user()->role === 'super_admin') {
            return;
        }

        if ($customer->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }
}
