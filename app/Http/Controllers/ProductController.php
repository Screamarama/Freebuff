<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Availability;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query()->where('is_active', true);

        if ($request->user()->role !== 'super_admin') {
            $query->where('business_id', $request->user()->business_id);
        }

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('equipment_number', 'like', '%'.$request->search.'%')
                  ->orWhere('sku', 'like', '%'.$request->search.'%')
                  ->orWhere('serial_number', 'like', '%'.$request->search.'%')
                  ->orWhere('manufacturer', 'like', '%'.$request->search.'%')
                  ->orWhere('model_number', 'like', '%'.$request->search.'%');
            });
        }

        // Rentals are identified by Equipment Number; sales by SKU.
        $rentals = (clone $query)
            ->where('product_type', 'rental')
            ->withHeldToday()
            ->orderBy('equipment_number')
            ->paginate(12, ['*'], 'rentals')
            ->withQueryString();

        $sales = (clone $query)
            ->where('product_type', 'sale')
            ->orderByRaw('sku IS NULL')
            ->orderBy('sku')
            ->orderBy('name')
            ->paginate(12, ['*'], 'sales')
            ->withQueryString();

        return view('products.index', compact('rentals', 'sales'));
    }

    /**
     * New product: pick rental or sale first, then show only that type's fields.
     */
    public function create(Request $request)
    {
        $type = $request->query('type');

        if (! in_array($type, ['rental', 'sale'], true)) {
            return view('products.choose-type');
        }

        return view('products.create', compact('type'));
    }

    public function store(Request $request)
    {
        $type = $request->input('product_type');

        if (! in_array($type, ['rental', 'sale'], true)) {
            return redirect()->route('products.create')->with('status', 'Choose whether this is a rental item or a sale item.');
        }

        $businessId = $request->user()->business_id;

        $validated = $this->validateProduct($request, $businessId);

        if ($request->user()->role !== 'super_admin' && ! $request->user()->business_id) {
            abort(403, 'No tenant assigned to your account.');
        }

        $validated['business_id'] = $request->user()->business_id;
        $validated['is_active'] = true;

        if ($type === 'rental') {
            // Each rental record is one physical unit; what is available is
            // derived from reservations and agreements, not entered here.
            $validated['stock_quantity'] = 1;
            unset($validated['available_quantity']);
        } else {
            $validated['stock_quantity'] ??= 0;
            $validated['available_quantity'] ??= 0;
        }

        Product::create($validated);

        return redirect('/products')->with('success', 'Product created!');
    }

    public function show(Request $request, Product $product, Availability $availability)
    {
        $this->ensureSameTenant($request, $product);

        [$from, $to, $truncated] = $this->availabilityRange($request);

        $calendar = $product->isRental()
            ? $availability->dailyAvailability($product, $from, $to)
            : [];

        return view('products.show', compact('product', 'calendar', 'from', 'to', 'truncated'));
    }

    /** The requested availability window, defaulting to the next seven days. */
    private function availabilityRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        // To on its own is fine: From falls back to today, and the same check
        // covers a From the caller supplied.
        $from = Carbon::parse($validated['from'] ?? now())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? $from->copy()->addDays(6))->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => 'The end date must be on or after the start date.',
            ]);
        }

        // Keep the list small: a month at most, and say so on the page.
        $truncated = $from->diffInDays($to) > 30;

        return [$from, $truncated ? $from->copy()->addDays(30) : $to, $truncated];
    }

    public function edit(Request $request, Product $product)
    {
        $this->ensureSameTenant($request, $product);

        return view('products.edit', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        $this->ensureSameTenant($request, $product);

        $previousPath = $product->image_path;

        $validated = $this->validateProduct($request, $product->business_id, $product);

        if (! isset($validated['image_path']) && $request->boolean('remove_image')) {
            $validated['image_path'] = null;
        }

        // Drop the old file when it was replaced or cleared, so the disk does
        // not collect orphans. array_key_exists, not ??, because clearing the
        // image sets image_path to null and must still delete the file.
        $newPath = array_key_exists('image_path', $validated) ? $validated['image_path'] : $previousPath;

        if ($previousPath && $previousPath !== $newPath) {
            Storage::disk('public')->delete($previousPath);
        }

        $product->update($validated);

        return redirect('/products')->with('success', 'Product updated!');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->ensureSameTenant($request, $product);

        $product->update(['is_active' => false]);
        return redirect('/products')->with('success', 'Product deleted!');
    }

    /**
     * Product rules differ by type. Rentals are identified by equipment
     * number and priced per time block; sales are identified by SKU and
     * tracked by stock. Purchase price is stored in cost_price.
     */
    private function validateProduct(Request $request, ?int $businessId, ?Product $product = null): array
    {
        $type = $request->input('product_type');

        $unique = function (string $column) use ($businessId, $product) {
            $rule = Rule::unique('products', $column)->where(fn ($q) => $q->where('business_id', $businessId));

            return $product ? $rule->ignore($product->id) : $rule;
        };

        $rules = [
            'product_type' => 'required|in:rental,sale',
            'name' => 'required|max:200',
            'description' => 'nullable',
            'category' => 'nullable|max:100',
            'image_url' => 'nullable|url',
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'remove_image' => 'nullable|boolean',
            'manufacturer' => 'nullable|max:150',
            'purchase_order' => 'nullable|max:100',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_date' => 'nullable|date',
        ];

        if ($type === 'rental') {
            $rules += [
                'equipment_number' => ['required', 'max:50', $unique('equipment_number')],
                'model_number' => 'nullable|max:150',
                'serial_number' => 'nullable|max:150',
                'rental_price_hour' => 'nullable|numeric|min:0',
                'rental_price_day' => 'nullable|numeric|min:0',
                'rental_price_week' => 'nullable|numeric|min:0',
                'rental_price_month' => 'nullable|numeric|min:0',
            ];
        } else {
            $rules += [
                'sku' => ['required', 'max:50', $unique('sku')],
                'sale_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'available_quantity' => 'nullable|integer|min:0',
            ];
        }

        $validated = $request->validate($rules);

        // Uploads are written to the public disk; only the path is stored.
        if ($request->hasFile('image')) {
            $validated['image_path'] = $request->file('image')->store('products', 'public');
        }

        unset($validated['image'], $validated['remove_image']);

        // The form speaks "purchase price"; the column is cost_price.
        $validated['cost_price'] = $validated['purchase_price'] ?? null;
        unset($validated['purchase_price']);

        return $validated;
    }

    private function ensureSameTenant(Request $request, Product $product): void
    {
        if ($request->user()->role === 'super_admin') {
            return;
        }

        if ($product->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }
}