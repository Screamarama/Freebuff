<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementCancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $manager;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);
        $this->manager = $this->user('manager@acme.test', 'manager');
        $this->clerk = $this->user('clerk@acme.test', 'clerk');
    }

    private function user(string $email, string $role): User
    {
        $user = new User(['name' => ucfirst($role), 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $this->business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function agreementWithDeposit(): RentalContract
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
        ]);

        $contract = RentalContract::create([
            'business_id' => $this->business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-24',
            'status' => 'active',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
        ]);

        app(AgreementLedger::class)->recordDeposit(
            $contract,
            ['amount' => 50, 'payment_method' => 'cash'],
            $this->manager,
        );

        return $contract;
    }

    public function test_a_clerk_cannot_cancel_or_refund_an_agreement(): void
    {
        $contract = $this->agreementWithDeposit();

        $this->actingAs($this->clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee('Cancel & refund')
            ->assertDontSee('Cancel agreement');

        $this->actingAs($this->clerk)
            ->patch(route('rental-agreements.cancel', $contract), [
                'refund_method' => 'cash',
                'cancellation_reason' => 'Clerk trying to cancel',
            ])
            ->assertForbidden();

        $contract->refresh();

        $this->assertSame('active', $contract->status);
        $this->assertSame('100.00', $contract->total_amount);
        $this->assertSame(0, $contract->payments()->where('kind', 'deposit_refund')->count());
        $this->assertSame(0, AuditLog::where('action', 'agreement.cancelled')->count());
    }

    public function test_a_manager_can_cancel_and_the_reason_is_audited(): void
    {
        $contract = $this->agreementWithDeposit();

        $this->actingAs($this->manager)
            ->patch(route('rental-agreements.cancel', $contract), [
                'refund_method' => 'cash',
                'cancellation_reason' => 'Customer changed plans',
            ])
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('cancelled', $contract->status);
        $this->assertSame('0.00', $contract->total_amount);
        $this->assertSame('Customer changed plans', $contract->cancellation_reason);

        $this->actingAs($this->manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee('Customer changed plans');

        $log = AuditLog::where('action', 'agreement.cancelled')->sole();

        $this->assertSame('Customer changed plans', $log->details['reason']);
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame('50.00', $log->details['deposit_refunded']);
    }

    public function test_a_blank_cancellation_reason_shows_the_required_error_under_the_field(): void
    {
        $contract = $this->agreementWithDeposit();

        $response = $this->actingAs($this->manager)
            ->from(route('rental-agreements.show', $contract))
            ->followingRedirects()
            ->patch(route('rental-agreements.cancel', $contract), [
                'refund_method' => 'cash',
                'cancellation_reason' => '',
            ])
            ->assertOk()
            ->assertSee('The cancellation reason field is required.')
            // The message belongs to the Reason input, not some other form.
            ->assertSeeInOrder([
                'id="cancel_reason"',
                'The cancellation reason field is required.',
            ]);

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'agreement.cancelled')->count());

    }
}
