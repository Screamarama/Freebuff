<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
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

    private function saleProduct(User $user, array $overrides = []): Product
    {
        return Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'sale',
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Hard hat',
            'sale_price' => 25,
            'stock_quantity' => 0,
            'available_quantity' => 0,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function order(User $user, string $type, Product|string $item, int $quantity, float $unitCost, array $overrides = []): PurchaseOrder
    {
        $line = $type === 'rental'
            ? ['description' => is_string($item) ? $item : 'Excavator', 'quantity_ordered' => $quantity, 'unit_cost' => $unitCost]
            : ['product_id' => $item->id, 'quantity_ordered' => $quantity, 'unit_cost' => $unitCost];

        $this->actingAs($user)->post(route('purchase-orders.store'), [
            'type' => $type,
            'supplier_name' => 'Acme Supply',
            'ordered_on' => now()->toDateString(),
            'items' => [$line],
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::query()->latest('id')->firstOrFail();
    }

    public function test_new_purchase_order_asks_for_a_type_first(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('purchase-orders.create'))
            ->assertOk()
            ->assertSee('Rental items')
            ->assertSee('Sales items')
            ->assertSee('type=rental', false)
            ->assertSee('type=sales', false);
    }

    public function test_rental_and_sales_orders_keep_separate_number_series(): void
    {
        $user = $this->tenantUser();
        $sale = $this->saleProduct($user);

        $firstRental = $this->order($user, 'rental', 'CAT 320 GC excavator', 1, 100);
        $secondRental = $this->order($user, 'rental', 'CAT 320 GC excavator', 1, 100);
        $sales = $this->order($user, 'sales', $sale, 4, 10);

        $this->assertSame('PO-R-0001', $firstRental->number);
        $this->assertSame('PO-R-0002', $secondRental->number);
        $this->assertSame('PO-S-0001', $sales->number);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $sales->status);
        $this->assertSame(40.0, $sales->orderedValue());
    }

    public function test_receiving_a_sales_order_adds_stock(): void
    {
        $user = $this->tenantUser();
        $product = $this->saleProduct($user);
        $order = $this->order($user, 'sales', $product, 5, 10);
        $item = $order->items()->firstOrFail();

        $this->actingAs($user)
            ->post(route('purchase-orders.receive', $order), [
                'quantities' => [$item->id => 5],
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(5, (int) $product->stock_quantity);
        $this->assertSame(5, (int) $product->available_quantity);

        $order->refresh();
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertNotNull($order->received_at);
        $this->assertSame(0, $order->outstandingQuantity());
    }

    public function test_a_partial_receipt_stays_backordered(): void
    {
        $user = $this->tenantUser();
        $product = $this->saleProduct($user);
        $order = $this->order($user, 'sales', $product, 5, 10);
        $item = $order->items()->firstOrFail();

        $this->actingAs($user)
            ->post(route('purchase-orders.receive', $order), ['quantities' => [$item->id => 2]])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->status);
        $this->assertSame(3, $order->outstandingQuantity());

        $this->actingAs($user)
            ->get(route('purchase-orders.backorders'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee($product->name)
            ->assertSee('3');
    }

    public function test_receiving_a_rental_order_creates_one_product_per_unit(): void
    {
        $user = $this->tenantUser();
        $order = $this->order($user, 'rental', 'CAT 320 GC excavator', 2, 100);

        $item = $order->items()->firstOrFail();
        $this->actingAs($user)
            ->post(route('purchase-orders.receive', $order), [
                'quantities' => [$item->id => 2],
                'received_on' => '2026-11-25',
            ])
            ->assertSessionHasNoErrors();

        $created = Product::query()
            ->where('purchase_order', $order->number)
            ->orderBy('equipment_number')
            ->get();

        $this->assertCount(2, $created);
        $this->assertSame(['PO-R-0001-1', 'PO-R-0001-2'], $created->pluck('equipment_number')->all());
        $this->assertSame(100.0, (float) $created[0]->cost_price);
        $this->assertSame('2026-11-25', $created[0]->purchase_date->toDateString());
        $this->assertSame(1, (int) $created[0]->stock_quantity);
        $this->assertSame('rental', $created[0]->product_type);

        // Not in the fleet yet: it waits in service hold, unpriced and unlisted.
        $this->assertSame(Product::FLEET_SERVICE_HOLD, $created[0]->fleet_status);
        $this->assertFalse($created[0]->is_active);
        $this->assertNull($created[0]->equipment_model_id);
        $this->assertNull($created[0]->useful_life_months);
        $this->assertSame('CAT 320 GC excavator', $created[0]->name);

        $this->actingAs($user)->get('/products')->assertOk()->assertDontSee('CAT 320 GC excavator');
        $this->actingAs($user)->get(route('service-hold.index'))->assertOk()->assertSee('CAT 320 GC excavator');
    }

    public function test_cannot_receive_more_than_is_outstanding(): void
    {
        $user = $this->tenantUser();
        $product = $this->saleProduct($user);
        $order = $this->order($user, 'sales', $product, 5, 10);
        $item = $order->items()->firstOrFail();

        $this->actingAs($user)
            ->post(route('purchase-orders.receive', $order), ['quantities' => [$item->id => 6]])
            ->assertSessionHasErrors('quantities.'.$item->id);

        $item->refresh();
        $this->assertSame(0, $item->quantity_received);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->fresh()->status);
    }

    public function test_a_tenant_cannot_order_another_tenants_product(): void
    {
        $user = $this->tenantUser();

        $otherTenant = Business::create(['name' => 'Other Tenant', 'is_active' => true]);
        $foreign = Product::create([
            'business_id' => $otherTenant->id,
            'product_type' => 'sale',
            'sku' => 'FOREIGN-1',
            'name' => 'Not yours',
            'stock_quantity' => 0,
            'available_quantity' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('purchase-orders.store'), [
                'type' => 'sales',
                'items' => [['product_id' => $foreign->id, 'quantity_ordered' => 1]],
            ])
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_a_tenant_cannot_view_another_tenants_order(): void
    {
        $user = $this->tenantUser();
        $product = $this->saleProduct($user);
        $order = $this->order($user, 'sales', $product, 1, 5);

        $intruder = $this->tenantUser('intruder@tenant.test');

        $this->actingAs($intruder)
            ->get(route('purchase-orders.show', $order))
            ->assertNotFound();
    }

    public function test_cancel_is_only_possible_before_receiving(): void
    {
        $user = $this->tenantUser();
        $product = $this->saleProduct($user);
        $order = $this->order($user, 'sales', $product, 2, 5);

        $this->actingAs($user)
            ->patch(route('purchase-orders.cancel', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $order->fresh()->status);

        $second = $this->order($user, 'sales', $product, 2, 5);
        $item = $second->items()->firstOrFail();

        $this->actingAs($user)
            ->post(route('purchase-orders.receive', $second), ['quantities' => [$item->id => 1]]);

        $this->actingAs($user)
            ->patch(route('purchase-orders.cancel', $second))
            ->assertSessionHasErrors('status');

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $second->fresh()->status);
    }

    public function test_book_value_follows_straight_line_depreciation(): void
    {
        $user = $this->tenantUser();
        $start = Carbon::today()->subMonths(6);

        $product = Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-'.uniqid(),
            'name' => 'Excavator',
            'stock_quantity' => 1,
            'cost_price' => 1200,
            'salvage_value' => 0,
            'useful_life_months' => 12,
            'purchase_date' => $start,
            'depreciation_started_on' => $start,
            'is_active' => true,
        ]);

        $this->assertSame(100.0, $product->monthlyDepreciation());
        $this->assertSame(600.0, $product->bookValue());

        // Never depreciates past the salvage value.
        $this->assertSame(0.0, $product->bookValue(Carbon::today()->addMonths(24)));
    }

    public function test_a_rental_line_needs_a_description(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)
            ->post(route('purchase-orders.store'), [
                'type' => 'rental',
                'items' => [['quantity_ordered' => 1]],
            ])
            ->assertSessionHasErrors('items.0.description');

        $this->assertSame(0, PurchaseOrder::query()->count());
    }
}
