<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EquipmentModel;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EquipmentModelController extends Controller
{
    public function index(Request $request): View
    {
        $models = EquipmentModel::query()
            ->where('business_id', $this->businessId($request))
            ->withCount('products')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('manufacturer', 'like', '%'.$search.'%')
                        ->orWhere('model_number', 'like', '%'.$search.'%')
                        ->orWhere('category', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('equipment-models.index', compact('models'));
    }

    public function create(): View
    {
        return view('equipment-models.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateModel($request);

        $validated['business_id'] = $this->businessId($request);
        $validated['is_active'] = true;

        EquipmentModel::create($validated);

        return redirect()
            ->route('equipment-models.index')
            ->with('success', 'Equipment model created.');
    }

    public function edit(Request $request, EquipmentModel $equipmentModel): View
    {
        $this->ensureSameTenant($request, $equipmentModel);

        $units = $equipmentModel->products()
            ->where('fleet_status', Product::FLEET_ACTIVE)
            ->orderBy('equipment_number')
            ->get();

        return view('equipment-models.edit', [
            'model' => $equipmentModel,
            'units' => $units,
            'differing' => $units->filter(fn (Product $unit) => $this->termsDiffer($unit, $equipmentModel)),
        ]);
    }

    public function update(Request $request, EquipmentModel $equipmentModel): RedirectResponse
    {
        $this->ensureSameTenant($request, $equipmentModel);

        $validated = $this->validateModel($request);
        $validated['is_active'] = $request->boolean('is_active');

        $equipmentModel->update($validated);

        return redirect()
            ->route('equipment-models.index')
            ->with('success', 'Equipment model updated. Existing units keep the details they were bought with; apply the new terms from the units panel when they should follow.');
    }

    /**
     * Copy the model's current rates and depreciation terms onto the fleet units
     * still carrying older ones, so the catalogue governs what is hired out.
     */
    public function applyDefaults(Request $request, EquipmentModel $equipmentModel): RedirectResponse
    {
        $this->ensureSameTenant($request, $equipmentModel);

        $units = $equipmentModel->products()
            ->where('fleet_status', Product::FLEET_ACTIVE)
            ->orderBy('equipment_number')
            ->get()
            ->filter(fn (Product $unit) => $this->termsDiffer($unit, $equipmentModel))
            ->values();

        if ($units->isEmpty()) {
            return back()->with('success', 'Every unit already uses these terms.');
        }

        DB::transaction(function () use ($units, $equipmentModel) {
            foreach ($units as $unit) {
                $unit->update([
                    'rental_price_hour' => $equipmentModel->rental_price_hour,
                    'rental_price_day' => $equipmentModel->rental_price_day,
                    'rental_price_week' => $equipmentModel->rental_price_week,
                    'rental_price_month' => $equipmentModel->rental_price_month,
                    'deposit_amount' => $equipmentModel->deposit_amount,
                    'useful_life_months' => $equipmentModel->useful_life_months,
                    'salvage_value' => $equipmentModel->salvage_value,
                ]);
            }
        });

        AuditLog::record('equipment_model.defaults_applied', [
            'catalogue_model' => $equipmentModel->name,
            'units' => $units->pluck('equipment_number')->values()->all(),
            'rental_price_day' => $equipmentModel->rental_price_day,
            'useful_life_months' => $equipmentModel->useful_life_months,
            'salvage_value' => $equipmentModel->salvage_value,
        ], $request->user(), $equipmentModel->business_id, $equipmentModel);

        $count = $units->count();

        return back()->with(
            'success',
            $count.' '.($count === 1 ? 'unit now uses' : 'units now use')." this model's terms."
        );
    }

    /** Whether a unit still carries a rate or depreciation term the model does not. */
    private function termsDiffer(Product $unit, EquipmentModel $model): bool
    {
        foreach (['rental_price_hour', 'rental_price_day', 'rental_price_week', 'rental_price_month',
            'deposit_amount', 'useful_life_months', 'salvage_value'] as $field) {
            if (! $this->sameNumber($unit->{$field}, $model->{$field})) {
                return true;
            }
        }

        return false;
    }

    /** Nulls only match nulls; values are compared as numbers. */
    private function sameNumber(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return (float) $left === (float) $right;
    }

    /** Retire a model without touching the units already in the fleet. */
    public function destroy(Request $request, EquipmentModel $equipmentModel): RedirectResponse
    {
        $this->ensureSameTenant($request, $equipmentModel);

        $equipmentModel->update(['is_active' => false]);

        return redirect()
            ->route('equipment-models.index')
            ->with('success', 'Equipment model retired.');
    }

    private function validateModel(Request $request): array
    {
        $validated = $request->validate([
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
            'default_cost_price' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:1|max:600',
            'salvage_value' => 'nullable|numeric|min:0',
        ]);

        if (isset($validated['salvage_value'], $validated['default_cost_price'])
            && (float) $validated['salvage_value'] > (float) $validated['default_cost_price']) {
            throw ValidationException::withMessages([
                'salvage_value' => 'The salvage value cannot be more than the typical purchase price.',
            ]);
        }

        return $validated;
    }

    private function businessId(Request $request): int
    {
        $businessId = $request->user()->business_id;

        if (! $businessId) {
            abort(403, 'No tenant assigned to your account.');
        }

        return (int) $businessId;
    }

    private function ensureSameTenant(Request $request, EquipmentModel $model): void
    {
        if ($request->user()->role === 'super_admin') {
            return;
        }

        if ($model->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }
}
