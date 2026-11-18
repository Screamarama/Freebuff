<?php

namespace App\Http\Controllers;

use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalContract;
use App\Services\Availability;
use App\Models\RentalItem;
use App\Models\Reservation;
use App\Services\AgreementLedger;
use App\Services\CustomerAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RentalContractController extends Controller
{
    public function index(Request $request): View
    {
        $query = RentalContract::query()->with(['customer', 'reservation']);

        if ($request->user()->role !== 'super_admin') {
            $query->where('business_id', $request->user()->business_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('contract_number', 'like', '%' . $request->search . '%')
                    ->orWhereHas('customer', function ($cq) use ($request) {
                        $cq->where('first_name', 'like', '%' . $request->search . '%')
                            ->orWhere('last_name', 'like', '%' . $request->search . '%');
                    });
            });
        }

        $contracts = $query->orderByDesc('id')->paginate(12);

        return view('rental-agreements.index', compact('contracts'));
    }

    /** Start an agreement directly, without a reservation. */
    public function create(Request $request): View
    {
        // A draft is only restored when the customer form sends us back with
        // ?resume=1. Any other visit discards it, so an abandoned flow cannot
        // refill a fresh agreement form with old dates or notes.
        $stored = $request->session()->pull('agreement_draft', []);
        $resume = $request->boolean('resume')
            && ($stored['saved_at'] ?? 0) >= now()->subHours(2)->timestamp;

        $draft = array_merge([
            'customer_id' => null,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'notes' => '',
        ], $resume ? $stored : []);

        // Super admins are redirected to the tenants summary by middleware,
        // so this page always has a tenant to scope to.
        $customers = Customer::query()
            ->where('business_id', $request->user()->business_id)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get();

        $products = Product::where('business_id', $request->user()->business_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rentalRows = $this->itemRows($request->old('rental', $draft['rental'] ?? []));
        $saleRows = $this->itemRows($request->old('sale', $draft['sale'] ?? []));
        $defaultRentalDuration = $this->durationForWindow(
            $request->old('start_date', $draft['start_date']),
            $request->old('end_date', $draft['end_date']),
            'day'
        );

        return view('rental-agreements.create', [
            'customers' => $customers,
            'rentalProducts' => $products->where('product_type', 'rental'),
            'saleProducts' => $products->where('product_type', 'sale'),
            'rentalRows' => $rentalRows,
            'saleRows' => $saleRows,
            'nextRentalIndex' => max(array_map('intval', array_keys($rentalRows))) + 1,
            'nextSaleIndex' => max(array_map('intval', array_keys($saleRows))) + 1,
            'defaultRentalDuration' => $defaultRentalDuration,
            'draft' => $draft,
        ]);
    }

    /**
     * Keep the agreement form's current values while the user adds the customer
     * it is missing, then bring them back here once that customer exists.
     */
    public function startNewCustomer(Request $request): RedirectResponse
    {
        $request->session()->put('agreement_draft', [
            'customer_id' => $request->integer('customer_id') ?: null,
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'notes' => $request->input('notes'),
            'rental' => $this->draftItems($request->input('rental', []), [
                'product_id', 'quantity', 'rate_type', 'duration', 'unit_price', 'serial_number',
            ]),
            'sale' => $this->draftItems($request->input('sale', []), [
                'product_id', 'quantity', 'unit_price', 'serial_number',
            ]),
            'saved_at' => now()->timestamp,
        ]);

        return redirect()->route('customers.create', ['from_agreement' => 1]);
    }

    public function store(Request $request, Availability $availability): RedirectResponse
    {
        $itemRules = [
            'product_id' => 'nullable|exists:products,id',
            'quantity' => 'nullable|integer|min:1',
            'rate_type' => 'nullable|in:hour,day,week,month',
            'duration' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'serial_number' => 'nullable|string|max:100',
        ];

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'notes' => 'nullable|string',
            'rental' => 'nullable|array',
            'rental.*.product_id' => $itemRules['product_id'],
            'rental.*.quantity' => $itemRules['quantity'],
            'rental.*.rate_type' => $itemRules['rate_type'],
            'rental.*.duration' => $itemRules['duration'],
            'rental.*.unit_price' => $itemRules['unit_price'],
            'rental.*.serial_number' => $itemRules['serial_number'],
            'sale' => 'nullable|array',
            'sale.*.product_id' => $itemRules['product_id'],
            'sale.*.quantity' => $itemRules['quantity'],
            'sale.*.unit_price' => $itemRules['unit_price'],
            'sale.*.serial_number' => $itemRules['serial_number'],
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);
        $this->ensureTenantOwns($request, $customer->business_id);

        $contract = DB::transaction(function () use ($validated, $customer, $request, $availability) {
            $contract = RentalContract::create([
                'business_id' => $customer->business_id,
                'contract_number' => $this->nextContractNumber(),
                'customer_id' => $customer->id,
                'clerk_id' => $request->user()->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'active',
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach (['rental', 'sale'] as $kind) {
                foreach ($validated[$kind] ?? [] as $index => $data) {
                    if (empty($data['product_id'])) {
                        continue;
                    }

                    $product = Product::findOrFail($data['product_id']);
                    if ($product->business_id !== $contract->business_id) {
                        abort(404);
                    }

                    $this->attachItem(
                        $contract,
                        $product,
                        $kind,
                        $data,
                        $availability,
                        $kind . '.' . $index . '.'
                    );
                }
            }

            return $contract;
        });

        $this->syncTerms($contract);

        return redirect()->route('rental-agreements.edit', $contract)
            ->with('success', 'Agreement ' . $contract->contract_number . ' created.');
    }

    /** Live stock/availability for one product over the agreement dates. */
    public function availability(Request $request, Availability $availability): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'contract_id' => 'nullable|integer',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $this->ensureTenantOwns($request, $product->business_id);

        $tracked = (int) $product->stock_quantity > 0;

        $contract = null;
        if ($request->filled('contract_id')) {
            $contract = RentalContract::findOrFail($request->integer('contract_id'));
            $this->ensureTenantOwns($request, $contract->business_id);
        }

        // Adding to an existing agreement: its own lines already hold units and
        // sales, so show what is left to add rather than total free stock.
        $onContract = $contract
            ? (int) $contract->items()->where('product_id', $product->id)->sum('quantity')
            : 0;

        if ($product->isSale()) {
            return response()->json([
                'available' => $tracked
                    ? max(0, (int) $product->available_quantity - $onContract)
                    : null,
                'tracked' => $tracked,
                'unit' => 'sale',
            ]);
        }

        return response()->json([
            'available' => $tracked
                ? max(0, $availability->availableFor(
                    $product,
                    $validated['start_date'],
                    $validated['end_date'],
                    ignoreContractId: $contract?->id
                ) - $onContract)
                : null,
            'tracked' => $tracked,
            'unit' => 'rental',
        ]);
    }

    public function show(Request $request, RentalContract $rentalContract, AgreementLedger $ledger, CustomerAccount $accounts): View
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);
        $rentalContract->load(['customer', 'items.product', 'reservation', 'business']);

        return view('rental-agreements.show', [
            'contract' => $rentalContract,
            'charges' => $ledger->charges($rentalContract),
            'damageCharges' => $ledger->damageCharges($rentalContract),
            'paid' => $ledger->paid($rentalContract),
            'rentHeld' => max(0, $ledger->rentPaid($rentalContract)),
            'balance' => $ledger->balance($rentalContract),
            'accountBalance' => $rentalContract->customer ? $accounts->openBalance($rentalContract->customer) : 0.0,
            'accountAvailable' => $rentalContract->customer ? $accounts->availableCredit($rentalContract->customer) : null,
            'payments' => $ledger->payments($rentalContract),
            'deposit' => $ledger->depositSummary($rentalContract),
            // What the products say the hold should be, offered as the default.
            'suggestedDeposit' => $rentalContract->items->sum(
                fn ($item) => (float) ($item->product?->deposit_amount ?? 0) * (int) $item->quantity
            ),
        ]);
    }

    /**
     * Turn a reservation into an active rental agreement (contract + line items).
     * Reuses the reservation's closest-block fee so totals always match.
     */
    public function convert(Request $request, Reservation $reservation, Availability $availability): RedirectResponse
    {
        $this->ensureTenantOwns($request, $reservation->business_id);
        $reservation->load(['product', 'customer']);

        if ($reservation->rentalContract) {
            return redirect()->route('rental-agreements.show', $reservation->rentalContract)
                ->with('success', 'This reservation already has a rental agreement.');
        }

        if (in_array($reservation->status, ['cancelled'], true)) {
            abort(422, 'Cancelled reservations cannot be converted.');
        }

        if ($reservation->status === 'completed' && $reservation->rentalContract === null) {
            // Fall through: a completed reservation without a contract can still
            // be converted (e.g. walk-in checkout recorded late). Otherwise only
            // upcoming/active reservations convert.
        } elseif (! in_array($reservation->status, ['upcoming', 'active', 'completed'], true)) {
            abort(422, 'Only upcoming or active reservations can be converted.');
        }

        $contract = DB::transaction(function () use ($request, $reservation, $availability) {
            // Hold the unit for the length of this transaction and re-check
            // inside it, so two conversions cannot both pass.
            $product = Product::whereKey($reservation->product_id)->lockForUpdate()->firstOrFail();

            $available = $availability->availableFor(
                $product,
                $reservation->starts_at,
                $reservation->ends_at,
                ignoreReservationId: $reservation->id
            );

            if ($available < $reservation->quantity) {
                throw ValidationException::withMessages([
                    'reservation' => 'Not enough units are available for those dates.',
                ]);
            }

            $fee = Reservation::calculateFee(
                $product,
                $reservation->starts_at,
                $reservation->ends_at,
                $reservation->quantity
            );

            $number = $this->nextContractNumber();

            $contract = RentalContract::create([
                'business_id' => $reservation->business_id,
                'reservation_id' => $reservation->id,
                'contract_number' => $number,
                'customer_id' => $reservation->customer_id,
                'clerk_id' => $request->user()->id,
                'start_date' => $reservation->starts_at->toDateString(),
                'end_date' => $reservation->ends_at->toDateString(),
                'status' => 'active',
                'subtotal' => $fee['total'],
                'total_amount' => $fee['total'],
                'notes' => $reservation->notes,
            ]);

            foreach ($fee['parts'] as $part) {
                $contract->items()->create([
                    'product_id' => $reservation->product_id,
                    'quantity' => $reservation->quantity,
                    'rate_type' => $part['unit'],
                    'unit_price' => $part['rate'],
                    'duration' => $part['count'],
                    'subtotal' => round($part['count'] * $part['rate'] * $reservation->quantity, 2),
                ]);
            }

            // Price the agreement now that its lines exist, so tax is part of
            // the total the customer signs.
            $contract->syncTotals();

            // Render the terms after the line items exist so {{rented_items}}
            // lists the real equipment (long lists move to an Appendix A).
            $this->syncTerms($contract);

            $reservation->update(['status' => 'completed']);

            return $contract;
        });

        return redirect()->route('rental-agreements.show', $contract)
            ->with('success', 'Reservation converted to rental agreement ' . $contract->contract_number . '.');
    }

    /** Edit line items: override prices, add rental/sale products (active agreements only). */
    public function edit(Request $request, RentalContract $rentalContract): View|RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (! $this->isEditable($rentalContract)) {
            return redirect()->route('rental-agreements.show', $rentalContract)
                ->with('success', 'Closed agreements cannot be edited.');
        }

        $rentalContract->load(['customer', 'items.product', 'reservation']);
        $rentalProducts = $this->agreementProducts($rentalContract, ['rental']);
        $saleProducts = $this->agreementProducts($rentalContract, ['sale']);

        return view('rental-agreements.edit', [
            'contract' => $rentalContract,
            'rentalProducts' => $rentalProducts,
            'saleProducts' => $saleProducts,
            'defaultRentalDuration' => $this->durationForWindow(
                $rentalContract->start_date,
                $rentalContract->end_date,
                'day'
            ),
        ]);
    }

    /** Add a rental or sale line item (price override allowed). */
    public function storeItem(Request $request, RentalContract $rentalContract, Availability $availability): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);
        $this->ensureEditable($rentalContract);

        $validated = $request->validate([
            'kind' => 'required|in:rental,sale',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'rate_type' => 'required_if:kind,rental|in:hour,day,week,month',
            'duration' => 'required_if:kind,rental|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'serial_number' => 'nullable|string|max:100',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        if ($product->business_id !== $rentalContract->business_id) {
            abort(404);
        }

        $this->attachItem($rentalContract, $product, $validated['kind'], $validated, $availability);

        $this->syncTerms($rentalContract);

        return redirect()->route('rental-agreements.edit', $rentalContract)->with('success', 'Item added.');
    }

    /** Override quantity / price / duration on a line item. */
    public function updateItem(Request $request, RentalContract $rentalContract, RentalItem $item, Availability $availability): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);
        $this->ensureEditable($rentalContract);

        if ($item->contract_id !== $rentalContract->id) {
            abort(404);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'duration' => ($item->isSale() ? 'nullable' : 'required') . '|integer|min:1',
            'serial_number' => 'nullable|string|max:100',
        ]);

        if (! $item->isSale()) {
            $max = $this->durationForWindow(
                $rentalContract->start_date,
                $rentalContract->end_date,
                $item->rate_type
            );

            if ($validated['duration'] > $max) {
                throw ValidationException::withMessages([
                    'duration' => "This line is longer than the agreement window (max {$max} {$item->rate_type}" . ($max === 1 ? '' : 's') . ').',
                ]);
            }
        }

        DB::transaction(function () use ($availability, $rentalContract, $item, $validated) {
            if (! $item->isSale() && $item->product !== null) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();

                if (! $availability->canAddLine(
                    $rentalContract, $product, $validated['quantity'], $item->id
                )) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Not enough units available for those dates.',
                    ]);
                }
            }

            if ($item->isSale() && $item->product !== null) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();

                if ((int) $product->stock_quantity > 0) {
                    $already = (int) $rentalContract->items()
                        ->where('product_id', $product->id)
                        ->where('rate_type', 'sale')
                        ->whereKeyNot($item->id)
                        ->sum('quantity');

                    if ($validated['quantity'] + $already > (int) $product->available_quantity) {
                        throw ValidationException::withMessages([
                            'quantity' => 'Only ' . (int) $product->available_quantity . ' in stock for that item.',
                        ]);
                    }
                }
            }

            $item->update([
                'quantity' => $validated['quantity'],
                'serial_number' => $validated['serial_number'] ?? null,
                'unit_price' => $validated['unit_price'],
                'duration' => $item->isSale() ? 1 : $validated['duration'],
                'subtotal' => RentalItem::computeSubtotal(
                    $item->rate_type,
                    (float) $validated['unit_price'],
                    $item->isSale() ? 1 : $validated['duration'],
                    $validated['quantity']
                ),
            ]);

            $rentalContract->syncTotals();
        });

        $this->syncTerms($rentalContract);

        return redirect()->route('rental-agreements.edit', $rentalContract)->with('success', 'Item updated.');
    }

    public function destroyItem(Request $request, RentalContract $rentalContract, RentalItem $item): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);
        $this->ensureEditable($rentalContract);

        if ($item->contract_id !== $rentalContract->id) {
            abort(404);
        }

        $item->delete();
        $rentalContract->syncTotals();
        $this->syncTerms($rentalContract);

        return redirect()->route('rental-agreements.edit', $rentalContract)->with('success', 'Item removed.');
    }

    /** Mark the equipment back in without settling a deposit. */
    public function markReturned(Request $request, RentalContract $rentalContract): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);
        $this->ensureOpen($rentalContract);

        $validated = $request->validate([
            'returned_on' => 'required|date',
        ]);

        $rentalContract->update([
            'status' => 'returned',
            'actual_return_date' => $validated['returned_on'],
        ]);

        return back()->with('success', 'Agreement marked returned — the units are free again.');
    }

    public function cancel(Request $request, RentalContract $rentalContract, AgreementLedger $ledger): RedirectResponse
    {
        $this->ensureTenantOwns($request, $rentalContract->business_id);

        if (Gate::denies('cancelAgreement', $rentalContract)) {
            abort(403, 'Only admins and managers can cancel an agreement or refund its money.');
        }

        if ($rentalContract->status === 'returned') {
            abort(422, 'That agreement is already closed.');
        }

        $validated = $request->validate([
            'refund_method' => ['nullable', Rule::in(Payment::COUNTER_METHODS)],
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $alreadyCancelled = $rentalContract->status === 'cancelled';

        $refunded = $ledger->cancelAgreement(
            $rentalContract,
            $validated['refund_method'] ?? null,
            $validated['cancellation_reason'],
            $request->user(),
        );

        if ($alreadyCancelled) {
            return back()->with('success', 'Money of $'.number_format($refunded, 2).' refunded — the agreement stays cancelled.');
        }

        $message = $refunded > 0
            ? 'Agreement cancelled — the units are free again and $'.number_format($refunded, 2).' refunded.'
            : 'Agreement cancelled — the units are free again.';

        return back()->with('success', $message);
    }

    /**
     * Render the tenant's terms while the agreement is still unsigned, so a
     * directly created agreement gains them when equipment is added.
     */
    private function syncTerms(RentalContract $contract): void
    {
        if ($contract->signed_at !== null) {
            return;
        }

        $contract->load('items.product');

        // No equipment left to list: do not keep terms naming removed items.
        if ($contract->items->isEmpty()) {
            if ($contract->terms_conditions !== null) {
                $contract->update(['terms_conditions' => null]);
            }

            return;
        }

        $equipment = $contract->equipmentBlock();
        $template = ContractTemplate::effectiveFor($contract->business_id);

        $rendered = $template?->render([
            'business_id' => $contract->business_id,
            'customer_name' => $contract->customer?->full_name ?? '',
            'customer_address' => $contract->customer?->full_address ?: '{{customer_address}}',
            'rented_items' => $equipment['inline'],
            'contract_number' => $contract->contract_number,
            'contract_date' => now()->format('F j, Y'),
            'start_date' => $contract->start_date->format('Y-m-d'),
            'end_date' => $contract->end_date->format('Y-m-d'),
            'total_amount' => number_format((float) $contract->total_amount, 2),
        ]);

        if ($rendered === null) {
            return;
        }

        $terms = trim($rendered['title'] . "\n\n" . $rendered['body'] . "\n\n" . $rendered['footer']);

        if ($equipment['appendix'] !== null) {
            $terms .= "\n\n" . $equipment['appendix'];
        }

        $contract->update(['terms_conditions' => $terms]);
    }

    private function ensureOpen(RentalContract $contract): void
    {
        if (in_array($contract->status, ['returned', 'cancelled'], true)) {
            abort(422, 'That agreement is already closed.');
        }
    }

    private function isEditable(RentalContract $contract): bool
    {
        return ! in_array($contract->status, ['returned', 'cancelled'], true);
    }

    private function ensureEditable(RentalContract $contract): void
    {
        if (! $this->isEditable($contract)) {
            abort(422, 'Closed agreements cannot be edited.');
        }
    }

    /** A contract number that no tenant has used yet. */
    private function nextContractNumber(): string
    {
        do {
            $number = 'RA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (RentalContract::where('contract_number', $number)->exists());

        return $number;
    }

    /**
     * Validate, price and attach one rental or sale line. $errorPrefix keeps
     * errors on the right create-form box (for example "rental.product_id").
     */
    private function attachItem(
        RentalContract $contract,
        Product $product,
        string $kind,
        array $data,
        Availability $availability,
        string $errorPrefix = ''
    ): void {
        $quantity = (int) ($data['quantity'] ?? 1);

        if ($kind === 'sale') {
            if (! $product->isSale()) {
                throw ValidationException::withMessages([
                    $errorPrefix . 'product_id' => 'Only sale items can be added as a sale.',
                ]);
            }
            $rateType = 'sale';
            $duration = 1;
            $unitPrice = $data['unit_price'] ?? $product->sale_price;
        } else {
            if (! $product->isRental()) {
                throw ValidationException::withMessages([
                    $errorPrefix . 'product_id' => 'Only rental items can be added as a rental.',
                ]);
            }
            $rateType = $data['rate_type'] ?? 'day';
            $duration = empty($data['duration'])
                ? $this->durationForWindow($contract->start_date, $contract->end_date, $rateType)
                : (int) $data['duration'];
            $max = $this->durationForWindow($contract->start_date, $contract->end_date, $rateType);
            $unitPrice = $data['unit_price'] ?? $product->{'rental_price_' . $rateType};

            if ($duration > $max) {
                throw ValidationException::withMessages([
                    $errorPrefix . 'duration' => "This line is longer than the agreement window (max {$max} {$rateType}" . ($max === 1 ? '' : 's') . ').',
                ]);
            }
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                $errorPrefix . 'product_id' => 'That item is not active.',
            ]);
        }

        if ($unitPrice === null) {
            throw ValidationException::withMessages([
                $errorPrefix . 'unit_price' => 'No list price for that rate — enter an override price.',
            ]);
        }

        DB::transaction(function () use ($availability, $contract, $product, $kind, $quantity, $rateType, $duration, $unitPrice, $data, $errorPrefix) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($kind === 'rental' && ! $availability->canAddLine($contract, $product, $quantity)) {
                throw ValidationException::withMessages([
                    $errorPrefix . 'quantity' => 'Not enough units available for those dates.',
                ]);
            }

            if ($kind === 'sale' && (int) $product->stock_quantity > 0) {
                $already = (int) $contract->items()
                    ->where('product_id', $product->id)
                    ->where('rate_type', 'sale')
                    ->sum('quantity');

                if ($quantity + $already > (int) $product->available_quantity) {
                    throw ValidationException::withMessages([
                        $errorPrefix . 'quantity' => 'Only ' . (int) $product->available_quantity . ' in stock for that item.',
                    ]);
                }
            }

            $contract->items()->create([
                'product_id' => $product->id,
                'serial_number' => $data['serial_number'] ?? null,
                'quantity' => $quantity,
                'rate_type' => $rateType,
                'unit_price' => $unitPrice,
                'duration' => $duration,
                'subtotal' => RentalItem::computeSubtotal($rateType, (float) $unitPrice, $duration, $quantity),
            ]);

            $contract->syncTotals();
        });
    }

    /** Inclusive calendar days covered by the agreement dates. */
    private function windowDays(Carbon|string $start, Carbon|string $end): int
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        return max(1, (int) abs($start->diffInDays($end)) + 1);
    }

    /** A sensible block count for the agreement window at the chosen rate. */
    private function durationForWindow(Carbon|string $start, Carbon|string $end, string $rate): int
    {
        $days = $this->windowDays($start, $end);

        return match ($rate) {
            'hour' => $days * 24,
            'week' => (int) ceil($days / 7),
            'month' => (int) ceil($days / 30),
            default => $days,
        };
    }

    /** Normalise posted/old item rows, defaulting to one blank row. */
    private function itemRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [0 => []];
        }

        // Drafts saved before multi-row support held a single row.
        if (array_key_exists('product_id', $rows)) {
            $rows = [$rows];
        }

        return $rows === [] ? [0 => []] : $rows;
    }

    /** Keep only the fields the create form knows how to restore, one entry per row. */
    private function draftItems(mixed $items, array $keys): array
    {
        if (! is_array($items)) {
            return [];
        }

        $rows = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = array_intersect_key($item, array_flip($keys));
            if (array_filter($row, fn ($value) => $value !== null && $value !== '') !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function agreementProducts(RentalContract $contract, array $types)
    {
        return Product::query()
            ->where('business_id', $contract->business_id)
            ->where('is_active', true)
            ->whereIn('product_type', $types)
            ->orderBy('name')
            ->get();
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
