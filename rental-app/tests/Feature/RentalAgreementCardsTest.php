<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RentalAgreementCardsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);

        $this->manager = new User(['name' => 'Mia Manager', 'email' => 'manager@acme.test', 'password' => 'password']);
        $this->manager->role = 'manager';
        $this->manager->business_id = $this->business->id;
        $this->manager->email_verified_at = now();
        $this->manager->save();
    }

    private function clerk(): User
    {
        $clerk = new User(['name' => 'Casey Clerk', 'email' => 'clerk@acme.test', 'password' => 'password']);
        $clerk->role = 'clerk';
        $clerk->business_id = $this->business->id;
        $clerk->email_verified_at = now();
        $clerk->save();

        return $clerk;
    }

    private function agreement(): RentalContract
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
        ]);

        return RentalContract::create([
            'business_id' => $this->business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-24',
            'status' => 'active',
        ]);
    }

    private function holdDeposit(RentalContract $contract, float $amount = 100): void
    {
        app(AgreementLedger::class)->recordDeposit($contract, ['amount' => $amount, 'payment_method' => 'cash'], $this->manager);
    }

    private function markReturned(RentalContract $contract): void
    {
        $this->actingAs($this->manager)
            ->patch(route('rental-agreements.return', $contract), ['returned_on' => '2026-11-22'])
            ->assertSessionHasNoErrors();
    }

    private function show(RentalContract $contract, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk();
    }

    private function settle(RentalContract $contract, float $lateFee = 0): void
    {
        $this->actingAs($this->manager)
            ->post(route('rental-agreements.payments.settle', $contract), [
                'returned_on' => '2026-11-22',
                'late_fee' => $lateFee,
                'refund_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();
    }

    /** @param  list<string>  $visible */
    private function assertCards(TestResponse $response, array $visible): void
    {
        foreach (['Return & settle deposit', 'Settle deposit', 'Cancel & refund', 'Refund held money', 'Close without deposit', 'Adjust settled return'] as $card) {
            in_array($card, $visible, true)
                ? $response->assertSee($card)
                : $response->assertDontSee($card);
        }
    }

    public function test_a_returned_agreement_holding_a_deposit_offers_settlement_only(): void
    {
        $contract = $this->agreement();
        $this->holdDeposit($contract);
        $this->markReturned($contract);

        $this->assertCards($this->show($contract), ['Settle deposit']);
    }

    public function test_the_close_without_deposit_card_needs_an_agreement_holding_no_deposit(): void
    {
        $held = $this->agreement();
        $this->holdDeposit($held);

        $this->assertCards($this->show($held), ['Return & settle deposit', 'Cancel & refund']);

        $bare = $this->agreement();

        $this->assertCards($this->show($bare), ['Return & settle deposit', 'Close without deposit']);
    }

    public function test_the_adjust_card_appears_only_after_the_deposit_is_settled(): void
    {
        $contract = $this->agreement();
        $this->holdDeposit($contract);
        $this->markReturned($contract);

        $this->assertCards($this->show($contract), ['Settle deposit']);

        $this->settle($contract);

        $this->assertCards($this->show($contract), ['Adjust settled return']);
    }

    public function test_a_cancelled_agreement_shows_no_return_cards(): void
    {
        $contract = $this->agreement();
        $this->holdDeposit($contract);

        $this->assertCards($this->show($contract), ['Return & settle deposit', 'Cancel & refund']);

        $this->actingAs($this->manager)
            ->patch(route('rental-agreements.cancel', $contract), ['refund_method' => 'cash', 'cancellation_reason' => 'Customer changed plans'])
            ->assertSessionHasNoErrors();

        $this->assertCards($this->show($contract), []);
    }

    public function test_an_already_cancelled_agreement_can_still_refund_a_held_deposit(): void
    {
        $contract = $this->agreement();
        $this->holdDeposit($contract);

        // An older cancellation, or a write from outside the cancel flow.
        $contract->update(['status' => 'cancelled']);

        $this->assertCards($this->show($contract), ['Refund held money']);

        $this->actingAs($this->manager)
            ->patch(route('rental-agreements.cancel', $contract), ['refund_method' => 'card', 'cancellation_reason' => 'Booked in error'])
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('cancelled', $contract->status);
        $this->assertSame('0.00', $contract->deposit_paid);
        $this->assertSame(
            -100.0,
            (float) $contract->payments()->where('kind', 'deposit_refund')->sum('amount')
        );

        $this->assertCards($this->show($contract), []);
    }

    public function test_a_clerk_sees_no_adjust_card_on_a_settled_return(): void
    {
        $contract = $this->agreement();
        $this->holdDeposit($contract);
        $this->markReturned($contract);
        $this->settle($contract);

        $this->assertCards($this->show($contract, $this->clerk()), []);

        // The manager does keep it, so the assertion above is about the gate.
        $this->assertCards($this->show($contract), ['Adjust settled return']);
    }

    public function test_a_return_settled_by_applying_the_deposit_to_fees_also_offers_adjust(): void
    {
        $contract = $this->agreement();
        $this->holdDeposit($contract, 100);
        $this->markReturned($contract);
        $this->settle($contract, lateFee: 100);

        $contract->refresh();

        $this->assertSame(0.0, app(AgreementLedger::class)->depositSummary($contract)['remaining']);
        $this->assertSame(0, $contract->payments()->where('kind', 'deposit_refund')->count());
        $this->assertSame(100.0, (float) $contract->deposit_applied);

        $this->assertCards($this->show($contract), ['Adjust settled return']);
    }
}
