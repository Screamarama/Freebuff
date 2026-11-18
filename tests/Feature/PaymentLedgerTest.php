<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $name = 'Acme Rentals'): Business
    {
        return Business::create([
            'name' => $name,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?Business $business = null, string $email = 'clerk@acme.test'): User
    {
        $user = new User(['name' => 'Dana Clerk', 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $business?->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function contract(Business $business, float $total = 100.00, string $status = 'active'): RentalContract
    {
        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => $status,
            'subtotal' => $total,
            'total_amount' => $total,
        ]);
    }

    public function test_a_partial_cash_payment_is_recorded_against_the_agreement(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.store', $contract), [
                'amount' => '40.00',
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract));

        $payment = Payment::sole();

        $this->assertSame($business->id, $payment->business_id);
        $this->assertSame($contract->id, (int) $payment->reference_id);
        $this->assertSame('rental', $payment->reference_type);
        $this->assertSame('40.00', $payment->amount);
        $this->assertSame('cash', $payment->payment_method);
        $this->assertSame($clerk->id, $payment->clerk_id);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-0001$/', $payment->receipt_number);

        // Money movements land in the audit log like every other change.
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.recorded']);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('paid', fn ($paid) => round($paid, 2) === 40.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 60.0)
            ->assertSee($payment->receipt_number)
            ->assertSee('Balance due');
    }

    public function test_payments_add_up_until_the_agreement_is_paid(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00);

        foreach ([['40.00', 'cash'], ['60.00', 'card']] as [$amount, $method]) {
            $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
                'amount' => $amount,
                'payment_method' => $method,
            ])->assertSessionHasNoErrors();
        }

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('paid', fn ($paid) => round($paid, 2) === 100.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 0.0);
    }

    public function test_a_payment_larger_than_the_balance_is_rejected(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.store', $contract), [
                'amount' => '150.00',
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    public function test_the_balance_is_shared_so_the_last_payment_may_not_overshoot(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '90.00',
            'payment_method' => 'check',
        ])->assertSessionHasNoErrors();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.store', $contract), [
                'amount' => '20.00',
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, Payment::count());
    }

    public function test_voiding_a_payment_posts_a_reversing_entry(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
            'transaction_id' => 'CHK-77',
        ]);

        $original = Payment::sole();

        $this->actingAs($admin)
            ->patch(route('rental-agreements.payments.void', [$contract, $original]), [
                'reason' => 'Rang up the wrong amount',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract));

        $original->refresh();
        $reversal = Payment::whereNotNull('reversal_of_id')->sole();

        $this->assertNotNull($original->voided_at);
        $this->assertSame('Rang up the wrong amount', $original->void_reason);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('-40.00', $reversal->amount);
        $this->assertNotSame($original->receipt_number, $reversal->receipt_number);
        $this->assertSame('rental', $reversal->reference_type);
        $this->assertSame($contract->id, (int) $reversal->reference_id);

        // Both rows stay: history is kept, the pair nets to nothing.
        $this->assertSame(2, Payment::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.voided']);

        // The reason is part of the audit record, not just the row.
        $audit = AuditLog::where('action', 'payment.voided')->sole();
        $this->assertSame('Rang up the wrong amount', $audit->details['reason']);
        $this->assertSame($original->receipt_number, $audit->details['receipt_number']);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('paid', fn ($paid) => round($paid, 2) === 0.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 100.0)
            ->assertSee('voided')
            ->assertSee('reversal')
            ->assertSee('Rang up the wrong amount');
    }

    public function test_a_manager_can_void_a_receipt(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $manager = $this->user('manager', $business, 'manager@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '25.00',
            'payment_method' => 'card',
        ]);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.void', [$contract, Payment::sole()]), [
                'reason' => 'Card declined after the fact',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Payment::whereNotNull('reversal_of_id')->count());
    }

    public function test_a_clerk_cannot_void_a_receipt(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]);

        $payment = Payment::sole();

        $this->actingAs($clerk)
            ->patch(route('rental-agreements.payments.void', [$contract, $payment]), [
                'reason' => 'Trying it anyway',
            ])
            ->assertForbidden();

        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Payment::whereNotNull('reversal_of_id')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'payment.voided']);
    }

    public function test_a_clerk_is_not_offered_the_void_control(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]);

        $voidUrl = route('rental-agreements.payments.void', [$contract, Payment::sole()]);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee($voidUrl);

        $this->actingAs($admin)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee($voidUrl)
            ->assertSee('Reason');
    }

    public function test_a_void_requires_a_reason(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]);

        $payment = Payment::sole();

        foreach (['' => 'empty', '  ' => 'blank', 'x' => 'too short'] as $reason => $case) {
            $this->actingAs($admin)
                ->patch(route('rental-agreements.payments.void', [$contract, $payment]), [
                    'reason' => $reason,
                ])
                ->assertSessionHasErrors('reason');
        }

        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Payment::whereNotNull('reversal_of_id')->count());
    }

    public function test_a_refused_void_shows_the_error_and_keeps_the_typed_reason(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $contract = $this->contract($business, 100.00);

        foreach (['5.00', '7.00'] as $amount) {
            $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
                'amount' => $amount,
                'payment_method' => 'cash',
            ]);
        }

        $payments = Payment::orderBy('id')->get();

        // Follow the redirect like a browser does, so the flashed errors and
        // old input are what the page renders.
        $html = $this->actingAs($admin)
            ->followingRedirects()
            ->from(route('rental-agreements.show', $contract))
            ->patch(route('rental-agreements.payments.void', [$contract, $payments[1]]), [
                // Exactly what the row's form posts, including the row marker.
                'payment_id' => $payments[1]->id,
                'reason' => 'x',
            ])
            ->assertOk()
            ->assertSee('at least 3 characters')
            ->getContent();

        // The typed reason comes back on the refused row, and only that row.
        $this->assertSame(1, substr_count($html, 'value="x"'));
        $this->assertSame(1, substr_count($html, 'name="payment_id" value="'.$payments[1]->id.'"'));

        // Nothing was posted and nothing was voided.
        $this->assertSame(2, Payment::count());
        $this->assertNull($payments[1]->fresh()->voided_at);
        $this->assertSame(0, Payment::whereNotNull('reversal_of_id')->count());
    }

    public function test_the_void_rules_live_in_one_policy(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $manager = $this->user('manager', $business, 'manager@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '5.00',
            'payment_method' => 'cash',
        ]);

        $payment = Payment::sole();

        // The page's @can and the endpoint's Gate both read PaymentPolicy.
        $this->assertFalse($clerk->can('void', $payment));
        $this->assertTrue($admin->can('void', $payment));
        $this->assertTrue($manager->can('void', $payment));
    }

    public function test_a_receipt_cannot_be_voided_twice(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]);

        $payment = Payment::sole();

        $this->actingAs($admin)->patch(route('rental-agreements.payments.void', [$contract, $payment]), [
            'reason' => 'Wrong method',
        ]);
        $this->actingAs($admin)
            ->patch(route('rental-agreements.payments.void', [$contract, $payment]), [
                'reason' => 'Wrong method again',
            ])
            ->assertSessionHasErrors('payment');

        // Original plus one reversal — the second void changed nothing.
        $this->assertSame(2, Payment::count());
        $this->assertSame(1, Payment::whereNotNull('reversal_of_id')->count());
    }

    public function test_a_reversing_entry_cannot_itself_be_voided(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]);

        $this->actingAs($admin)->patch(route('rental-agreements.payments.void', [$contract, Payment::sole()]), [
            'reason' => 'Duplicate charge',
        ]);
        $reversal = Payment::whereNotNull('reversal_of_id')->sole();

        $this->actingAs($admin)
            ->patch(route('rental-agreements.payments.void', [$contract, $reversal]), [
                'reason' => 'Trying to void the reversal',
            ])
            ->assertSessionHasErrors('payment');
    }

    public function test_cancelled_agreements_take_no_payments(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00, 'cancelled');

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.store', $contract), [
                'amount' => '10.00',
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    public function test_payments_are_scoped_to_the_tenant(): void
    {
        $acme = $this->business('Acme Rentals');
        $beta = $this->business('Beta Rentals');
        $acmeClerk = $this->user('clerk', $acme, 'clerk@acme.test');
        $betaClerk = $this->user('clerk', $beta, 'clerk@beta.test');

        $acmeContract = $this->contract($acme, 100.00);

        $this->actingAs($acmeClerk)->post(route('rental-agreements.payments.store', $acmeContract), [
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]);

        $payment = Payment::sole();

        // Another tenant cannot pay this agreement or void its receipt.
        $this->actingAs($betaClerk)
            ->post(route('rental-agreements.payments.store', $acmeContract), [
                'amount' => '10.00',
                'payment_method' => 'cash',
            ])
            ->assertNotFound();

        $this->actingAs($betaClerk)
            ->patch(route('rental-agreements.payments.void', [$acmeContract, $payment]), [
                'reason' => 'Cross-tenant attempt',
            ])
            ->assertNotFound();

        $this->assertNull($payment->fresh()->voided_at);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_receipt_belongs_to_the_agreement_it_was_taken_for(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $admin = $this->user('admin', $business, 'admin@acme.test');
        $first = $this->contract($business, 100.00);
        $second = $this->contract($business, 100.00);

        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $first), [
            'amount' => '25.00',
            'payment_method' => 'cash',
        ]);

        $this->actingAs($admin)
            ->patch(route('rental-agreements.payments.void', [$second, Payment::sole()]), [
                'reason' => 'Wrong agreement',
            ])
            ->assertNotFound();
    }

    public function test_receipt_numbers_restart_for_each_tenant(): void
    {
        $acme = $this->business('Acme Rentals');
        $beta = $this->business('Beta Rentals');
        $acmeClerk = $this->user('clerk', $acme, 'clerk@acme.test');
        $betaClerk = $this->user('clerk', $beta, 'clerk@beta.test');

        $this->actingAs($acmeClerk)->post(route('rental-agreements.payments.store', $this->contract($acme)), [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ]);
        $this->actingAs($betaClerk)->post(route('rental-agreements.payments.store', $this->contract($beta)), [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ]);

        $numbers = Payment::orderBy('id')->pluck('receipt_number');

        $this->assertMatchesRegularExpression('/^RCP-\d{8}-0001$/', $numbers[0]);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-0001$/', $numbers[1]);
        $this->assertSame(2, Payment::distinct()->count('business_id'));
    }

    public function test_a_clerk_can_only_use_the_offered_methods(): void
    {
        $business = $this->business();
        $clerk = $this->user('clerk', $business);
        $contract = $this->contract($business, 100.00);

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.store', $contract), [
                'amount' => '10.00',
                'payment_method' => 'crypto',
            ])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Payment::count());
    }
}
