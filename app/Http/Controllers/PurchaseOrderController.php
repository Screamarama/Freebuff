<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = $this->businessId($request);

        $type = $request->query('type');
        $status = $request->query('status');

        $query = PurchaseOrder::query()
            ->where('business_id', $businessId)
            ->with('items');

        if (in_array($type, PurchaseOrder::TYPES, true)) {
            $query->where('type', $type);
        }

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('ordered_on')->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('purchase-orders.index', [
            'orders' => $orders,
            'type' => in_array($type, PurchaseOrder::TYPES, true) ? $type : null,
            'status' => $status,
        ]);
    }

    /** Ask for the order type first: rental equipment and sales stock are separate books. */
    public function create(Request $request): View
    {
        $businessId = $this->businessId($request);
        $type = $request->query('type');

        if (! in_array($type, PurchaseOrder::TYPES, true)) {
            return view('purchase-orders.choose-type');
        }

        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('product_type', 'sale')
            ->orderBy('name')
            ->get();

        $suppliers = PurchaseOrder::query()
            ->where('business_id', $businessId)
            ->whereNotNull('supplier_name')
            ->distinct()
            ->orderBy('supplier_name')
            ->pluck('supplier_name');

        return view('purchase-orders.create', compact('type', 'products', 'suppliers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = $this->businessId($request);

        $data = $request->validate([
            'type' => 'required|in:'.implode(',', PurchaseOrder::TYPES),
            'supplier_name' => 'nullable|string|max:150',
            'ordered_on' => 'nullable|date',
            'expected_on' => 'nullable|date|after_or_equal:ordered_on',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.description' => 'required_if:type,rental|nullable|string|max:150',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ], [
            'items.required' => 'Add at least one line to the purchase order.',
            'items.min' => 'Add at least one line to the purchase order.',
        ]);

        $order = DB::transaction(function () use ($data, $businessId) {
            $order = PurchaseOrder::create([
                'business_id' => $businessId,
                'type' => $data['type'],
                'number' => PurchaseOrder::nextNumber($businessId, $data['type']),
                'supplier_name' => $data['supplier_name'] ?? null,
                'status' => PurchaseOrder::STATUS_ORDERED,
                'ordered_on' => $data['ordered_on'] ?? Carbon::today(),
                'expected_on' => $data['expected_on'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $index => $line) {
                if ($data['type'] === PurchaseOrder::TYPE_RENTAL) {
                    // Rental equipment is ordered by description; its catalogue
                    // entry and terms are set up after it is received.
                    $order->items()->create([
                        'description' => $line['description'],
                        'quantity_ordered' => (int) $line['quantity_ordered'],
                        'unit_cost' => $line['unit_cost'] ?? 0,
                    ]);

                    continue;
                }

                $product = $this->tenantProduct($businessId, (int) $line['product_id'], $data['type'], "items.$index.product_id");

                $order->items()->create([
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity_ordered' => (int) $line['quantity_ordered'],
                    'unit_cost' => $line['unit_cost'] ?? $product->cost_price ?? 0,
                ]);
            }

            return $order;
        });

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Purchase order '.$order->number.' created.');
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $order = $this->tenantOrder($request, $purchaseOrder);
        $order->load(['items.product', 'items.committedUnits']);

        $products = Product::query()
            ->where('business_id', $order->business_id)
            ->where('is_active', true)
            ->where('product_type', 'sale')
            ->orderBy('name')
            ->get();

        return view('purchase-orders.show', compact('order', 'products'));
    }

    public function storeItem(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->tenantOrder($request, $purchaseOrder);

        if ($order->isClosed()) {
            throw ValidationException::withMessages(['product_id' => 'This purchase order is closed.']);
        }

        $data = $request->validate([
            'product_id' => 'nullable|integer',
            'description' => $order->isRental() ? 'required|string|max:150' : 'nullable|string|max:150',
            'quantity_ordered' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        if ($order->isRental()) {
            $order->items()->create([
                'description' => $data['description'],
                'quantity_ordered' => (int) $data['quantity_ordered'],
                'unit_cost' => $data['unit_cost'] ?? 0,
            ]);
        } else {
            $product = $this->tenantProduct($order->business_id, (int) ($data['product_id'] ?? 0), $order->type, 'product_id');

            $order->items()->create([
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity_ordered' => (int) $data['quantity_ordered'],
                'unit_cost' => $data['unit_cost'] ?? $product->cost_price ?? 0,
            ]);
        }

        $order->refreshStatus();

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Line added to '.$order->number.'.');
    }

    public function destroyItem(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): RedirectResponse
    {
        $order = $this->tenantOrder($request, $purchaseOrder);

        if ($item->purchase_order_id !== $order->id) {
            abort(404);
        }

        if ($item->quantity_received > 0) {
            throw ValidationException::withMessages(['item' => 'A received line cannot be removed.']);
        }

        $item->delete();
        $order->refreshStatus();

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Line removed.');
    }

    /**
     * Record a receipt. Sales lines add stock; rental lines become one product
     * record per unit so each keeps its own cost, purchase date and book value.
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->tenantOrder($request, $purchaseOrder);

        if ($order->isClosed()) {
            throw ValidationException::withMessages(['quantities' => 'This purchase order is closed.']);
        }

        $data = $request->validate([
            'quantities' => 'required|array',
            'quantities.*' => 'nullable|integer|min:0',
            'received_on' => 'nullable|date',
        ], [
            'quantities.required' => 'Enter the quantities received.',
        ]);

        $receivedOn = isset($data['received_on'])
            ? Carbon::parse($data['received_on'])
            : Carbon::today();

        $receivedLines = [];

        DB::transaction(function () use ($order, $data, $receivedOn, &$receivedLines) {
            $order->load('items.product');

            $receivedTotal = 0;

            foreach ($order->items as $item) {
                $quantity = (int) ($data['quantities'][$item->id] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > $item->outstanding()) {
                    throw ValidationException::withMessages([
                        'quantities.'.$item->id => $item->label().': only '.$item->outstanding().' left to receive.',
                    ]);
                }

                if (! $order->isRental() && ! $item->product) {
                    throw ValidationException::withMessages([
                        'quantities.'.$item->id => $item->label().': the product no longer exists.',
                    ]);
                }

                $item->forceFill(['quantity_received' => $item->quantity_received + $quantity])->save();

                if ($order->isRental()) {
                    $this->createRentalUnits($order, $item, $quantity, $receivedOn);
                } else {
                    $this->addSaleStock($item->product, $quantity);
                }

                $receivedTotal += $quantity;

                $receivedLines[] = [
                    'line' => $item->label(),
                    'quantity' => $quantity,
                    'unit_cost' => (float) $item->unit_cost,
                ];
            }

            if ($receivedTotal === 0) {
                throw ValidationException::withMessages(['quantities' => 'Enter a quantity to receive.']);
            }

            $order->refreshStatus();
        });

        // What arrived, when and against which order is a stock movement worth
        // keeping: the quantity counters alone cannot say who took delivery.
        AuditLog::record('purchase_order.received', [
            'purchase_order' => $order->number,
            'supplier' => $order->supplier_name,
            'received_on' => $receivedOn->toDateString(),
            'lines' => $receivedLines,
            'total_units' => array_sum(array_column($receivedLines, 'quantity')),
        ], $request->user(), $order->business_id, $order);

        // Rental equipment is not bookable yet: it waits in service hold to be
        // priced and committed, so send the user straight there.
        if ($order->isRental()) {
            return redirect()
                ->route('service-hold.index')
                ->with('success', $order->number.': received units are in service hold, ready to set up.');
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Receipt recorded on '.$order->number.'.');
    }

    /** Cancel an order only while nothing on it has been received. */
    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->tenantOrder($request, $purchaseOrder);

        if ($order->status === PurchaseOrder::STATUS_CANCELLED) {
            return redirect()->route('purchase-orders.show', $order);
        }

        if ($order->items()->where('quantity_received', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'status' => 'This order has receipts; it cannot be cancelled.',
            ]);
        }

        $order->forceFill(['status' => PurchaseOrder::STATUS_CANCELLED])->save();

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Purchase order '.$order->number.' cancelled.');
    }

    /** Everything still owed by suppliers, oldest expected date first. */
    public function backorders(Request $request): View
    {
        $businessId = $this->businessId($request);

        $lines = PurchaseOrderItem::query()
            ->whereColumn('quantity_received', '<', 'quantity_ordered')
            ->whereHas('purchaseOrder', function ($query) use ($businessId) {
                $query->where('business_id', $businessId)
                    ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED);
            })
            ->with(['purchaseOrder', 'product'])
            ->get()
            ->sortBy(fn (PurchaseOrderItem $item) => $item->purchaseOrder->expected_on?->timestamp ?? PHP_INT_MAX)
            ->values();

        return view('purchase-orders.backorders', [
            'lines' => $lines,
            'outstandingUnits' => (int) $lines->sum(fn (PurchaseOrderItem $item) => $item->outstanding()),
            'outstandingValue' => round((float) $lines->sum(
                fn (PurchaseOrderItem $item) => $item->outstanding() * (float) $item->unit_cost
            ), 2),
        ]);
    }

    /**
     * Received rental equipment lands in service hold with only what the order
     * says: a description, a temporary tag and its cost. Its rates, depreciation
     * terms and catalogue entry are set when it is committed to the fleet.
     */
    private function createRentalUnits(PurchaseOrder $order, PurchaseOrderItem $item, int $quantity, Carbon $receivedOn): void
    {
        for ($unit = 0; $unit < $quantity; $unit++) {
            $sequence = Product::query()
                ->where('business_id', $order->business_id)
                ->where('purchase_order', $order->number)
                ->count() + 1;

            Product::create([
                'business_id' => $order->business_id,
                'purchase_order_item_id' => $item->id,
                'product_type' => 'rental',
                'fleet_status' => Product::FLEET_SERVICE_HOLD,
                'is_active' => false,
                'equipment_number' => $this->uniqueEquipmentNumber($order->number.'-'.$sequence, $order->business_id),
                'name' => $item->description ?: 'Unnamed equipment',
                'cost_price' => $item->unit_cost,
                'purchase_order' => $order->number,
                'purchase_date' => $receivedOn,
                'depreciation_started_on' => $receivedOn,
                'stock_quantity' => 1,
            ]);
        }
    }

    /** Sales stock arrives as units: both the count and what is on hand move. */
    private function addSaleStock(Product $product, int $quantity): void
    {
        $product->forceFill([
            'stock_quantity' => (int) $product->stock_quantity + $quantity,
            'available_quantity' => (int) $product->available_quantity + $quantity,
        ])->save();
    }

    private function uniqueEquipmentNumber(string $base, int $businessId): string
    {
        $candidate = $base;
        $suffix = 1;

        while (Product::query()->where('business_id', $businessId)->where('equipment_number', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    private function businessId(Request $request): int
    {
        $businessId = $request->user()->business_id;

        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        return (int) $businessId;
    }

    private function tenantOrder(Request $request, PurchaseOrder $order): PurchaseOrder
    {
        if ($order->business_id !== $this->businessId($request)) {
            abort(404);
        }

        return $order;
    }

    /** Lines may only reference this tenant's products of the order's own type. */
    private function tenantProduct(int $businessId, int $productId, string $type, string $field): Product
    {
        $product = Product::query()
            ->where('business_id', $businessId)
            ->where('id', $productId)
            ->first();

        if (! $product || $product->product_type !== ($type === PurchaseOrder::TYPE_RENTAL ? 'rental' : 'sale')) {
            throw ValidationException::withMessages([
                $field => 'Choose one of your own '.($type === PurchaseOrder::TYPE_RENTAL ? 'rental' : 'sales').' items.',
            ]);
        }

        return $product;
    }
}
