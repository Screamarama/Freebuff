<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_placeholders_render_with_tenant_and_customer_details(): void
    {
        $business = Business::create([
            'name' => 'Acme Rentals',
            'address' => '12 Dock Road, Springfield',
            'phone' => '555-0100',
            'email' => 'info@acme.test',
            'currency' => 'USD',
            'state' => 'Illinois',
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-0001',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
            'address' => '5 Elm Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'postal_code' => '62701',
        ]);

        $template = new ContractTemplate(ContractTemplate::defaultBoilerplate());

        $rendered = $template->render([
            'business_id' => $business->id,
            'customer_name' => $customer->full_name,
            'customer_address' => $customer->full_address,
            'rented_items' => '1. 2 × Generator — 4 days @ $25.00',
            'contract_number' => 'RA-TEST',
            'contract_date' => 'October 30, 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'total_amount' => '189.99',
        ]);

        $this->assertSame('RENTAL AGREEMENT', $rendered['title']);
        $this->assertStringContainsString('1. THE PARTIES.', $rendered['body']);
        $this->assertStringContainsString('on October 30, 2026 by and between', $rendered['body']);
        $this->assertStringContainsString('Owner: Acme Rentals located at 12 Dock Road, Springfield', $rendered['body']);
        $this->assertStringContainsString('in the State of Illinois.', $rendered['body']);
        $this->assertStringContainsString('Renter: Jane Doe located at 5 Elm Street, Springfield, IL 62701', $rendered['body']);
        $this->assertStringContainsString('3. TERM', $rendered['body']);
        $this->assertStringNotContainsString('(check one)', $rendered['body']);
        $this->assertStringContainsString('Hereinafter known as the “Term.”', $rendered['body']);
        $this->assertStringContainsString('4. USE OF EQUIPMENT', $rendered['body']);
        $this->assertStringContainsString('for unlawful purposes can result in the termination of this Agreement.', $rendered['body']);
        $this->assertStringContainsString("5. RENTER'S RESPONSIBILITY", $rendered['body']);
        $this->assertStringContainsString('except for common wear and tear', $rendered['body']);
        $this->assertStringContainsString('6. REPAIRS', $rendered['body']);
        $this->assertStringContainsString('credited for future use of the Equipment', $rendered['body']);
        $this->assertStringContainsString('7. DEFAULT', $rendered['body']);
        $this->assertStringContainsString('take possession of the Equipment', $rendered['body']);
        $this->assertStringContainsString('8. INDEMNIFICATION', $rendered['body']);
        $this->assertStringContainsString('hold the Owner harmless', $rendered['body']);
        $this->assertStringContainsString('9. ENTIRE AGREEMENT', $rendered['body']);
        $this->assertStringContainsString('supersedes all prior agreements', $rendered['body']);
        $this->assertStringContainsString('10. GOVERNING LAW', $rendered['body']);
        $this->assertStringContainsString('11. BUSINESS DETAILS', $rendered['body']);
        $this->assertStringContainsString('14. SIGNATURES', $rendered['body']);
        $this->assertStringNotContainsString('{{', $rendered['body']);
    }

    public function test_missing_customer_address_keeps_visible_placeholder(): void
    {
        $template = new ContractTemplate(ContractTemplate::defaultBoilerplate());

        $rendered = $template->render(['business_id' => null]);

        $this->assertStringContainsString('{{customer_address}}', $rendered['body']);
    }

    private function lineItem(string $name, int $quantity, string $rateType, int $duration, float $price, ?string $serial = null, ?int $productId = null): RentalItem
    {
        $item = new RentalItem([
            'product_id' => $productId,
            'quantity' => $quantity,
            'rate_type' => $rateType,
            'duration' => $duration,
            'unit_price' => $price,
            'serial_number' => $serial,
        ]);
        $item->setRelation('product', new Product(['name' => $name]));

        return $item;
    }

    public function test_serialized_lines_list_each_unit_with_its_serial(): void
    {
        $contract = new RentalContract;
        $contract->setRelation('items', collect([
            $this->lineItem('Excavator', 1, 'day', 3, 45.00, 'SN-001'),
            $this->lineItem('Excavator', 1, 'day', 3, 45.00, 'SN-002'),
        ]));

        $block = $contract->equipmentBlock();

        $this->assertNull($block['appendix']);
        $this->assertStringContainsString('1. 1 × Excavator (SN: SN-001) — 3 days @ $45.00', $block['inline']);
        $this->assertStringContainsString('2. 1 × Excavator (SN: SN-002) — 3 days @ $45.00', $block['inline']);
    }

    public function test_non_serialized_lines_group_with_summed_quantity(): void
    {
        $contract = new RentalContract;
        $contract->setRelation('items', collect([
            $this->lineItem('Generator', 2, 'day', 4, 25.00),
            $this->lineItem('Generator', 1, 'day', 4, 25.00),
            $this->lineItem('Generator', 1, 'week', 1, 100.00),
        ]));

        $block = $contract->equipmentBlock();

        $this->assertNull($block['appendix']);
        $this->assertStringContainsString('1. 3 × Generator — 4 days @ $25.00', $block['inline']);
        $this->assertStringContainsString('2. 1 × Generator — 1 week @ $100.00', $block['inline']);
    }

    public function test_serial_number_can_be_saved_on_a_line_item(): void
    {
        $business = Business::create([
            'name' => 'Acme Rentals',
            'phone' => '555-0100',
            'email' => 'info@acme.test',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $admin = new User(['name' => 'Acme Admin', 'email' => 'serial@acme.test', 'password' => 'password']);
        $admin->role = 'admin';
        $admin->business_id = $business->id;
        $admin->email_verified_at = now();
        $admin->save();

        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-0002',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);
        $product = Product::create([
            'business_id' => $business->id,
            'equipment_number' => 'EXC-1',
            'name' => 'Excavator',
            'product_type' => 'rental',
        ]);
        $contract = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-SN1',
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
        ]);
        $item = $contract->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 45.00,
            'duration' => 3,
            'subtotal' => 135.00,
        ]);

        $this->actingAs($admin);
        $this->patch(route('rental-agreements.items.update', [$contract, $item]), [
            'quantity' => 1,
            'unit_price' => 45.00,
            'duration' => 3,
            'serial_number' => 'SN-001',
        ])->assertRedirect();

        $this->assertSame('SN-001', $item->fresh()->serial_number);
    }

    public function test_equipment_lists_inline_for_five_or_fewer_items(): void
    {
        $contract = new RentalContract;
        $contract->setRelation('items', collect([
            $this->lineItem('Excavator', 2, 'day', 3, 45.00),
            $this->lineItem('Helmet', 1, 'sale', 1, 15.50),
        ]));

        $block = $contract->equipmentBlock();

        $this->assertNull($block['appendix']);
        $this->assertStringContainsString('1. 2 × Excavator — 3 days @ $45.00', $block['inline']);
        $this->assertStringContainsString('2. 1 × Helmet — Sale @ $15.50', $block['inline']);
    }

    public function test_equipment_moves_to_appendix_a_when_more_than_five_items(): void
    {
        $contract = new RentalContract;
        $items = [];
        for ($i = 1; $i <= 6; $i++) {
            $items[] = $this->lineItem('Tool ' . $i, 1, 'day', 1, 10.00, null, $i);
        }
        $contract->setRelation('items', collect($items));

        $block = $contract->equipmentBlock();

        $this->assertSame('See Appendix A for the full equipment list.', $block['inline']);
        $this->assertStringStartsWith('APPENDIX A — EQUIPMENT LIST', $block['appendix']);
        $this->assertStringContainsString('6. 1 × Tool 6 — 1 day @ $10.00', $block['appendix']);
    }

    public function test_equipment_section_renders_in_boilerplate(): void
    {
        $defaults = ContractTemplate::defaultBoilerplate();
        $this->assertStringContainsString('2. EQUIPMENT', $defaults['body']);
        $this->assertContains('rented_items', ContractTemplate::placeholders());

        $template = new ContractTemplate($defaults);
        $rendered = $template->render([
            'business_id' => null,
            'business_name' => 'Acme Rentals',
            'business_address' => '12 Dock Road',
            'company_state' => 'Illinois',
            'customer_name' => 'Jane Doe',
            'customer_address' => '5 Elm Street',
            'rented_items' => '1. 2 × Excavator — 3 days @ $45.00',
            'contract_number' => 'RA-TEST',
            'contract_date' => 'October 30, 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'total_amount' => '189.99',
        ]);

        $this->assertStringContainsString('2. EQUIPMENT', $rendered['body']);
        $this->assertStringContainsString('1. 2 × Excavator', $rendered['body']);
        $this->assertStringNotContainsString('{{', $rendered['body']);
    }

    public function test_new_tenant_form_starts_from_the_common_header(): void
    {
        $defaults = ContractTemplate::defaultBoilerplate();

        $this->assertSame('RENTAL AGREEMENT', $defaults['title']);
        $this->assertContains('contract_date', ContractTemplate::placeholders());
        $this->assertContains('customer_address', ContractTemplate::placeholders());
    }
}
