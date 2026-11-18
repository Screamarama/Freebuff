<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Services\Availability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class ReservationController extends Controller
{
    public function index(Request $request): View
    {
        $query = Reservation::query()->with(['product', 'customer']);

        if ($request->user()->role !== 'super_admin') {
            $query->where('business_id', $request->user()->business_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('notes', 'like', '%' . $request->search . '%')
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', '%' . $request->search . '%'))
                    ->orWhereHas('customer', function ($cq) use ($request) {
                        $cq->where('first_name', 'like', '%' . $request->search . '%')
                            ->orWhere('last_name', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $reservations = $query->orderBy('starts_at')->paginate(12);

        return view('reservations.index', compact('reservations'));
    }

    public function create(Request $request): View
    {
        $businessId = $request->user()->business_id;
        $products = $this->tenantProducts($request)->orderBy('name')->get();
        $customers = $this->tenantCustomers($request)->orderBy('first_name')->get();
        $selectedProductId = $request->integer('product_id') ?: null;

        return view('reservations.create', compact('products', 'customers', 'selectedProductId', 'businessId'));
    }

    public function store(Request $request, Availability $availability): RedirectResponse
    {
        $businessId = $request->user()->business_id;
        if ($request->user()->role !== 'super_admin' && ! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:customers,id',
            'quantity' => 'required|integer|min:1',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
            'notes' => 'nullable',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $customer = Customer::findOrFail($validated['customer_id']);
        $this->ensureTenantOwns($request, $product->business_id);
        $this->ensureTenantOwns($request, $customer->business_id);

        if (! $product->isRental()) {
            return back()->withErrors(['product_id' => 'Only rental items can be reserved.'])->withInput();
        }

        if (! $product->is_active) {
            return back()->withErrors(['product_id' => 'That item is not active.'])->withInput();
        }

        DB::transaction(function () use ($availability, $product, $validated, $request) {
            // Lock the unit so a second booking cannot pass the same check
            // while this one is being written.
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $availability->assertReservation($product, $validated['quantity'], $validated['starts_at'], $validated['ends_at']);

            $fee = Reservation::calculateFee($product, $validated['starts_at'], $validated['ends_at'], $validated['quantity']);

            Reservation::create([
                ...$validated,
                'business_id' => $product->business_id,
                'created_by' => $request->user()->id,
                'status' => 'upcoming',
                'total_amount' => $fee['total'],
                'billing_unit' => $fee['unit'],
                'price_breakdown' => $fee['breakdown'],
            ]);
        });

        // Note: stock quantities are intentionally untouched. The item stays
        // rentable until 24h before starts_at (see Reservation::holdState).
        return redirect()->route('reservations.index')->with('success', 'Reservation created. Item stays available until 24h before it starts.');
    }

    public function show(Request $request, Reservation $reservation): View
    {
        $this->ensureTenantOwns($request, $reservation->business_id);
        $reservation->load(['product', 'customer', 'rentalContract']);

        return view('reservations.show', compact('reservation'));
    }

    public function edit(Request $request, Reservation $reservation): View|RedirectResponse
    {
        $this->ensureTenantOwns($request, $reservation->business_id);

        if (in_array($reservation->status, ['completed', 'cancelled'], true)) {
            return redirect()->route('reservations.show', $reservation)->with('success', 'Closed reservations cannot be edited.');
        }

        $products = $this->tenantProducts($request)->orderBy('name')->get();
        $customers = $this->tenantCustomers($request)->orderBy('first_name')->get();

        return view('reservations.edit', compact('reservation', 'products', 'customers'));
    }

    public function update(Request $request, Reservation $reservation, Availability $availability): RedirectResponse
    {
        $this->ensureTenantOwns($request, $reservation->business_id);

        if (in_array($reservation->status, ['completed', 'cancelled'], true)) {
            abort(422, 'Closed reservations cannot be edited.');
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:customers,id',
            'quantity' => 'required|integer|min:1',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'notes' => 'nullable',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $customer = Customer::findOrFail($validated['customer_id']);
        $this->ensureTenantOwns($request, $product->business_id);
        $this->ensureTenantOwns($request, $customer->business_id);

        if (! $product->isRental()) {
            return back()->withErrors(['product_id' => 'Only rental items can be reserved.'])->withInput();
        }

        DB::transaction(function () use ($availability, $product, $validated, $reservation) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $availability->assertReservation($product, $validated['quantity'], $validated['starts_at'], $validated['ends_at'], $reservation->id);

            $fee = Reservation::calculateFee($product, $validated['starts_at'], $validated['ends_at'], $validated['quantity']);

            $reservation->update(array_merge($validated, [
                'total_amount' => $fee['total'],
                'billing_unit' => $fee['unit'],
                'price_breakdown' => $fee['breakdown'],
            ]));
        });

        return redirect()->route('reservations.show', $reservation)->with('success', 'Reservation updated.');
    }

    public function destroy(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->ensureTenantOwns($request, $reservation->business_id);
        $reservation->update(['status' => 'cancelled']);

        return redirect()->route('reservations.index')->with('success', 'Reservation cancelled. No stock was ever held.');
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->ensureTenantOwns($request, $reservation->business_id);
        $reservation->update(['status' => 'cancelled']);

        return back()->with('success', 'Reservation cancelled.');
    }

    public function complete(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->ensureTenantOwns($request, $reservation->business_id);
        $reservation->update(['status' => 'completed']);

        return back()->with('success', 'Reservation marked completed.');
    }

    private function tenantProducts(Request $request)
    {
        $query = Product::query()->where('is_active', true)->where('product_type', 'rental');
        if ($request->user()->role !== 'super_admin') {
            $query->where('business_id', $request->user()->business_id);
        } elseif ($request->user()->business_id) {
            $query->where('business_id', $request->user()->business_id);
        }

        return $query;
    }

    private function tenantCustomers(Request $request)
    {
        $query = Customer::query()->where('is_active', true);
        if ($request->user()->role !== 'super_admin') {
            $query->where('business_id', $request->user()->business_id);
        } elseif ($request->user()->business_id) {
            $query->where('business_id', $request->user()->business_id);
        }

        return $query;
    }

    private function ensureTenantOwns(Request $request, ?int $businessId): void
    {
        if ($request->user()->role === 'super_admin') {
            return;
        }

        if ($businessId !== $request->user()->business_id) {
            abort(404);
        }
    }
}
