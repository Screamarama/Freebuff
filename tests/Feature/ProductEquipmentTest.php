<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(): User
    {
        $tenant = Business::create([
            'name' => 'Test Rental Business',
            'phone' => '555-0100',
            'email' => 'info@testrental.test',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $admin = new User(['name' => 'Tenant Admin', 'email' => 'admin@tenant.test', 'password' => 'password']);
        $admin->role = 'admin';
        $admin->business_id = $tenant->id;
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return [
            'equipment_number' => 'EQ-100',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'serial_number' => 'CAT0320GC12345',
            'name' => 'Excavator',
            'product_type' => 'rental',
            'rental_price_day' => 450,
            ...$overrides,
        ];
    }

    public function test_new_product_asks_for_a_type_first(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('Rental item')
            ->assertSee('Sales item')
            ->assertSee('type=rental', false)
            ->assertSee('type=sale', false);
    }

    public function test_rental_form_shows_equipment_fields_and_rates(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('products.create', ['type' => 'rental']))
            ->assertOk()
            ->assertSee('Equipment number')
            ->assertSee('Manufacturer')
            ->assertSee('Model number')
            ->assertSee('Serial number')
            ->assertSee('Purchase order')
            ->assertSee('Purchase price')
            ->assertSee('Purchase date')
            ->assertSee('Rental price / hour')
            ->assertSee('Rental price / day')
            ->assertSee('Rental price / week')
            ->assertSee('Rental price / month')
            ->assertDontSee('Stock quantity')
            ->assertDontSee('Available quantity');
    }

    public function test_sale_form_shows_sku_purchase_and_stock_fields(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('products.create', ['type' => 'sale']))
            ->assertOk()
            ->assertSee('SKU')
            ->assertSee('Manufacturer')
            ->assertSee('Purchase order')
            ->assertSee('Purchase price')
            ->assertSee('Purchase date')
            ->assertSee('Sale price')
            ->assertSee('Stock quantity')
            ->assertSee('Available quantity')
            ->assertDontSee('Equipment number')
            ->assertDontSee('Rental price / day');
    }

    public function test_sale_item_requires_a_sku_instead_of_equipment_number(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        // A sale item with an SKU but no equipment number is valid.
        $this->post(route('products.store'), [
            'name' => 'Hard Hat',
            'product_type' => 'sale',
            'sku' => 'HAT-YEL',
            'sale_price' => 15.5,
        ])->assertRedirect('/products');

        $this->assertDatabaseHas('products', [
            'business_id' => $admin->business_id,
            'sku' => 'HAT-YEL',
            'equipment_number' => null,
        ]);

        // Without a SKU it fails.
        $this->post(route('products.store'), [
            'name' => 'Gloves',
            'product_type' => 'sale',
            'sale_price' => 8,
        ])->assertSessionHasErrors('sku');
    }

    public function test_products_page_separates_rentals_and_sales_with_their_own_sorting(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        foreach ([['EQ-300', 'Zeta Lift'], ['EQ-100', 'Alpha Digger']] as [$number, $name]) {
            Product::create([
                'business_id' => $admin->business_id,
                'equipment_number' => $number,
                'name' => $name,
                'product_type' => 'rental',
                'is_active' => true,
            ]);
        }

        foreach ([['SKU-ZZZ', 'Wedge'], ['SKU-AAA', 'Ankle Weight']] as [$sku, $name]) {
            Product::create([
                'business_id' => $admin->business_id,
                'sku' => $sku,
                'name' => $name,
                'product_type' => 'sale',
                'is_active' => true,
            ]);
        }

        $this->get('/products')
            ->assertOk()
            ->assertSee('Rental items')
            ->assertSee('Sales items')
            ->assertSeeInOrder(['Alpha Digger', 'Zeta Lift'])
            ->assertSeeInOrder(['Ankle Weight', 'Wedge']);
    }

    public function test_new_rental_product_stores_equipment_details(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        $this->post(route('products.store'), $this->payload())->assertRedirect('/products');

        $this->assertDatabaseHas('products', [
            'business_id' => $admin->business_id,
            'equipment_number' => 'EQ-100',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'serial_number' => 'CAT0320GC12345',
        ]);
    }

    public function test_purchase_details_are_stored_and_rentals_are_single_units(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        $this->post(route('products.store'), $this->payload([
            'purchase_order' => 'PO-1042',
            'purchase_price' => 32000,
            'purchase_date' => '2026-03-14',
        ]))->assertRedirect('/products');

        $product = Product::where('equipment_number', 'EQ-100')->firstOrFail();

        $this->assertSame('PO-1042', $product->purchase_order);
        $this->assertSame('32000.00', $product->cost_price);
        $this->assertSame('2026-03-14', $product->purchase_date->format('Y-m-d'));
        // Rentals are single physical units; stock is implied, not entered.
        $this->assertSame(1, $product->stock_quantity);
        $this->assertSame(1, $product->available_quantity);
    }

    public function test_sale_item_keeps_its_entered_stock(): void
    {
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), [
            'name' => 'Hard Hat',
            'product_type' => 'sale',
            'sku' => 'HAT-YEL',
            'sale_price' => 15.5,
            'stock_quantity' => 24,
            'available_quantity' => 20,
        ])->assertRedirect('/products');

        $product = Product::where('sku', 'HAT-YEL')->firstOrFail();

        $this->assertSame(24, $product->stock_quantity);
        $this->assertSame(20, $product->available_quantity);
    }

    public function test_long_image_urls_are_saved(): void
    {
        $this->actingAs($this->tenantUser());

        // Search-redirect links routinely exceed the old 255-char column.
        $longUrl = 'https://example.test/img?'.str_repeat('a=1234567890&', 200).'z=1';

        $this->post(route('products.store'), [
            'name' => 'Red Hard Hat',
            'product_type' => 'sale',
            'sku' => 'RED-HAT',
            'sale_price' => 22,
            'image_url' => $longUrl,
        ])->assertRedirect('/products');

        $this->assertSame($longUrl, Product::where('sku', 'RED-HAT')->firstOrFail()->image_url);
    }

    public function test_equipment_number_is_required(): void
    {
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), $this->payload(['equipment_number' => '']))
            ->assertSessionHasErrors('equipment_number');
    }

    public function test_equipment_number_is_unique_per_tenant(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        $this->post(route('products.store'), $this->payload());
        $this->post(route('products.store'), $this->payload(['name' => 'Second Excavator']))
            ->assertSessionHasErrors('equipment_number');

        // Another tenant may reuse the same number.
        $other = Business::create([
            'name' => 'Other Rental Co',
            'phone' => '555-0111',
            'email' => 'info@other.test',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $otherAdmin = new User(['name' => 'Other Admin', 'email' => 'admin@other.test', 'password' => 'password']);
        $otherAdmin->role = 'admin';
        $otherAdmin->business_id = $other->id;
        $otherAdmin->email_verified_at = now();
        $otherAdmin->save();

        $this->actingAs($otherAdmin);
        $this->post(route('products.store'), $this->payload())->assertRedirect('/products');
    }

    public function test_search_finds_products_by_serial_or_manufacturer(): void
    {
        $admin = $this->tenantUser();
        Product::create([
            'business_id' => $admin->business_id,
            'equipment_number' => 'EQ-200',
            'manufacturer' => 'Bobcat',
            'model_number' => 'T76',
            'serial_number' => 'BOB-99887',
            'name' => 'Skid Steer',
            'product_type' => 'rental',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $this->get('/products?search=BOB-99887')->assertOk()->assertSee('Skid Steer');
        $this->get('/products?search=Bobcat')->assertOk()->assertSee('Skid Steer');
        $this->get('/products?search=EQ-200')->assertOk()->assertSee('Skid Steer');
    }

    public function test_product_page_shows_equipment_details(): void
    {
        $admin = $this->tenantUser();
        $product = Product::create([
            'business_id' => $admin->business_id,
            'equipment_number' => 'EQ-300',
            'manufacturer' => 'JLG',
            'model_number' => '450AJ',
            'serial_number' => 'JLG-450-77',
            'name' => 'Boom Lift',
            'product_type' => 'rental',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Equipment number')
            ->assertSee('EQ-300')
            ->assertSee('JLG')
            ->assertSee('450AJ')
            ->assertSee('JLG-450-77');
    }

    public function test_rental_item_accepts_an_uploaded_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('excavator.jpg', 800, 600),
        ]))->assertRedirect('/products');

        $product = Product::where('equipment_number', 'EQ-100')->firstOrFail();

        $this->assertStringStartsWith('products/', (string) $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
        $this->assertSame('/storage/'.$product->image_path, $product->image_source);
    }

    public function test_sale_item_accepts_an_uploaded_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), [
            'name' => 'Hard Hat',
            'product_type' => 'sale',
            'sku' => 'HAT-YEL',
            'sale_price' => 15.5,
            'image' => UploadedFile::fake()->image('hat.png'),
        ])->assertRedirect('/products');

        $product = Product::where('sku', 'HAT-YEL')->firstOrFail();

        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_a_new_upload_replaces_the_previous_file(): void
    {
        Storage::fake('public');
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('first.jpg'),
        ]))->assertRedirect('/products');

        $product = Product::where('equipment_number', 'EQ-100')->firstOrFail();
        $original = $product->image_path;

        $this->put(route('products.update', $product), $this->payload([
            'image' => UploadedFile::fake()->image('second.jpg'),
        ]))->assertRedirect('/products');

        $product->refresh();

        $this->assertNotSame($original, $product->image_path);
        Storage::disk('public')->assertMissing($original);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_an_uploaded_image_can_be_removed(): void
    {
        Storage::fake('public');
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), $this->payload([
            'image' => UploadedFile::fake()->image('unit.jpg'),
        ]))->assertRedirect('/products');

        $product = Product::where('equipment_number', 'EQ-100')->firstOrFail();
        $path = $product->image_path;

        $this->put(route('products.update', $product), $this->payload(['remove_image' => '1']))
            ->assertRedirect('/products');

        $product->refresh();

        $this->assertNull($product->image_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_uploads_must_be_images(): void
    {
        Storage::fake('public');
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), $this->payload([
            'image' => UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('image');
    }

    public function test_product_page_shows_the_uploaded_image_over_the_url(): void
    {
        Storage::fake('public');
        $this->actingAs($this->tenantUser());

        $this->post(route('products.store'), $this->payload([
            'image_url' => 'https://example.test/fallback.jpg',
            'image' => UploadedFile::fake()->image('unit.jpg'),
        ]))->assertRedirect('/products');

        $product = Product::where('equipment_number', 'EQ-100')->firstOrFail();

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('/storage/'.$product->image_path, false)
            ->assertSee('Uploaded image')
            ->assertDontSee('https://example.test/fallback.jpg', false);
    }

    public function test_forms_accept_file_uploads_for_both_types(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        $product = Product::create([
            'business_id' => $admin->business_id,
            'equipment_number' => 'EQ-900',
            'name' => 'Telehandler',
            'product_type' => 'rental',
            'is_active' => true,
        ]);

        foreach (['rental', 'sale'] as $type) {
            $this->get(route('products.create', ['type' => $type]))
                ->assertOk()
                ->assertSee('multipart/form-data', false)
                ->assertSee('name="image"', false);
        }

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('multipart/form-data', false)
            ->assertSee('name="image"', false);
    }
}
