<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnAdjustmentTest extends TestCase
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
        $user->save();

        return $user;
    }

    /** One rented item at $100 with a $50 deposit, already paid for. */
    private function contract(Business $business): RentalContract
    {
        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'name' => 'Excavator',
            'product_type' => 'rental',
            'rental_price_day' => 100.00,
            'deposit_amount' => 50.00,
        ]);

        $contract = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
        ]);

        $contract->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 100.00,
            'duration' => 1,
            'subtotal' => 100.00,
        ]);

        return $contract;
    }

    /** Rent paid, $50 held, returned with $20 late and $10 damage: $30 kept, $20 back. */
    private function settledReturn(Business $business, User $actor): RentalContract
    {
        $contract = $this->contract($business);
        $item = $contract->items()->first();

        $this->actingAs($actor)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => '100.00',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('rental-agreements.payments.deposit', $contract), [
            'deposit_amount' => '50.00',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($actor)->post(route('rental-agreements.payments.settle', $contract), [
            'returned_on' => now()->toDateString(),
            'late_fee' => '20.00',
            'damage' => [$item->id => '10.00'],
            'refund_method' => 'cash',
        ])->assertSessionHasNoErrors();

        return $contract->fresh();
    }

    private function adjust(RentalContract $contract, array $overrides = []): array
    {
        $item = $contract->items()->first();

        return array_merge([
            'late_fee' => '20.00',
            'deposit_applied' => '30.00',
            'damage' => [$item->id => '10.00'],
            'refund_method' => 'cash',
            'reason' => 'Late fee was keyed twice',
        ], $overrides);
    }

    public function test_a_manager_can_raise_the_late_fee_on_a_settled_return(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->settledReturn($business, $manager);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
                'late_fee' => '40.00',
            ]))
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('40.00', $contract->late_fee);
        $this->assertSame('140.00', $contract->total_amount);

        // $140 rent+late plus $10 damage, against $100 rent paid and $30 kept.
        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('charges', fn ($charges) => round($charges, 2) === 150.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 20.0);

        $audit = AuditLog::where('action', 'deposit.adjusted')->sole();
        $this->assertSame('20.00 → 40.00', $audit->details['late_fee']);
        $this->assertSame('Late fee was keyed twice', $audit->details['reason']);
    }

    public function test_a_damage_fee_can_be_corrected_downwards(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->settledReturn($business, $manager);
        $item = $contract->items()->first();

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
                'damage' => [$item->id => '0'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $item->fresh()->damage_fee);

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('charges', fn ($charges) => round($charges, 2) === 120.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === -10.0);

        $this->assertSame('10.00 → 0.00', AuditLog::where('action', 'deposit.adjusted')->sole()->details['damage']);
    }

    public function test_keeping_less_of_the_deposit_posts_a_refund_and_keeps_the_receipts(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->settledReturn($business, $manager);

        $originalRefund = Payment::where('kind', 'deposit_refund')->sole();
        $receiptsBefore = Payment::count();

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
                'deposit_applied' => '10.00',
                'refund_method' => 'card',
            ]))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('10.00', $contract->deposit_applied);
        $this->assertSame('0.00', $contract->deposit_paid);

        // The settlement's own refund is untouched; a second one pays back $20.
        $this->assertSame('-20.00', $originalRefund->fresh()->amount);
        $this->assertSame($receiptsBefore + 1, Payment::count());

        $extra = Payment::where('kind', 'deposit_refund')->where('id', '!=', $originalRefund->id)->sole();
        $this->assertSame('-20.00', $extra->amount);
        $this->assertSame('card', $extra->payment_method);

        // $50 taken in is now $10 kept and $40 handed back.
        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('deposit', fn ($deposit) => round($deposit['applied'], 2) === 10.0
                && round($deposit['refunded'], 2) === 40.0
                && round($deposit['remaining'], 2) === 0.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 20.0);
    }

    public function test_more_cannot_be_kept_than_was_taken_in(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->settledReturn($business, $manager);
        $receiptsBefore = Payment::count();

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
                'deposit_applied' => '60.00',
            ]))
            ->assertSessionHasErrors([
                // $50 taken in, $20 already refunded, so only $30 is left to keep.
                'deposit_applied' => 'The deposit kept cannot exceed the $30.00 still held.',
            ]);

        $this->assertSame('30.00', $contract->fresh()->deposit_applied);
        $this->assertSame($receiptsBefore, Payment::count());
        $this->assertSame(0, AuditLog::where('action', 'deposit.adjusted')->count());
    }

    public function test_only_a_settled_return_can_be_adjusted(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->contract($business);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract))
            ->assertSessionHasErrors([
                'adjust' => 'Only a settled return can be adjusted.',
            ]);

        $this->assertSame(0, AuditLog::where('action', 'deposit.adjusted')->count());
    }

    public function test_a_credit_left_by_an_adjustment_can_be_refunded(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->settledReturn($business, $manager);
        $item = $contract->items()->first();

        // Cutting the damage fee leaves the customer owed $10.
        $this->actingAs($manager)->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
            'damage' => [$item->id => '0'],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === -10.0)
            ->assertSee('owes the customer $10.00')
            ->assertSee(route('rental-agreements.payments.refund-credit', $contract));

        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.refund-credit', $contract), [
                'payment_method' => 'card',
                'reason' => 'Damage fee was charged twice',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $refund = Payment::where('notes', 'like', 'Credit refunded%')->sole();
        $this->assertSame('-10.00', $refund->amount);
        $this->assertSame('card', $refund->payment_method);
        $this->assertSame('payment', $refund->kind);

        // The receipt is a normal ledger row, so money nets to the charges.
        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 0.0)
            ->assertViewHas('paid', fn ($paid) => round($paid, 2) === 120.0)
            ->assertSee($refund->receipt_number)
            ->assertDontSee('owes the customer');

        $audit = AuditLog::where('action', 'payment.refunded')->sole();
        $this->assertSame('10.00', $audit->details['amount']);
        $this->assertSame('Damage fee was charged twice', $audit->details['reason']);

        // Nothing is owed now, so it cannot be paid out twice.
        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.refund-credit', $contract), [
                'payment_method' => 'card',
                'reason' => 'Once more',
            ])
            ->assertSessionHasErrors([
                'credit' => 'This agreement does not owe the customer anything.',
            ]);

        $this->assertSame(1, Payment::where('notes', 'like', 'Credit refunded%')->count());
    }

    /** Settled return, then the deposit kept is cut to $10, so $40 is refunded. */
    private function reducedDeposit(Business $business, User $manager): RentalContract
    {
        $contract = $this->settledReturn($business, $manager);

        $this->actingAs($manager)->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
            'deposit_applied' => '10.00',
        ]))->assertSessionHasNoErrors();

        return $contract->fresh();
    }

    public function test_a_deposit_top_up_puts_back_what_was_refunded(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->reducedDeposit($business, $manager);
        $receiptsBefore = Payment::count();

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee('Deposit top-up')
            ->assertSee('At most $30.00')
            ->assertSee(route('rental-agreements.payments.top-up', $contract));

        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.top-up', $contract), [
                'amount' => '20.00',
                'payment_method' => 'cash',
                'reason' => 'Deposit was refunded twice by mistake',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $topUp = Payment::where('notes', 'like', 'Deposit topped up%')->sole();
        $this->assertSame('20.00', $topUp->amount);
        // Its own kind, so the receipts table says top-up rather than held.
        $this->assertSame('deposit_top_up', $topUp->kind);
        $this->assertSame('Deposit top-up', $topUp->kindLabel());
        $this->assertNotNull($topUp->receipt_number);
        $this->assertSame($receiptsBefore + 1, Payment::count());

        $contract->refresh();
        $this->assertSame('30.00', $contract->deposit_applied);
        $this->assertSame('0.00', $contract->deposit_paid);

        // $50 taken in plus $20 back in, $30 + $40 handed out and kept.
        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('deposit', fn ($deposit) => round($deposit['received'], 2) === 70.0
                && round($deposit['applied'], 2) === 30.0
                && round($deposit['refunded'], 2) === 40.0
                && round($deposit['remaining'], 2) === 0.0)
            // Keeping the extra $20 also covers the shortfall on the fees.
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 0.0)
            // $10 of the refunded money is still not kept, so it can still come back.
            ->assertSee('At most $10.00');

        $audit = AuditLog::where('action', 'deposit.top_up')->sole();
        $this->assertSame('20.00', $audit->details['amount']);
        $this->assertSame('10.00 → 30.00', $audit->details['applied']);
        $this->assertSame('Deposit was refunded twice by mistake', $audit->details['reason']);
    }

    public function test_a_top_up_cannot_exceed_the_deposit_that_was_refunded(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $contract = $this->reducedDeposit($business, $manager);
        $receiptsBefore = Payment::count();

        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.top-up', $contract), [
                'amount' => '40.00',
                'payment_method' => 'cash',
                'reason' => 'Trying to keep too much',
            ])
            ->assertSessionHasErrors([
                'amount' => 'At most $30.00 can be topped up — the deposit refunded and not yet kept.',
            ]);

        $this->assertSame('10.00', $contract->fresh()->deposit_applied);
        $this->assertSame($receiptsBefore, Payment::count());
        $this->assertSame(0, AuditLog::where('action', 'deposit.top_up')->count());
    }

    public function test_a_top_up_needs_a_settled_return_and_a_manager(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $contract = $this->reducedDeposit($business, $manager);

        $payload = [
            'amount' => '10.00',
            'payment_method' => 'cash',
            'reason' => 'Trying it anyway',
        ];

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.top-up', $contract), $payload)
            ->assertForbidden();

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee(route('rental-agreements.payments.top-up', $contract));

        // A live agreement already has its own deposit form, so no top-up here.
        $active = $this->contract($business);

        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.top-up', $active), $payload)
            ->assertSessionHasErrors([
                'top_up' => 'Only a settled return can take a deposit top-up.',
            ]);

        $this->assertSame(0, AuditLog::where('action', 'deposit.top_up')->count());
    }

    public function test_only_a_manager_can_pay_out_a_credit(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $contract = $this->settledReturn($business, $manager);
        $item = $contract->items()->first();

        $this->actingAs($manager)->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
            'damage' => [$item->id => '0'],
        ]));

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.refund-credit', $contract), [
                'payment_method' => 'cash',
                'reason' => 'Trying it anyway',
            ])
            ->assertForbidden();

        $this->assertSame(0, Payment::where('notes', 'like', 'Credit refunded%')->count());

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee(route('rental-agreements.payments.refund-credit', $contract));
    }

    public function test_a_clerk_can_neither_see_nor_use_the_adjustment(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $contract = $this->settledReturn($business, $manager);

        $this->actingAs($clerk)
            ->patch(route('rental-agreements.payments.adjust', $contract), $this->adjust($contract, [
                'late_fee' => '40.00',
            ]))
            ->assertForbidden();

        $this->assertSame('20.00', $contract->fresh()->late_fee);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee(route('rental-agreements.payments.adjust', $contract));

        // The manager does get the form.
        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee(route('rental-agreements.payments.adjust', $contract));
    }
}
