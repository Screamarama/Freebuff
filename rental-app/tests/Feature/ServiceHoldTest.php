<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\EquipmentModel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceHoldTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(string $email = 'admin@tenant.test'): User
    {
        $tenant = Business::create([
            'name' => 'Test Rental Business',
            'phone' => '555-0100',
            'email' => 'info@testrental.test',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $admin = new User(['name' => 'Tenant Admin', 'email' => $email, 'password' => 'password']);
        $admin->role = 'admin';
        $admin->business_id = $tenant->id;
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function staff(User $admin, string $role, string $email): User
    {
        $user = new User(['name' => ucfirst($role), 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $admin->business_id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function heldUnit(User $user, array $overrides = []): Product
    {
        return Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'fleet_status' => Product::FLEET_SERVICE_HOLD,
            'is_active' => false,
            'equipment_number' => 'PO-R-0001-1',
            'name' => 'CAT 320 GC excavator',
            'cost_price' => 24000,
            'purchase_order' => 'PO-R-0001',
            'purchase_date' => '2026-11-25',
            'stock_quantity' => 1,
            ...$overrides,
        ]);
    }

    private function commitPayload(array $overrides = []): array
    {
        return [
            'equipment_number' => 'EQ-100',
            'serial_number' => 'CAT0320GC12345',
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'category' => 'Earthmoving',
            'rental_price_hour' => 75,
            'rental_price_day' => 450,
            'rental_price_week' => 2400,
            'rental_price_month' => 8000,
            'deposit_amount' => 500,
            'used_sale_price' => 18000,
            'useful_life_months' => 48,
            'salvage_value' => 4800,
            'depreciation_started_on' => '2026-11-25',
            ...$overrides,
        ];
    }

    public function test_a_held_unit_is_listed_but_not_in_the_fleet(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertSee('CAT 320 GC excavator')
            ->assertSee('PO-R-0001');

        $this->actingAs($user)
            ->get('/products')
            ->assertOk()
            ->assertDontSee('CAT 320 GC excavator');

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('Commit to rental fleet');
    }

    public function test_committing_a_unit_creates_its_catalogue_entry_and_activates_it(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertRedirect(route('service-hold.index'))
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $model = EquipmentModel::where('business_id', $user->business_id)->firstOrFail();

        $this->assertSame(Product::FLEET_ACTIVE, $unit->fleet_status);
        $this->assertTrue($unit->is_active);
        $this->assertSame($model->id, $unit->equipment_model_id);
        $this->assertSame('EQ-100', $unit->equipment_number);
        $this->assertSame('Caterpillar', $unit->manufacturer);
        $this->assertSame('450.00', $unit->rental_price_day);
        $this->assertSame('18000.00', $unit->used_sale_price);
        $this->assertSame(48, $unit->useful_life_months);
        $this->assertSame('4800.00', $unit->salvage_value);

        // The unit now depreciates: (24000 - 4800) / 48 per month.
        $this->assertSame(400.0, $unit->monthlyDepreciation());
        $this->assertSame(21600.0, $unit->bookValue(Carbon::parse('2027-05-25')));

        // The catalogue entry carries the terms entered at commit time.
        $this->assertSame('Excavator', $model->name);
        $this->assertSame('Caterpillar', $model->manufacturer);
        $this->assertSame('450.00', $model->rental_price_day);
        $this->assertSame(48, $model->useful_life_months);
        $this->assertSame('4800.00', $model->salvage_value);
        $this->assertSame('24000.00', $model->default_cost_price);
    }

    public function test_units_with_the_same_spec_share_one_catalogue_model(): void
    {
        $user = $this->tenantUser();
        $first = $this->heldUnit($user);
        $second = $this->heldUnit($user, ['equipment_number' => 'PO-R-0001-2']);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $first), $this->commitPayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('service-hold.commit', $second), $this->commitPayload([
                'equipment_number' => 'EQ-101',
                'serial_number' => 'CAT0320GC99999',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EquipmentModel::query()->count());
        $this->assertSame(
            $first->fresh()->equipment_model_id,
            $second->fresh()->equipment_model_id
        );
        $this->assertSame(2, EquipmentModel::firstOrFail()->products()->count());
    }

    public function test_setup_names_the_existing_model_the_unit_would_join_and_hides_ignored_rates(): void
    {
        $user = $this->tenantUser();

        EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'is_active' => true,
        ]);

        $unit = $this->heldUnit($user, [
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
        ]);

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('Joins existing model')
            ->assertSee('Caterpillar 320 GC')
            ->assertSee('$450.00/day')
            ->assertSee('its catalogue rates and depreciation terms apply')
            // The rate inputs are hidden and disabled: they would be ignored.
            ->assertSee('id="model-default-fields" class="sm:col-span-2 hidden"', false);
    }

    public function test_setup_asks_for_rates_when_no_catalogue_model_matches(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('No catalogue model matches yet')
            ->assertDontSeeText('Joins existing model')
            ->assertDontSee('sm:col-span-2 hidden')
            ->assertSee('Catalogue rates and depreciation');
    }

    public function test_a_clerk_can_see_the_hold_but_not_set_rates_or_commit(): void
    {
        $admin = $this->tenantUser();
        $unit = $this->heldUnit($admin);
        $clerk = $this->staff($admin, 'clerk', 'clerk@tenant.test');

        $this->actingAs($clerk)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertSee('CAT 320 GC excavator')
            ->assertDontSee('Set up &');

        $this->actingAs($clerk)->get(route('service-hold.setup', $unit))->assertForbidden();

        $this->actingAs($clerk)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertForbidden();

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $unit->fresh()->fleet_status);
        $this->assertSame(0, EquipmentModel::query()->count());

        // An admin still gets the button and the form.
        $this->actingAs($admin)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertSee('Set up &');

        $this->actingAs($admin)->get(route('service-hold.setup', $unit))->assertOk();
    }

    public function test_joining_a_retired_model_is_flagged_and_reactivates_it(): void
    {
        $user = $this->tenantUser();

        $model = EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'default_cost_price' => 20000,
            'is_active' => false,
        ]);

        $unit = $this->heldUnit($user, [
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
        ]);

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('This model is retired; committing returns it to the catalogue.');

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'was retired and is active in the catalogue again'));

        $this->assertTrue($model->fresh()->is_active);
        $this->assertSame($model->id, $unit->fresh()->equipment_model_id);
        $this->assertTrue($unit->fresh()->is_active);
    }

    public function test_committing_does_not_overwrite_an_existing_models_defaults(): void
    {
        $user = $this->tenantUser();

        $existing = EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'default_cost_price' => 20000,
            'is_active' => true,
        ]);

        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload([
                'rental_price_day' => 999,
                'useful_life_months' => 24,
                'salvage_value' => 1000,
            ]))
            ->assertSessionHasNoErrors();

        // The catalogue is left exactly as it was, cost included.
        $existing->refresh();
        $this->assertSame('450.00', $existing->rental_price_day);
        $this->assertSame(60, $existing->useful_life_months);
        $this->assertSame('5000.00', $existing->salvage_value);
        $this->assertSame('20000.00', $existing->default_cost_price);

        // The unit adopts those terms rather than the typed ones.
        $unit->refresh();
        $this->assertSame($existing->id, $unit->equipment_model_id);
        $this->assertSame('450.00', $unit->rental_price_day);
        $this->assertSame(60, $unit->useful_life_months);
        $this->assertSame('5000.00', $unit->salvage_value);

        // Ticking the box is the only way the model changes.
        $second = $this->heldUnit($user, ['equipment_number' => 'PO-R-0001-2']);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $second), $this->commitPayload([
                'equipment_number' => 'EQ-101',
                'rental_price_day' => 999,
                'useful_life_months' => 24,
                'salvage_value' => 1000,
                'update_model_defaults' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame('999.00', $existing->rental_price_day);
        $this->assertSame(24, $existing->useful_life_months);
        $this->assertSame('1000.00', $existing->salvage_value);
        $this->assertSame('999.00', $second->fresh()->rental_price_day);
    }

    public function test_committing_requires_an_equipment_number_that_is_free(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-100',
            'name' => 'Existing unit',
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertSessionHasErrors('equipment_number');

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $unit->fresh()->fleet_status);
    }

    public function test_salvage_cannot_exceed_the_purchase_cost_and_life_must_be_positive(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload(['salvage_value' => 30000]))
            ->assertSessionHasErrors('salvage_value');

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload(['useful_life_months' => 0]))
            ->assertSessionHasErrors('useful_life_months');

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $unit->fresh()->fleet_status);
        $this->assertSame(0, EquipmentModel::query()->count());
    }

    public function test_a_tenant_cannot_commit_another_tenants_held_unit(): void
    {
        $owner = $this->tenantUser();
        $unit = $this->heldUnit($owner);

        $intruder = $this->tenantUser('intruder@tenant.test');

        $this->actingAs($intruder)->get(route('service-hold.setup', $unit))->assertNotFound();
        $this->actingAs($intruder)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertDontSee('CAT 320 GC excavator');
    }

    public function test_an_active_unit_cannot_be_recommitted_from_hold(): void
    {
        $user = $this->tenantUser();

        $unit = Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-200',
            'name' => 'In service',
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('service-hold.setup', $unit))->assertNotFound();
    }

    /** A rental purchase order line with its units waiting in service hold. */
    private function heldLine(User $user, int $quantity = 3): array
    {
        $order = PurchaseOrder::create([
            'business_id' => $user->business_id,
            'type' => PurchaseOrder::TYPE_RENTAL,
            'number' => 'PO-R-0007',
            'supplier_name' => 'Line Supplier',
            'status' => PurchaseOrder::STATUS_PARTIAL,
            'ordered_on' => '2026-11-20',
        ]);

        $item = $order->items()->create([
            'description' => 'CAT 320 GC excavator',
            'quantity_ordered' => $quantity,
            'quantity_received' => $quantity,
            'unit_cost' => 24000,
        ]);

        $units = collect();

        for ($i = 1; $i <= $quantity; $i++) {
            $units->push(Product::create([
                'business_id' => $user->business_id,
                'purchase_order_item_id' => $item->id,
                'product_type' => 'rental',
                'fleet_status' => Product::FLEET_SERVICE_HOLD,
                'is_active' => false,
                'equipment_number' => 'PO-R-0007-'.$i,
                'name' => 'CAT 320 GC excavator',
                'cost_price' => 24000,
                'purchase_order' => 'PO-R-0007',
                'purchase_date' => '2026-11-25',
                'stock_quantity' => 1,
            ]));
        }

        return [$order, $item, $units];
    }

    private function linePayload($units, array $overrides = []): array
    {
        $payload = $this->commitPayload($overrides);
        unset($payload['equipment_number'], $payload['serial_number']);

        $payload['units'] = $units->values()->mapWithKeys(fn (Product $unit, int $index) => [
            $unit->id => [
                'equipment_number' => 'EQ-'.($index + 1),
                'serial_number' => 'SN-'.($index + 1),
            ],
        ])->all();

        return $payload;
    }

    public function test_a_whole_purchase_order_line_is_committed_in_one_go(): void
    {
        $user = $this->tenantUser();
        [, $item, $units] = $this->heldLine($user);

        $this->actingAs($user)
            ->get(route('service-hold.line.setup', $item))
            ->assertOk()
            ->assertSee('Set up 3 units')
            ->assertSee('PO-R-0007-1')
            ->assertSee('Commit 3 units');

        $this->actingAs($user)
            ->post(route('service-hold.line.commit', $item), $this->linePayload($units))
            ->assertRedirect(route('service-hold.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EquipmentModel::query()->count());
        $model = EquipmentModel::query()->firstOrFail();

        foreach ($units->values() as $index => $unit) {
            $unit->refresh();

            $this->assertSame(Product::FLEET_ACTIVE, $unit->fleet_status);
            $this->assertTrue($unit->is_active);
            $this->assertSame($model->id, $unit->equipment_model_id);
            $this->assertSame('EQ-'.($index + 1), $unit->equipment_number);
            $this->assertSame('SN-'.($index + 1), $unit->serial_number);
            $this->assertSame('450.00', $unit->rental_price_day);
            $this->assertSame('18000.00', $unit->used_sale_price);
            $this->assertSame('2026-11-25', $unit->depreciation_started_on->format('Y-m-d'));
        }
    }

    public function test_the_line_page_names_the_catalogue_model_the_units_will_join(): void
    {
        $user = $this->tenantUser();
        [, $item] = $this->heldLine($user);

        // The line only carries a description, so it matches a catalogue entry
        // that has no make or model number yet.
        EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'CAT 320 GC excavator',
            'rental_price_day' => 500,
            'useful_life_months' => 60,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('service-hold.line.setup', $item))
            ->assertOk()
            ->assertSee('Joins existing model')
            ->assertSee('CAT 320 GC excavator')
            ->assertSee('Update this model');
    }

    public function test_a_line_commit_needs_a_free_tag_for_every_unit(): void
    {
        $user = $this->tenantUser();
        [, $item, $units] = $this->heldLine($user, 2);

        $payload = $this->linePayload($units);
        $payload['units'][$units[1]->id]['equipment_number'] = 'EQ-1';

        $this->actingAs($user)
            ->post(route('service-hold.line.commit', $item), $payload)
            ->assertSessionHasErrors();

        $this->assertSame(0, EquipmentModel::query()->count());
        $this->assertSame(Product::FLEET_SERVICE_HOLD, $units[1]->fresh()->fleet_status);
    }

    public function test_a_line_commit_leaves_units_already_out_of_hold_alone(): void
    {
        $user = $this->tenantUser();
        [, $item, $units] = $this->heldLine($user);

        $this->actingAs($user)->post(route('service-hold.commit', $units[0]), $this->commitPayload([
            'equipment_number' => 'EQ-FIRST',
        ]));

        $this->actingAs($user)
            ->get(route('service-hold.line.setup', $item))
            ->assertOk()
            ->assertSee('Set up 2 units');

        $remaining = $units->slice(1)->values();

        $this->actingAs($user)
            ->post(route('service-hold.line.commit', $item), $this->linePayload($remaining))
            ->assertSessionHasNoErrors();

        $this->assertSame('EQ-FIRST', $units[0]->fresh()->equipment_number);
        $this->assertSame(Product::FLEET_ACTIVE, $units[2]->fresh()->fleet_status);
    }

    public function test_clerks_and_other_tenants_cannot_set_up_a_line(): void
    {
        $user = $this->tenantUser();
        [, $item, $units] = $this->heldLine($user);

        $clerk = $this->staff($user, 'clerk', 'clerk@tenant.test');

        $this->actingAs($clerk)
            ->get(route('service-hold.line.setup', $item))
            ->assertForbidden();
        $this->actingAs($clerk)
            ->post(route('service-hold.line.commit', $item), $this->linePayload($units))
            ->assertForbidden();

        $intruder = $this->tenantUser('intruder@tenant.test');

        $this->actingAs($intruder)
            ->get(route('service-hold.line.setup', $item))
            ->assertNotFound();
        $this->actingAs($intruder)
            ->post(route('service-hold.line.commit', $item), $this->linePayload($units))
            ->assertNotFound();

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $units[0]->fresh()->fleet_status);
    }

    public function test_the_hold_groups_units_by_line_and_offers_the_bulk_action(): void
    {
        $user = $this->tenantUser();
        $this->heldLine($user, 3);

        $this->heldUnit($user, ['equipment_number' => 'PO-R-0001-9']);

        $this->actingAs($user)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertSee('Set up all 3')
            ->assertSee('4 units waiting')
            ->assertDontSee('Set up all 1');
    }

    public function test_committing_records_the_terms_and_the_person_who_applied_them(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'service_hold.committed')->sole();
        $model = EquipmentModel::firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->business_id, $log->business_id);
        $this->assertSame('Equipment committed to fleet', $log->actionLabel());
        $this->assertSame($model->id, $log->model_id);
        $this->assertSame('Excavator', $log->details['catalogue_model']);
        $this->assertSame('EQ-100', $log->details['units'][0]['equipment_number']);
        $this->assertSame('CAT0320GC12345', $log->details['units'][0]['serial_number']);
        $this->assertSame('450.00', $log->details['rental_price_day']);
        $this->assertSame(48, $log->details['useful_life_months']);
        $this->assertSame('4800.00', $log->details['salvage_value']);
        $this->assertFalse($log->details['updated_model_defaults']);
    }

    public function test_a_held_unit_can_be_returned_and_its_line_is_owed_again(): void
    {
        $user = $this->tenantUser();
        [$order, $item, $units] = $this->heldLine($user, 3);

        $this->actingAs($user)
            ->get(route('service-hold.return.form', $units[0]))
            ->assertOk()
            ->assertSee('Why is it going back?')
            ->assertSee('Return 1 unit');

        $this->actingAs($user)
            ->post(route('service-hold.return', $units[0]), [
                'reason' => 'Damaged on arrival — hydraulic leak',
                'credit_note' => 'CN-991',
                'resolution' => 'replacement',
            ])
            ->assertRedirect(route('service-hold.index'))
            ->assertSessionHasNoErrors();

        $this->assertNull(Product::find($units[0]->id));
        $this->assertSame(2, $item->fresh()->quantity_received);
        $this->assertSame(1, $item->fresh()->outstanding());
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->fresh()->status);

        $log = AuditLog::where('action', 'service_hold.returned')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Damaged on arrival — hydraulic leak', $log->details['reason']);
        $this->assertSame('CN-991', $log->details['credit_note']);
        $this->assertSame('awaiting a replacement', $log->details['resolution']);
        $this->assertSame('PO-R-0007', $log->details['purchase_order']);
        $this->assertSame('PO-R-0007-1', $log->details['units'][0]['equipment_number']);
        $this->assertSame($item->id, $log->model_id);
    }

    public function test_a_whole_line_returns_only_the_units_still_in_hold(): void
    {
        $user = $this->tenantUser();
        [$order, $item, $units] = $this->heldLine($user, 3);
        $order->refreshStatus();

        // One unit is already a fleet asset: a line return must leave it alone.
        $this->actingAs($user)
            ->post(route('service-hold.commit', $units[0]), $this->commitPayload(['equipment_number' => 'EQ-KEPT']))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('service-hold.line.return.form', $item))
            ->assertOk()
            ->assertSee('Return 2 units');

        $this->actingAs($user)
            ->post(route('service-hold.line.return', $item), [
                'reason' => 'Not as ordered',
                'credit_note' => 'CN-7',
                'resolution' => 'credit',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Product::FLEET_ACTIVE, $units[0]->fresh()->fleet_status);
        $this->assertNull(Product::find($units[1]->id));
        $this->assertNull(Product::find($units[2]->id));

        // A credit note leaves the receipt standing, so the order stays received.
        $this->assertSame(3, $item->fresh()->quantity_received);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->fresh()->status);

        $log = AuditLog::where('action', 'service_hold.returned')->sole();
        $this->assertCount(2, $log->details['units']);
        $this->assertSame('credit note only', $log->details['resolution']);
    }

    public function test_returning_requires_a_reason_and_a_resolution(): void
    {
        $user = $this->tenantUser();
        [, , $units] = $this->heldLine($user, 2);

        $this->actingAs($user)
            ->post(route('service-hold.return', $units[0]), [])
            ->assertSessionHasErrors(['reason', 'resolution']);

        $this->assertNotNull(Product::find($units[0]->id));
        $this->assertSame(0, AuditLog::where('action', 'service_hold.returned')->count());
    }

    public function test_clerks_and_other_tenants_cannot_return_held_units(): void
    {
        $user = $this->tenantUser();
        [, $item, $units] = $this->heldLine($user, 2);

        $clerk = $this->staff($user, 'clerk', 'clerk@tenant.test');

        $this->actingAs($clerk)->get(route('service-hold.return.form', $units[0]))->assertForbidden();
        $this->actingAs($clerk)
            ->post(route('service-hold.line.return', $item), [
                'reason' => 'Wrong item',
                'resolution' => 'replacement',
            ])
            ->assertForbidden();

        $intruder = $this->tenantUser('intruder@tenant.test');

        $this->actingAs($intruder)->get(route('service-hold.return.form', $units[0]))->assertNotFound();
        $this->actingAs($intruder)
            ->post(route('service-hold.line.return', $item), [
                'reason' => 'Wrong item',
                'resolution' => 'replacement',
            ])
            ->assertNotFound();

        // A committed unit is a fleet asset, not something sitting in the hold.
        $this->actingAs($user)
            ->post(route('service-hold.commit', $units[0]), $this->commitPayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('service-hold.return.form', $units[0]))
            ->assertNotFound();

        $this->assertSame(2, Product::where('purchase_order_item_id', $item->id)->count());
    }
}
