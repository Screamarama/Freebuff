<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use App\Services\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function business(): Business
    {
        return Business::create([
            'name' => 'Acme Rentals',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function user(Business $business, string $role = 'manager', string $email = 'manager@acme.test'): User
    {
        $user = new User(['name' => 'Mia Manager', 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function customer(
        Business $business,
        string $type = 'charge',
        ?string $limit = '500.00',
        string $code = 'C-ACC1',
    ): Customer {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => $code,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
            'account_type' => $type,
            'credit_limit' => $limit,
        ]);
    }

    private function contract(Business $business, Customer $customer, float $total = 100.00): RentalContract
    {
        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => $total,
            'total_amount' => $total,
        ]);
    }

    public function test_a_charge_account_needs_a_credit_limit(): void
    {
        $business = $this->business();
        $manager = $this->user($business);

        $payload = [
            'customer_code' => 'C-NEW1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'charge',
        ];

        $this->actingAs($manager)
            ->post(route('customers.store'), $payload)
            ->assertSessionHasErrors('credit_limit');

        $this->actingAs($manager)
            ->post(route('customers.store'), $payload + ['credit_limit' => '250.00'])
            ->assertSessionHasNoErrors();

        $customer = Customer::where('customer_code', 'C-NEW1')->sole();

        $this->assertTrue($customer->isChargeAccount());
        $this->assertSame('250.00', $customer->credit_limit);
    }

    public function test_charging_an_agreement_puts_its_balance_on_the_account(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertNotNull($contract->charged_to_account_at);
        $this->assertSame(now()->addDays(CustomerAccount::TERMS_DAYS)->toDateString(), $contract->due_on->toDateString());
        // The sale is frozen at billing, so editing the agreement later cannot
        // move the sales figure its tax report already stated.
        $this->assertSame(100.00, (float) $contract->charged_sales_amount);

        // No money moved: the balance is still owed, just on the account now.
        $this->assertDatabaseMissing('payments', ['reference_id' => $contract->id]);
        // The balance itself is now receivable on the account.
        $this->assertSame(100.00, app(\App\Services\AgreementLedger::class)->balance($contract));
        $this->assertSame(100.00, app(CustomerAccount::class)->openBalance($customer->fresh()));
        $this->assertSame(400.00, app(CustomerAccount::class)->availableCredit($customer->fresh()));

        $this->assertDatabaseHas('audit_logs', ['action' => 'account.charged']);

        $contract->update(['subtotal' => 150.00, 'total_amount' => 150.00]);
        $this->assertSame(100.00, (float) $contract->fresh()->charged_sales_amount);
    }

    public function test_charging_an_agreement_keeps_the_exemption_certificate_it_used(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $customer->update([
            'tax_exemption_number' => 'EX-9',
            'tax_exemption_expires_on' => '2027-01-01',
            'tax_exemption_reason' => 'Resale',
            'tax_exempt_copy_on_file' => true,
        ]);

        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('EX-9', $contract->tax_exemption_number);
        $this->assertSame('2027-01-01', $contract->tax_exemption_expires_on->toDateString());
        $this->assertSame('Resale', $contract->tax_exemption_reason);

        // A later correction to the customer leaves the billed sale's support.
        $customer->update(['tax_exemption_number' => 'EX-10', 'tax_exemption_reason' => 'Government']);
        $this->assertSame('EX-9', $contract->fresh()->tax_exemption_number);
        $this->assertSame('Resale', $contract->fresh()->tax_exemption_reason);
    }

    public function test_a_charge_over_the_limit_is_refused(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '50.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasErrors('account');

        $this->assertNull($contract->fresh()->charged_to_account_at);
        $this->assertSame(0, AuditLog::where('action', 'account.charged')->count());
    }

    public function test_a_cash_customer_cannot_be_charged(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'cash', null);
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasErrors('account');

        $this->assertNull($contract->fresh()->charged_to_account_at);
        $this->assertStringContainsString(
            'cash account',
            session('errors')->first('account')
        );
    }

    public function test_an_agreement_cannot_be_charged_twice(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasNoErrors();

        $first = $contract->fresh()->charged_to_account_at;

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasErrors('account');

        $this->assertSame(
            $first->toDateTimeString(),
            $contract->fresh()->charged_to_account_at->toDateTimeString()
        );
    }

    public function test_a_paid_agreement_has_nothing_to_charge(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '100.00',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasErrors('account');

        $this->assertNull($contract->fresh()->charged_to_account_at);
    }

    public function test_the_limit_counts_what_is_already_on_the_account(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '150.00');

        $first = $this->contract($business, $customer, 100.00);
        $second = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $first))
            ->assertSessionHasNoErrors();

        // $100 of the $150 limit is taken, so a second $100 does not fit.
        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $second))
            ->assertSessionHasErrors('account');

        $this->assertNull($second->fresh()->charged_to_account_at);
        $this->assertSame(50.00, app(CustomerAccount::class)->availableCredit($customer->fresh()));
    }

    public function test_a_manager_can_reverse_a_charge_with_a_reason(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasNoErrors();

        $this->assertSame(100.00, app(CustomerAccount::class)->openBalance($customer));

        $this->actingAs($manager)
            ->post(route('rental-agreements.reverse-charge', $contract), [
                'reason' => 'Billed to the wrong account',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        // The charge itself stays in the history; only its effect is undone.
        $this->assertNotNull($contract->charged_to_account_at);
        $this->assertNotNull($contract->charge_reversed_at);
        $this->assertSame('Billed to the wrong account', $contract->charge_reversed_reason);
        $this->assertFalse($contract->isOnAccount());

        $this->assertSame(0.0, app(CustomerAccount::class)->openBalance($customer->fresh()));
        $this->assertSame(500.0, app(CustomerAccount::class)->availableCredit($customer->fresh()));

        $audit = AuditLog::where('action', 'account.charge_reversed')->sole();
        $this->assertSame('Billed to the wrong account', $audit->details['reason']);
        $this->assertSame('100.00', $audit->details['amount']);

        // The page says what happened and offers the charge again.
        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee('reversed on')
            ->assertSee('Billed to the wrong account')
            ->assertSee(route('rental-agreements.charge-account', $contract))
            ->assertDontSee(route('rental-agreements.reverse-charge', $contract));
    }

    public function test_a_clerk_cannot_reverse_a_charge(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasNoErrors();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.reverse-charge', $contract), [
                'reason' => 'Trying it anyway',
            ])
            ->assertForbidden();

        $this->assertNull($contract->fresh()->charge_reversed_at);
        $this->assertSame(100.00, app(CustomerAccount::class)->openBalance($customer));
        $this->assertSame(0, AuditLog::where('action', 'account.charge_reversed')->count());

        // Same policy the page asks: no control for a clerk.
        $this->assertFalse($clerk->can('reverseCharge', $contract));
        $this->assertTrue($manager->can('reverseCharge', $contract));

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee(route('rental-agreements.reverse-charge', $contract));
    }

    public function test_reversing_a_charge_needs_a_reason(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract));

        foreach (['', 'x'] as $reason) {
            $this->actingAs($manager)
                ->post(route('rental-agreements.reverse-charge', $contract), ['reason' => $reason])
                ->assertSessionHasErrors('reason');
        }

        $this->assertNull($contract->fresh()->charge_reversed_at);
        $this->assertSame(100.00, app(CustomerAccount::class)->openBalance($customer));
    }

    public function test_only_a_charge_on_the_account_can_be_reversed(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)
            ->post(route('rental-agreements.reverse-charge', $contract), ['reason' => 'Never charged'])
            ->assertSessionHasErrors('account');

        $this->assertNull($contract->fresh()->charge_reversed_at);
    }

    public function test_a_charge_cannot_be_reversed_twice(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract));
        $this->actingAs($manager)->post(route('rental-agreements.reverse-charge', $contract), [
            'reason' => 'Wrong account',
        ])->assertSessionHasNoErrors();

        $first = $contract->fresh()->charge_reversed_at;

        $this->actingAs($manager)
            ->post(route('rental-agreements.reverse-charge', $contract), ['reason' => 'Again'])
            ->assertSessionHasErrors('account');

        $this->assertSame(
            $first->toDateTimeString(),
            $contract->fresh()->charge_reversed_at->toDateTimeString()
        );
        $this->assertSame('Wrong account', $contract->fresh()->charge_reversed_reason);
    }

    public function test_a_charge_already_paid_against_cannot_be_reversed(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);
        $ledger = app(AgreementLedger::class);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract));

        // The customer pays part of the account charge at the counter.
        $payment = $ledger->record($contract, ['amount' => 20, 'payment_method' => 'cash'], $manager);

        $this->actingAs($manager)
            ->post(route('rental-agreements.reverse-charge', $contract), ['reason' => 'Wrong account'])
            ->assertSessionHasErrors([
                'account' => '$20.00 has already been paid against this charge. Void the payment first if it was wrong.',
            ]);

        $this->assertNull($contract->fresh()->charge_reversed_at);
        $this->assertSame(80.00, app(CustomerAccount::class)->openBalance($customer->fresh()));

        // The message's advice works: void the receipt and the charge can come off.
        $ledger->void($payment, $manager, 'Taken on the wrong agreement');

        $this->actingAs($manager)
            ->post(route('rental-agreements.reverse-charge', $contract), ['reason' => 'Wrong account'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($contract->fresh()->charge_reversed_at);
        $this->assertSame(0.0, app(CustomerAccount::class)->openBalance($customer->fresh()));
    }

    public function test_an_agreement_can_be_charged_again_after_a_reversal(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract));
        $this->actingAs($manager)->post(route('rental-agreements.reverse-charge', $contract), [
            'reason' => 'Wrong account',
        ]);

        $this->actingAs($manager)
            ->post(route('rental-agreements.charge-account', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertTrue($contract->isOnAccount());
        $this->assertSame(100.00, app(CustomerAccount::class)->openBalance($customer->fresh()));
        $this->assertSame(2, AuditLog::where('action', 'account.charged')->count());
    }

    public function test_the_agreement_page_offers_the_charge_and_shows_the_account(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'charge', '500.00');
        $contract = $this->contract($business, $customer, 100.00);

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee(route('rental-agreements.charge-account', $contract))
            ->assertSee('Available credit')
            ->assertSee('$500.00');

        $this->actingAs($manager)->post(route('rental-agreements.charge-account', $contract));

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee(route('rental-agreements.charge-account', $contract))
            ->assertSee('Charged to the account on')
            ->assertSee($contract->fresh()->due_on->format('Y-m-d'));
    }
}
