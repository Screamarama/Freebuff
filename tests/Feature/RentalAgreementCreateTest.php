<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalAgreementCreateTest extends TestCase
{
    use RefreshDatabase;

    private function business(): Business
    {
        return Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);
    }

    private function manager(Business $business): User
    {
        $user = new User(['name' => 'Mia Manager', 'email' => 'manager@acme.test', 'password' => 'password']);
        $user->role = 'manager';
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function customer(Business $business, string $first = 'Jane'): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => $first,
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
        ]);
    }

    public function test_the_agreements_page_links_to_the_new_agreement_form(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $this->actingAs($manager)
            ->get(route('rental-agreements.index'))
            ->assertOk()
            ->assertSee(route('rental-agreements.create'))
            ->assertSee('New agreement');
    }

    public function test_a_manager_can_start_an_agreement_without_a_reservation(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $this->actingAs($manager)
            ->get(route('rental-agreements.create'))
            ->assertOk()
            ->assertSee($customer->full_name)
            ->assertSee('New customer')
            ->assertSee(route('rental-agreements.create.customer'))
            ->assertDontSee('<x-', false);

        $response = $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'notes' => 'Phone booking',
            ]);

        $contract = RentalContract::sole();

        $response->assertRedirect(route('rental-agreements.edit', $contract));

        $this->assertSame($business->id, $contract->business_id);
        $this->assertSame($customer->id, $contract->customer_id);
        $this->assertSame($manager->id, $contract->clerk_id);
        $this->assertSame('active', $contract->status);
        $this->assertNull($contract->reservation_id);
        $this->assertSame('2026-11-20', $contract->start_date->format('Y-m-d'));
        $this->assertSame('2026-11-24', $contract->end_date->format('Y-m-d'));
        $this->assertSame('Phone booking', $contract->notes);
        $this->assertStringStartsWith('RA-', $contract->contract_number);
    }

    public function test_rental_and_sale_items_can_be_added_while_creating_an_agreement(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);

        $sale = Product::create([
            'business_id' => $business->id,
            'name' => 'Safety gloves',
            'product_type' => 'sale',
            'sale_price' => 25.00,
            'stock_quantity' => 5,
            'available_quantity' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get(route('rental-agreements.create'))
            ->assertOk()
            ->assertSee('Items (optional)')
            ->assertSee('Compressor')
            ->assertSee('Safety gloves');

        $response = $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'rental' => [[
                    'product_id' => $rental->id,
                    'quantity' => 1,
                    'rate_type' => 'day',
                    'duration' => 3,
                ]],
                'sale' => [[
                    'product_id' => $sale->id,
                    'quantity' => 2,
                ]],
            ]);

        $contract = RentalContract::sole();
        $response->assertRedirect(route('rental-agreements.edit', $contract))->assertSessionHasNoErrors();

        $this->assertCount(2, $contract->items);

        $rentalItem = $contract->items->firstWhere('product_id', $rental->id);
        $saleItem = $contract->items->firstWhere('product_id', $sale->id);

        $this->assertSame('day', $rentalItem->rate_type);
        $this->assertSame(3, $rentalItem->duration);
        $this->assertEquals(30.0, (float) $rentalItem->subtotal);

        $this->assertSame('sale', $saleItem->rate_type);
        $this->assertEquals(50.0, (float) $saleItem->subtotal);
        $this->assertEquals(80.0, (float) $contract->subtotal);
    }

    public function test_several_rental_and_sale_lines_can_be_added_while_creating(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $compressor = $this->product($business);

        $generator = Product::create([
            'business_id' => $business->id,
            'name' => 'Generator',
            'product_type' => 'rental',
            'rental_price_day' => 20.00,
            'stock_quantity' => 1,
            'available_quantity' => 1,
            'is_active' => true,
        ]);

        $gloves = Product::create([
            'business_id' => $business->id,
            'name' => 'Safety gloves',
            'product_type' => 'sale',
            'sale_price' => 25.00,
            'stock_quantity' => 5,
            'available_quantity' => 5,
            'is_active' => true,
        ]);

        $helmet = Product::create([
            'business_id' => $business->id,
            'name' => 'Hard hat',
            'product_type' => 'sale',
            'sale_price' => 15.00,
            'stock_quantity' => 5,
            'available_quantity' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'rental' => [
                    [
                        'product_id' => $compressor->id,
                        'quantity' => 1,
                        'rate_type' => 'day',
                        'duration' => 2,
                    ],
                    [
                        'product_id' => $generator->id,
                        'quantity' => 1,
                        'rate_type' => 'day',
                        'duration' => 4,
                    ],
                ],
                'sale' => [
                    [
                        'product_id' => $gloves->id,
                        'quantity' => 2,
                    ],
                    [
                        'product_id' => $helmet->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $contract = RentalContract::sole();

        $this->assertCount(4, $contract->items);
        $this->assertEquals(165.0, (float) $contract->subtotal);

        foreach ([$compressor, $generator, $gloves, $helmet] as $product) {
            $this->assertTrue($contract->items->contains('product_id', $product->id));
        }
    }

    public function test_item_errors_stay_with_their_row_after_rows_are_removed(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);

        // Index 1 is what remains after the browser removes row 0.
        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'rental' => [
                    1 => [
                        'product_id' => $rental->id,
                        'quantity' => 2,
                        'rate_type' => 'day',
                        'duration' => 1,
                    ],
                ],
            ])
            ->assertSessionHasErrors('rental.1.quantity')
            ->assertSessionHasInput('rental.1.product_id', $rental->id);

        $this->assertDatabaseCount('rental_contracts', 0);
    }

    public function test_a_rental_line_cannot_be_longer_than_the_agreement_window(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);

        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'rental' => [[
                    'product_id' => $rental->id,
                    'quantity' => 1,
                    'rate_type' => 'day',
                    'duration' => 6,
                ]],
            ])
            ->assertSessionHasErrors('rental.0.duration');

        $this->assertDatabaseCount('rental_contracts', 0);
    }

    public function test_a_rental_line_defaults_to_the_agreement_window(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);

        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'rental' => [[
                    'product_id' => $rental->id,
                    'quantity' => 1,
                    'rate_type' => 'day',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $item = RentalItem::sole();

        $this->assertSame(5, $item->duration);
        $this->assertEquals(50.0, (float) $item->subtotal);
    }

    public function test_sale_lines_cannot_exceed_stock(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $sale = Product::create([
            'business_id' => $business->id,
            'name' => 'Safety gloves',
            'product_type' => 'sale',
            'sale_price' => 25.00,
            'stock_quantity' => 2,
            'available_quantity' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'sale' => [
                    ['product_id' => $sale->id, 'quantity' => 1],
                    ['product_id' => $sale->id, 'quantity' => 2],
                ],
            ])
            ->assertSessionHasErrors('sale.1.quantity');

        $this->assertDatabaseCount('rental_contracts', 0);
    }

    public function test_updating_a_sale_quantity_cannot_exceed_stock(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $contract = $this->contract($business, $customer);

        $sale = Product::create([
            'business_id' => $business->id,
            'name' => 'Safety gloves',
            'product_type' => 'sale',
            'sale_price' => 25.00,
            'stock_quantity' => 3,
            'available_quantity' => 3,
            'is_active' => true,
        ]);

        $item = $contract->items()->create([
            'product_id' => $sale->id,
            'quantity' => 1,
            'rate_type' => 'sale',
            'unit_price' => 25.00,
            'duration' => 1,
            'subtotal' => 25.00,
        ]);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.items.update', [$contract, $item]), [
                'quantity' => 4,
                'unit_price' => 25.00,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(1, (int) $item->fresh()->quantity);
    }

    public function test_the_availability_endpoint_reports_rental_and_sale_stock(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $rental = $this->product($business);

        $sale = Product::create([
            'business_id' => $business->id,
            'name' => 'Safety gloves',
            'product_type' => 'sale',
            'sale_price' => 25.00,
            'stock_quantity' => 4,
            'available_quantity' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->getJson(route('rental-agreements.availability', [
                'product_id' => $rental->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
            ]))
            ->assertOk()
            ->assertJson(['available' => 1, 'tracked' => true, 'unit' => 'rental']);

        $this->actingAs($manager)
            ->getJson(route('rental-agreements.availability', [
                'product_id' => $sale->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
            ]))
            ->assertOk()
            ->assertJson(['available' => 3, 'tracked' => true, 'unit' => 'sale']);
    }

    public function test_availability_on_an_existing_contract_excludes_its_own_lines(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);
        $contract = $this->contract($business, $customer);

        $contract->items()->create([
            'product_id' => $rental->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 10.00,
            'duration' => 5,
            'subtotal' => 50.00,
        ]);

        $this->actingAs($manager)
            ->getJson(route('rental-agreements.availability', [
                'product_id' => $rental->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'contract_id' => $contract->id,
            ]))
            ->assertOk()
            ->assertJson(['available' => 0]);
    }

    public function test_adding_a_line_longer_than_the_contract_window_is_rejected(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);
        $contract = $this->contract($business, $customer);

        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $rental->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 6,
            ])
            ->assertSessionHasErrors('duration');

        $this->assertDatabaseCount('rental_items', 0);
    }

    public function test_the_availability_endpoint_hides_another_tenants_product(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $other = Business::create(['name' => 'Other Rentals', 'currency' => 'USD', 'is_active' => true]);
        $foreign = Product::create([
            'business_id' => $other->id,
            'name' => 'Foreign item',
            'product_type' => 'rental',
            'rental_price_day' => 5.00,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->getJson(route('rental-agreements.availability', [
                'product_id' => $foreign->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
            ]))
            ->assertNotFound();
    }

    public function test_an_unavailable_rental_item_blocks_creating_the_agreement(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $rental = $this->product($business);

        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
                'rental' => [[
                    'product_id' => $rental->id,
                    'quantity' => 2,
                    'rate_type' => 'day',
                    'duration' => 1,
                ]],
            ])
            ->assertSessionHasErrors('rental.0.quantity');

        $this->assertDatabaseCount('rental_contracts', 0);
    }

    private function product(Business $business): Product
    {
        return Product::create([
            'business_id' => $business->id,
            'name' => 'Compressor',
            'product_type' => 'rental',
            'rental_price_day' => 10.00,
            'stock_quantity' => 1,
            'available_quantity' => 1,
            'is_active' => true,
        ]);
    }

    public function test_adding_a_customer_from_the_agreement_form_returns_with_them_selected_and_the_draft_kept(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $rental = $this->product($business);

        $this->actingAs($manager)
            ->post(route('rental-agreements.create.customer'), [
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-05',
                'notes' => 'Deliver to the yard',
                'rental' => [[
                    'product_id' => $rental->id,
                    'quantity' => 2,
                    'rate_type' => 'day',
                    'duration' => 4,
                ]],
            ])
            ->assertRedirect(route('customers.create', ['from_agreement' => 1]));

        $this->actingAs($manager)
            ->post(route('customers.store'), [
                'customer_code' => 'C-NEW9',
                'first_name' => 'Nina',
                'last_name' => 'New',
                'phone' => '555-0199',
                'account_type' => 'cash',
                'return_to_agreement' => '1',
            ])
            ->assertRedirect(route('rental-agreements.create', ['resume' => 1]))
            ->assertSessionHasNoErrors();

        $customer = Customer::where('customer_code', 'C-NEW9')->sole();

        $response = $this->actingAs($manager)
            ->get(route('rental-agreements.create', ['resume' => 1]))
            ->assertOk()
            ->assertSee('Customer created and selected.')
            ->assertSee('Nina New (C-NEW9)')
            ->assertSee('value="2026-12-01"', false)
            ->assertSee('value="2026-12-05"', false)
            ->assertSee('Deliver to the yard')
            ->assertSee('value="'.$rental->id.'" selected', false)
            ->assertSee('name="rental[0][quantity]"', false)
            ->assertSee('value="2"', false)
            ->assertSee('value="4"', false);

        $this->assertMatchesRegularExpression(
            '/value="'.$customer->id.'"\s+selected/',
            $response->getContent()
        );
    }

    public function test_creating_a_customer_normally_does_not_use_an_old_agreement_draft(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $this->actingAs($manager)
            ->withSession(['agreement_draft' => [
                'customer_id' => null,
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-05',
                'notes' => 'Abandoned draft',
                'saved_at' => now()->timestamp,
            ]])
            ->post(route('customers.store'), [
                'customer_code' => 'C-NORMAL1',
                'first_name' => 'Normal',
                'last_name' => 'Customer',
                'phone' => '555-0111',
                'account_type' => 'cash',
            ])
            ->assertRedirect(route('customers.index'));

        $this->assertTrue(Customer::where('customer_code', 'C-NORMAL1')->exists());
    }

    public function test_an_abandoned_draft_is_not_restored_on_a_normal_create_visit(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $this->actingAs($manager)
            ->withSession(['agreement_draft' => [
                'customer_id' => null,
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-05',
                'notes' => 'Abandoned draft',
                'saved_at' => now()->timestamp,
            ]])
            ->get(route('rental-agreements.create'))
            ->assertOk()
            ->assertDontSee('Abandoned draft');

        $this->assertFalse(session()->has('agreement_draft'));
    }

    public function test_cancelling_a_customer_started_from_an_agreement_clears_the_draft(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $this->actingAs($manager)
            ->withSession(['agreement_draft' => [
                'customer_id' => null,
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-05',
                'notes' => 'Abandon me',
                'saved_at' => now()->timestamp,
            ]])
            ->get(route('customers.create.cancel'))
            ->assertRedirect(route('customers.index'));

        $this->assertFalse(session()->has('agreement_draft'));
    }

    public function test_adding_items_generates_the_tenants_terms(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business);
        $contract = $this->contract($business, $customer);

        $this->assertNull($contract->terms_conditions);

        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $product->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertSessionHasNoErrors();

        $terms = $contract->fresh()->terms_conditions;

        $this->assertNotNull($terms);
        $this->assertStringContainsString($contract->contract_number, $terms);
        $this->assertStringContainsString($product->name, $terms);
    }

    public function test_an_agreement_can_be_marked_returned_with_its_return_date(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business);
        $contract = $this->contract($business, $customer);

        $contract->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 10.00,
            'duration' => 2,
            'subtotal' => 20.00,
        ]);

        $availability = app(\App\Services\Availability::class);

        // While open, the agreement holds the only unit.
        $this->assertSame(0, $availability->availableFor($product, $contract->start_date, $contract->end_date));

        $this->actingAs($manager)
            ->patch(route('rental-agreements.return', $contract), [
                'returned_on' => '2026-11-22',
            ])
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('returned', $contract->status);
        $this->assertSame('2026-11-22', $contract->actual_return_date->format('Y-m-d'));
        $this->assertSame(1, $availability->availableFor($product, $contract->start_date, $contract->end_date));
    }

    public function test_a_returned_agreement_can_still_settle_its_deposit(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $contract = $this->contract($business, $customer);

        $ledger = app(\App\Services\AgreementLedger::class);
        $ledger->recordDeposit($contract, ['amount' => 100, 'payment_method' => 'cash'], $manager);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.return', $contract), ['returned_on' => '2026-11-22'])
            ->assertSessionHasNoErrors();

        // Returning without settling must leave the refund reachable.
        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.settle', $contract), [
                'returned_on' => '2026-11-22',
                'late_fee' => 0,
                'refund_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('returned', $contract->status);
        $this->assertSame(0.0, $ledger->depositSummary($contract)['remaining']);
        $this->assertSame(
            -100.0,
            (float) $contract->payments()->where('kind', 'deposit_refund')->sum('amount')
        );
    }

    public function test_a_returned_agreement_cannot_settle_its_deposit_twice(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $contract = $this->contract($business, $customer);

        $ledger = app(\App\Services\AgreementLedger::class);
        $ledger->recordDeposit($contract, ['amount' => 100, 'payment_method' => 'cash'], $manager);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.return', $contract), ['returned_on' => '2026-11-22'])
            ->assertSessionHasNoErrors();

        $settle = [
            'returned_on' => '2026-11-22',
            'late_fee' => 0,
            'refund_method' => 'cash',
        ];

        $this->actingAs($manager)
            ->post(route('rental-agreements.payments.settle', $contract), $settle)
            ->assertSessionHasNoErrors();

        // The refund is gone; settling again must not pay it a second time.
        $this->actingAs($manager)
            ->from(route('rental-agreements.show', $contract))
            ->post(route('rental-agreements.payments.settle', $contract), $settle)
            ->assertSessionHasErrors(['late_fee' => 'This agreement is already settled.']);

        $this->assertSame(
            -100.0,
            (float) $contract->payments()->where('kind', 'deposit_refund')->sum('amount')
        );
        $this->assertSame(1, $contract->payments()->where('kind', 'deposit_refund')->count());
        $this->assertSame('returned', $contract->fresh()->status);
    }

    public function test_removing_the_last_item_clears_the_generated_terms(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business);
        $contract = $this->contract($business, $customer);

        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $product->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($contract->fresh()->terms_conditions);

        $item = $contract->items()->sole();

        $this->actingAs($manager)
            ->delete(route('rental-agreements.items.destroy', [$contract, $item]))
            ->assertSessionHasNoErrors();

        $this->assertNull($contract->fresh()->terms_conditions);
    }

    public function test_an_agreement_can_be_cancelled(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $contract = $this->contract($business, $customer);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.cancel', $contract), ['cancellation_reason' => 'Customer changed plans'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $contract->fresh()->status);
    }

    public function test_a_closed_agreement_cannot_be_closed_again(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $contract = $this->contract($business, $customer, ['status' => 'returned']);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.cancel', $contract))
            ->assertStatus(422);

        $this->assertSame('returned', $contract->fresh()->status);
    }

    private function contract(Business $business, Customer $customer, array $attributes = []): RentalContract
    {
        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-24',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    public function test_the_end_date_must_not_be_before_the_start_date(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $this->actingAs($manager)
            ->from(route('rental-agreements.create'))
            ->post(route('rental-agreements.store'), [
                'customer_id' => $customer->id,
                'start_date' => '2026-11-24',
                'end_date' => '2026-11-20',
            ])
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, RentalContract::count());
    }

    public function test_another_tenants_customer_cannot_be_used(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);

        $other = $this->business();
        $otherCustomer = $this->customer($other, 'Olive');

        $this->actingAs($manager)
            ->post(route('rental-agreements.store'), [
                'customer_id' => $otherCustomer->id,
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-24',
            ])
            ->assertStatus(404);

        $this->assertSame(0, RentalContract::count());
    }
}
