<?php

namespace App\Http\Controllers;

use App\Models\EquipmentModel;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        if ($request->user()->role !== 'super_admin'
            && $product->business_id !== $request->user()->business_id) {
            abort(404);
        }

        if (! $product->isRental() || ! $product->isInServiceHold()) {
            abort(404);
        }

        return $product;
    }

    /** The units still in service hold from one purchase order line. */
    private function heldUnitsForLine(Request $request, PurchaseOrderItem $item): Collection
    {
        $order = $item->purchaseOrder;

        if (! $order) {
            abort(404);
        }

        if ($request->user()->role !== 'super_admin'
            && $order->business_id !== $request->user()->business_id) {
            abort(404);
        }

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

    private function businessId(Request $request): int
    {
        $businessId = $request->user()->business_id;

        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        return (int) $businessId;
    }
}
