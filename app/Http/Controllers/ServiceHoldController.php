<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EquipmentModel;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\RentalItem;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Rental equipment arrives from a purchase order into service hold. Nothing is
 * bookable until it is set up here: rates, depreciation terms and the used sale
 * price are entered, then it is committed to the fleet and the catalogue.
 *
 * Units received on one purchase order line share its terms, so the whole line
 * can be set up and committed at once.
 */
class ServiceHoldController extends Controller
{
    public function index(Request $request): View
    {
        $units = Product::query()
            ->where('business_id', $this->businessId($request))
            ->where('product_type', 'rental')
            ->inServiceHold()
            ->with('purchaseOrderItem')
            ->orderBy('purchase_date')
            ->orderBy('equipment_number')
            ->get();

        // One group per purchase order line. Units without a line (receipts
        // from before lines were recorded) stay on their own.
        $lines = $units
            ->groupBy(fn (Product $unit) => $unit->purchase_order_item_id
                ? 'line:'.$unit->purchase_order_item_id
                : 'unit:'.$unit->id)
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'item' => $first->purchaseOrderItem,
                    'description' => $first->purchaseOrderItem?->description ?: ($first->name ?: 'Equipment'),
                    'purchase_order' => $first->purchase_order,
                    'units' => $group->values(),
                ];
            })
            ->values();

        return view('service-hold.index', compact('units', 'lines'));
    }

    public function setup(Request $request, Product $product): View
    {
        $unit = $this->heldUnit($request, $product);

        // Match against what the form currently shows, so a validation bounce
        // keeps telling the user which catalogue entry the unit would join.
        $current = [
            'name' => old('name', $unit->name),
            'manufacturer' => old('manufacturer', $unit->manufacturer),
            'model_number' => old('model_number', $unit->model_number),
        ];

        [$catalogue, $matching, $matchingSummary] = $this->catalogueData($unit, $current);

        return view('service-hold.setup', [
            'unit' => $unit,
            'catalogue' => $catalogue,
            'matching' => $matching,
            'matchingSummary' => $matchingSummary,
        ]);
    }

    /** Set up every unit still held from one purchase order line. */
    public function setupLine(Request $request, PurchaseOrderItem $item): View
    {
        $units = $this->heldUnitsForLine($request, $item);
        $first = $units->first();

        $current = [
            'name' => old('name', $item->description ?: $first->name),
            'manufacturer' => old('manufacturer', $first->manufacturer),
            'model_number' => old('model_number', $first->model_number),
        ];

        [$catalogue, $matching, $matchingSummary] = $this->catalogueData($first, $current);

        return view('service-hold.line-setup', [
            'item' => $item,
            'units' => $units,
            'catalogue' => $catalogue,
            'matching' => $matching,
            'matchingSummary' => $matchingSummary,
        ]);
    }

    /** The catalogue models this tenant can join, plus the preview data for the form. */
    private function catalogueData(Product $unit, array $current): array
    {
        $currency = $unit->business?->currency;

        $catalogue = EquipmentModel::query()
            ->where('business_id', $unit->business_id)
            ->orderBy('name')
            ->get()
            ->map(function (EquipmentModel $model) use ($currency) {
                return [
                    'name' => $model->name,
                    'manufacturer' => $model->manufacturer,
                    'model_number' => $model->model_number,
                    'label' => $model->label(),
                    'summary' => $this->rateSummary($model, $currency),
                    'active' => (bool) $model->is_active,
                    'rental_price_hour' => $model->rental_price_hour,
                    'rental_price_day' => $model->rental_price_day,
                    'rental_price_week' => $model->rental_price_week,
                    'rental_price_month' => $model->rental_price_month,
                    'deposit_amount' => $model->deposit_amount,
                    'useful_life_months' => $model->useful_life_months,
                    'salvage_value' => $model->salvage_value,
                ];
            })
            ->values();

        $matching = $this->matchingModel($unit, $current);

        return [
            $catalogue,
            $matching,
            $matching ? $this->rateSummary($matching, $currency) : null,
        ];
    }

    /** The catalogue terms a unit would adopt, for the setup page and its preview. */
    private function rateSummary(EquipmentModel $model, ?string $currency): string
    {
        $bits = [];

        if ($model->rental_price_day !== null) {
            $bits[] = Money::format($model->rental_price_day, $currency).'/day';
        }
        if ($model->useful_life_months) {
            $bits[] = $model->useful_life_months.' months';
        }
        if ($model->salvage_value !== null) {
            $bits[] = Money::format($model->salvage_value, $currency).' salvage';
        }

        return $bits ? implode(' · ', $bits) : 'no rates set yet';
    }

    public function commit(Request $request, Product $product): RedirectResponse
    {
        $unit = $this->heldUnit($request, $product);

        $data = $this->validateSetup($request);

        $unitData = $request->validate([
            'equipment_number' => [
                'required',
                'max:50',
                Rule::unique('products', 'equipment_number')
                    ->where(fn ($q) => $q->where('business_id', $unit->business_id))
                    ->ignore($unit->id),
            ],
            'serial_number' => 'nullable|max:150',
        ]);

        $this->assertSalvageWithinCost(collect([$unit]), $data);

        $joined = $this->matchingModel($unit, $data);
        $updateDefaults = $request->boolean('update_model_defaults');
        $wasRetired = $joined !== null && ! $joined->is_active;

        $model = $this->commitUnits(
            collect([$unit]),
            $data,
            [$unit->id => $unitData],
            $updateDefaults,
        );

        $this->auditCommit($request, collect([$unit]), $model, $data['used_sale_price'] ?? null, $updateDefaults);

        $message = $unit->equipment_number.' committed to the rental fleet.';

        // Say so when the unit adopted the catalogue's terms instead of the
        // ones typed on the form, so nothing about the rates is a surprise.
        if ($joined && ! $updateDefaults) {
            $message .= ' It uses '.$model->name."'s existing catalogue rates and depreciation defaults.";
        }

        if ($wasRetired) {
            $message .= ' '.$model->name.' was retired and is active in the catalogue again.';
        }

        return redirect()
            ->route('service-hold.index')
            ->with('success', $message);
    }

    /**
     * Commit every held unit from one purchase order line with the same
     * catalogue entry and rates. Each unit keeps its own asset tag and serial.
     */
    public function commitLine(Request $request, PurchaseOrderItem $item): RedirectResponse
    {
        $units = $this->heldUnitsForLine($request, $item);

        $data = $this->validateSetup($request);

        $rules = [
            'units' => 'required|array',
        ];

        foreach ($units as $unit) {
            $rules["units.{$unit->id}.equipment_number"] = [
                'required',
                'max:50',
                Rule::unique('products', 'equipment_number')
                    ->where(fn ($q) => $q->where('business_id', $unit->business_id))
                    ->ignore($unit->id),
            ];
            $rules["units.{$unit->id}.serial_number"] = 'nullable|max:150';
        }

        $request->validate($rules);

        $posted = $request->input('units', []);

        // One asset tag per unit: the database index would catch it, but the
        // form should say which problem it is.
        $tags = collect($posted)->pluck('equipment_number')->filter()->map(fn ($tag) => (string) $tag);

        if ($tags->count() !== $tags->unique()->count()) {
            throw ValidationException::withMessages([
                'units' => 'Each unit needs its own equipment number.',
            ]);
        }

        $perUnit = [];

        foreach ($units as $unit) {
            $perUnit[$unit->id] = [
                'equipment_number' => $posted[$unit->id]['equipment_number'],
                'serial_number' => $posted[$unit->id]['serial_number'] ?? null,
            ];
        }

        $this->assertSalvageWithinCost($units, $data);

        $joined = $this->matchingModel($units->first(), $data);
        $updateDefaults = $request->boolean('update_model_defaults');
        $wasRetired = $joined !== null && ! $joined->is_active;

        $model = $this->commitUnits($units, $data, $perUnit, $updateDefaults);

        $this->auditCommit($request, $units, $model, $data['used_sale_price'] ?? null, $updateDefaults);

        $count = $units->count();

        $message = $count.' '.($count === 1 ? 'unit' : 'units').' committed to the rental fleet.';

        if ($joined && ! $updateDefaults) {
            $message .= ' They use '.$model->name."'s existing catalogue rates and depreciation defaults.";
        }

        if ($wasRetired) {
            $message .= ' '.$model->name.' was retired and is active in the catalogue again.';
        }

        return redirect()
            ->route('service-hold.index')
            ->with('success', $message);
    }

    /**
     * A commit decides rates, depreciation and the catalogue entry a unit joins,
     * so the terms actually applied are kept with who applied them.
     */
    private function auditCommit(
        Request $request,
        Collection $units,
        EquipmentModel $model,
        ?string $usedSalePrice,
        bool $updateDefaults,
    ): void {
        $first = $units->first();

        AuditLog::record('service_hold.committed', [
            'purchase_order' => $first->purchase_order,
            'line' => $first->purchaseOrderItem?->description,
            'catalogue_model' => $model->name,
            'units' => $this->unitTags($units),
            'rental_price_hour' => $model->rental_price_hour,
            'rental_price_day' => $model->rental_price_day,
            'rental_price_week' => $model->rental_price_week,
            'rental_price_month' => $model->rental_price_month,
            'deposit_amount' => $model->deposit_amount,
            'useful_life_months' => $model->useful_life_months,
            'salvage_value' => $model->salvage_value,
            'used_sale_price' => $usedSalePrice,
            'depreciation_started_on' => $first->depreciation_started_on?->toDateString(),
            'updated_model_defaults' => $updateDefaults,
        ], $request->user(), $first->business_id, $model);
    }

    /** Ask why a held unit (or a whole line's held units) is going back. */
    public function showReturn(Request $request, Product $product): View
    {
        $unit = $this->heldUnit($request, $product);

        return $this->unitsView(
            'service-hold.return',
            collect([$unit]),
            'Return '.($unit->equipment_number ?: 'this unit'),
            route('service-hold.return', $unit),
        );
    }

    public function showReturnLine(Request $request, PurchaseOrderItem $item): View
    {
        $units = $this->heldUnitsForLine($request, $item);

        return $this->unitsView(
            'service-hold.return',
            $units,
            'Return '.$units->count().' '.Str::plural('unit', $units->count()),
            route('service-hold.line.return', $item),
        );
    }

    /** The shared shell of the return and reopen pages. */
    private function unitsView(string $view, Collection $units, string $heading, string $action): View
    {
        return view($view, [
            'units' => $units,
            'purchaseOrder' => $units->first()->purchaseOrderItem?->purchaseOrder?->number
                ?: $units->first()->purchase_order,
            'action' => $action,
            'heading' => $heading,
        ]);
    }

    public function returnUnit(Request $request, Product $product): RedirectResponse
    {
        return $this->sendBack($request, collect([$this->heldUnit($request, $product)]));
    }

    public function returnLine(Request $request, PurchaseOrderItem $item): RedirectResponse
    {
        return $this->sendBack($request, $this->heldUnitsForLine($request, $item));
    }

    /**
     * Take held units out of service hold: a damaged or wrong delivery never
     * becomes a fleet asset. A replacement puts the quantity back on the order as
     * outstanding; a credit note leaves the line as received.
     */
    private function sendBack(Request $request, Collection $units): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:3|max:500',
            'credit_note' => 'nullable|string|max:100',
            'resolution' => 'required|in:replacement,credit',
        ]);

        $first = $units->first();
        $item = $first->purchaseOrderItem;
        $number = $item?->purchaseOrder?->number ?: $first->purchase_order;
        $replace = $data['resolution'] === 'replacement';
        $count = $units->count();

        $returned = $this->unitTags($units);

        DB::transaction(function () use ($units, $item, $replace, $count) {
            foreach ($units as $unit) {
                $unit->delete();
            }

            // Back to outstanding so a replacement can be received onto the
            // same line; with a credit note the receipt stands as it was.
            if ($replace && $item) {
                $item->forceFill([
                    'quantity_received' => max(0, $item->quantity_received - $count),
                ])->save();

                $item->purchaseOrder?->refreshStatus();
            }
        });

        AuditLog::record('service_hold.returned', [
            'purchase_order' => $number,
            'line' => $item?->description,
            'units' => $returned,
            'reason' => $data['reason'],
            'credit_note' => $data['credit_note'] ?? null,
            'resolution' => $replace ? 'awaiting a replacement' : 'credit note only',
        ], $request->user(), $first->business_id, $item);

        $message = $count.' '.($count === 1 ? 'unit' : 'units').' returned to the supplier.';

        if ($item && $replace) {
            $message .= ' '.($number ?: 'The purchase order').' is owed '.($count === 1 ? 'it' : 'them').' again.';
        } elseif ($item && filled($data['credit_note'] ?? null)) {
            $message .= ' Credit note '.$data['credit_note'].' is on file; '.($number ?: 'the order').' stays received.';
        }

        return redirect()
            ->route('service-hold.index')
            ->with('success', $message);
    }

    /** Ask why a fleet unit (or a line's units) is going back to service hold. */
    public function showReopen(Request $request, Product $product): View
    {
        $unit = $this->committedUnit($request, $product);

        return $this->unitsView(
            'service-hold.reopen',
            collect([$unit]),
            'Return '.($unit->equipment_number ?: 'this unit').' to service hold',
            route('service-hold.reopen', $unit),
        );
    }

    public function showReopenLine(Request $request, PurchaseOrderItem $item): View
    {
        $units = $this->committedUnitsForLine($request, $item);

        return $this->unitsView(
            'service-hold.reopen',
            $units,
            'Return '.$units->count().' '.Str::plural('unit', $units->count()).' to service hold',
            route('service-hold.line.reopen', $item),
        );
    }

    public function reopenUnit(Request $request, Product $product): RedirectResponse
    {
        return $this->reopen($request, collect([$this->committedUnit($request, $product)]));
    }

    public function reopenLine(Request $request, PurchaseOrderItem $item): RedirectResponse
    {
        return $this->reopen($request, $this->committedUnitsForLine($request, $item));
    }

    /**
     * Take a fleet unit back out of service so its tag or catalogue match can be
     * corrected: it keeps its number, serial and rates until it is committed
     * again, but it is no longer bookable in the meantime.
     */
    private function reopen(Request $request, Collection $units): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        $this->assertNotInUse($units);

        $first = $units->first();
        $modelName = $first->equipmentModel?->name;
        $returned = $this->unitTags($units);

        DB::transaction(function () use ($units) {
            foreach ($units as $unit) {
                $unit->update([
                    'fleet_status' => Product::FLEET_SERVICE_HOLD,
                    'is_active' => false,
                ]);
            }
        });

        AuditLog::record('service_hold.reopened', [
            'purchase_order' => $first->purchase_order,
            'line' => $first->purchaseOrderItem?->description,
            'catalogue_model' => $modelName,
            'units' => $returned,
            'reason' => $data['reason'],
        ], $request->user(), $first->business_id, $first);

        $count = $units->count();

        return redirect()
            ->route('service-hold.index')
            ->with('success', $count.' '.($count === 1 ? 'unit is' : 'units are').' back in service hold, ready to set up again.');
    }

    /**
     * Equipment already promised to a customer cannot be pulled out of the
     * bookable fleet: that would leave a live agreement or a reservation with
     * nothing to hand over.
     */
    private function assertNotInUse(Collection $units): void
    {
        $productIds = $units->pluck('id')->all();

        $onHire = RentalItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('contract', fn ($query) => $query->whereNotIn('status', ['returned', 'cancelled']))
            ->exists();

        $reserved = Reservation::query()
            ->blocking()
            ->whereIn('product_id', $productIds)
            ->exists();

        if ($onHire || $reserved) {
            throw ValidationException::withMessages([
                'units' => 'A unit that is out on hire or held by a reservation cannot go back to service hold.',
            ]);
        }
    }

    private function validateSetup(Request $request): array
    {
        return $request->validate([
            'name' => 'required|max:200',
            'manufacturer' => 'nullable|max:150',
            'model_number' => 'nullable|max:150',
            'category' => 'nullable|max:100',
            'description' => 'nullable',
            'rental_price_hour' => 'nullable|numeric|min:0',
            'rental_price_day' => 'nullable|numeric|min:0',
            'rental_price_week' => 'nullable|numeric|min:0',
            'rental_price_month' => 'nullable|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'used_sale_price' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:1|max:600',
            'salvage_value' => 'nullable|numeric|min:0',
            'depreciation_started_on' => 'nullable|date',
        ]);
    }

    private function assertSalvageWithinCost(Collection $units, array $data): void
    {
        if (! isset($data['salvage_value'])) {
            return;
        }

        foreach ($units as $unit) {
            if ($unit->cost_price === null) {
                continue;
            }

            if ((float) $data['salvage_value'] > (float) $unit->cost_price) {
                throw ValidationException::withMessages([
                    'salvage_value' => 'The salvage value cannot be more than what this unit cost.',
                ]);
            }
        }
    }

    /**
     * Put the catalogue entry in place, then copy its terms onto every unit and
     * return it to the bookable fleet.
     *
     * @param  array<int, array{equipment_number: string, serial_number: ?string}>  $perUnit
     */
    private function commitUnits(
        Collection $units,
        array $data,
        array $perUnit,
        bool $updateDefaults,
    ): EquipmentModel {
        $first = $units->first();

        return DB::transaction(function () use ($units, $first, $data, $perUnit, $updateDefaults) {
            $model = $this->catalogueEntry($first, $data, $updateDefaults);

            // Joining a retired model puts it back in service: it is on the
            // fleet again, so the catalogue entry must not stay retired.
            if (! $model->is_active) {
                $model->update(['is_active' => true]);
            }

            foreach ($units as $unit) {
                $unit->update([
                    'equipment_model_id' => $model->id,
                    'equipment_number' => $perUnit[$unit->id]['equipment_number'],
                    'serial_number' => $perUnit[$unit->id]['serial_number'] ?? null,
                    'name' => $model->name,
                    'description' => $data['description'] ?? $model->description,
                    'category' => $model->category,
                    'manufacturer' => $model->manufacturer,
                    'model_number' => $model->model_number,
                    'rental_price_hour' => $model->rental_price_hour,
                    'rental_price_day' => $model->rental_price_day,
                    'rental_price_week' => $model->rental_price_week,
                    'rental_price_month' => $model->rental_price_month,
                    'deposit_amount' => $model->deposit_amount,
                    'used_sale_price' => $data['used_sale_price'] ?? null,
                    'useful_life_months' => $model->useful_life_months,
                    'salvage_value' => $model->salvage_value,
                    'depreciation_started_on' => $data['depreciation_started_on'] ?? $unit->purchase_date,
                    'stock_quantity' => 1,
                    'fleet_status' => Product::FLEET_ACTIVE,
                    'is_active' => true,
                ]);
            }

            return $model;
        });
    }

    /**
     * Units with the same name, manufacturer and model number share one
     * catalogue entry. An existing model is only changed when the user asked
     * for it; otherwise the unit adopts the terms already on the catalogue.
     */
    private function catalogueEntry(Product $unit, array $data, bool $updateDefaults): EquipmentModel
    {
        $model = $this->matchingModel($unit, $data);

        if ($model && ! $updateDefaults) {
            return $model;
        }

        $model ??= new EquipmentModel(['business_id' => $unit->business_id]);

        $model->fill([
            'name' => $data['name'],
            'manufacturer' => $data['manufacturer'] ?? null,
            'model_number' => $data['model_number'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'rental_price_hour' => $data['rental_price_hour'] ?? null,
            'rental_price_day' => $data['rental_price_day'] ?? null,
            'rental_price_week' => $data['rental_price_week'] ?? null,
            'rental_price_month' => $data['rental_price_month'] ?? null,
            'deposit_amount' => $data['deposit_amount'] ?? null,
            'default_cost_price' => $unit->cost_price,
            'useful_life_months' => $data['useful_life_months'] ?? null,
            'salvage_value' => $data['salvage_value'] ?? null,
            'is_active' => true,
        ])->save();

        return $model;
    }

    private function matchingModel(Product $unit, array $data): ?EquipmentModel
    {
        $query = EquipmentModel::query()
            ->where('business_id', $unit->business_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower((string) ($data['name'] ?? ''))]);

        foreach (['manufacturer', 'model_number'] as $field) {
            $value = $data[$field] ?? null;

            $value === null
                ? $query->whereNull($field)
                : $query->where($field, $value);
        }

        return $query->first();
    }

    private function heldUnit(Request $request, Product $product): Product
    {
        $this->assertTenant($request, $product->business_id);

        if (! $product->isRental() || ! $product->isInServiceHold()) {
            abort(404);
        }

        return $product;
    }

    /** A fleet unit that can be taken back to service hold for correction. */
    private function committedUnit(Request $request, Product $product): Product
    {
        $this->assertTenant($request, $product->business_id);

        if (! $product->isRental() || $product->isInServiceHold()) {
            abort(404);
        }

        return $product;
    }

    /** The fleet units from one purchase order line. */
    private function committedUnitsForLine(Request $request, PurchaseOrderItem $item): Collection
    {
        $order = $item->purchaseOrder;

        if (! $order) {
            abort(404);
        }

        $this->assertTenant($request, $order->business_id);

        $units = Product::query()
            ->where('business_id', $order->business_id)
            ->where('purchase_order_item_id', $item->id)
            ->where('product_type', 'rental')
            ->where('fleet_status', '!=', Product::FLEET_SERVICE_HOLD)
            ->orderBy('equipment_number')
            ->get();

        if ($units->isEmpty()) {
            abort(404);
        }

        return $units;
    }

    /** The units still in service hold from one purchase order line. */
    private function heldUnitsForLine(Request $request, PurchaseOrderItem $item): Collection
    {
        $order = $item->purchaseOrder;

        if (! $order) {
            abort(404);
        }

        $this->assertTenant($request, $order->business_id);

        $units = Product::query()
            ->where('business_id', $order->business_id)
            ->where('purchase_order_item_id', $item->id)
            ->where('product_type', 'rental')
            ->inServiceHold()
            ->orderBy('equipment_number')
            ->get();

        if ($units->isEmpty()) {
            abort(404);
        }

        return $units;
    }

    /** Another tenant's records are invisible; a super admin may look at any. */
    private function assertTenant(Request $request, int $businessId): void
    {
        if ($request->user()->role !== 'super_admin'
            && $businessId !== $request->user()->business_id) {
            abort(404);
        }
    }

    /** The asset tags an audit entry keeps, so a later rename cannot rewrite history. */
    private function unitTags(Collection $units): array
    {
        return $units->map(fn (Product $unit) => [
            'equipment_number' => $unit->equipment_number,
            'serial_number' => $unit->serial_number,
        ])->values()->all();
    }

    private function businessId(Request $request): int
    {
        $businessId = $request->user()->business_id;

        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        return (int) $businessId;
    }
}
