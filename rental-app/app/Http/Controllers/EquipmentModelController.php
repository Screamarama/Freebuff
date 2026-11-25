<?php

namespace App\Http\Controllers;

use App\Models\EquipmentModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('equipment-models.edit', ['model' => $equipmentModel]);
    }

    public function update(Request $request, EquipmentModel $equipmentModel): RedirectResponse
    {
        $this->ensureSameTenant($request, $equipmentModel);

        $validated = $this->validateModel($request);
        $validated['is_active'] = $request->boolean('is_active');

        $equipmentModel->update($validated);

        return redirect()
            ->route('equipment-models.index')
            ->with('success', 'Equipment model updated. Existing units keep the details they were bought with.');
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
