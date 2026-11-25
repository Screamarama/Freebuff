@php
    $model = $model ?? null;
@endphp

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <x-input-label for="name" value="Model name" />
        <x-text-input id="name" name="name" type="text" maxlength="200" class="mt-1 block w-full"
                      value="{{ old('name', $model?->name) }}" placeholder="e.g. 320 GC Excavator" required />
        <p class="mt-1 text-xs text-gray-500">What the office calls this make and model.</p>
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="manufacturer" value="Manufacturer" />
        <x-text-input id="manufacturer" name="manufacturer" type="text" maxlength="150"
                      class="mt-1 block w-full" value="{{ old('manufacturer', $model?->manufacturer) }}"
                      placeholder="e.g. Caterpillar" />
        <x-input-error :messages="$errors->get('manufacturer')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="model_number" value="Model number" />
        <x-text-input id="model_number" name="model_number" type="text" maxlength="150"
                      class="mt-1 block w-full" value="{{ old('model_number', $model?->model_number) }}"
                      placeholder="e.g. 320 GC" />
        <x-input-error :messages="$errors->get('model_number')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="category" value="Category" />
        <x-text-input id="category" name="category" type="text" maxlength="100" class="mt-1 block w-full"
                      value="{{ old('category', $model?->category) }}" placeholder="e.g. Earthmoving" />
        <x-input-error :messages="$errors->get('category')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="deposit_amount" value="Default deposit" />
        <x-text-input id="deposit_amount" name="deposit_amount" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('deposit_amount', $model?->deposit_amount) }}" />
        <x-input-error :messages="$errors->get('deposit_amount')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="description" value="Description" />
        <textarea id="description" name="description" rows="3"
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $model?->description) }}</textarea>
        <x-input-error :messages="$errors->get('description')" class="mt-2" />
    </div>

    <div class="sm:col-span-2 border-t border-gray-200 pt-2">
        <h3 class="text-sm font-semibold text-gray-700">Default rental rates</h3>
        <p class="mt-1 text-xs text-gray-500">Copied onto each unit when it is created or re-saved; a unit can be given its own price later only by changing the model.</p>
    </div>

    <div>
        <x-input-label for="rental_price_hour" value="Rental price / hour" />
        <x-text-input id="rental_price_hour" name="rental_price_hour" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('rental_price_hour', $model?->rental_price_hour) }}" />
        <x-input-error :messages="$errors->get('rental_price_hour')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="rental_price_day" value="Rental price / day" />
        <x-text-input id="rental_price_day" name="rental_price_day" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('rental_price_day', $model?->rental_price_day) }}" />
        <x-input-error :messages="$errors->get('rental_price_day')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="rental_price_week" value="Rental price / week" />
        <x-text-input id="rental_price_week" name="rental_price_week" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('rental_price_week', $model?->rental_price_week) }}" />
        <x-input-error :messages="$errors->get('rental_price_week')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="rental_price_month" value="Rental price / month" />
        <x-text-input id="rental_price_month" name="rental_price_month" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('rental_price_month', $model?->rental_price_month) }}" />
        <x-input-error :messages="$errors->get('rental_price_month')" class="mt-2" />
    </div>

    <div class="sm:col-span-2 border-t border-gray-200 pt-2">
        <h3 class="text-sm font-semibold text-gray-700">Purchase and depreciation defaults</h3>
        <p class="mt-1 text-xs text-gray-500">New units start from these; a purchase order line can override them.</p>
    </div>

    <div>
        <x-input-label for="default_cost_price" value="Typical purchase price" />
        <x-text-input id="default_cost_price" name="default_cost_price" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('default_cost_price', $model?->default_cost_price) }}" />
        <p class="mt-1 text-xs text-gray-500">Pre-fills the unit cost on purchase orders.</p>
        <x-input-error :messages="$errors->get('default_cost_price')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="useful_life_months" value="Useful life (months)" />
        <x-text-input id="useful_life_months" name="useful_life_months" type="number" step="1" min="1" max="600"
                      class="mt-1 block w-full" value="{{ old('useful_life_months', $model?->useful_life_months) }}"
                      placeholder="e.g. 60" />
        <p class="mt-1 text-xs text-gray-500">60 months is five years.</p>
        <x-input-error :messages="$errors->get('useful_life_months')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="salvage_value" value="Salvage value" />
        <x-text-input id="salvage_value" name="salvage_value" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('salvage_value', $model?->salvage_value) }}"
                      placeholder="0.00" />
        <p class="mt-1 text-xs text-gray-500">What a unit is expected to be worth at the end of its life.</p>
        <x-input-error :messages="$errors->get('salvage_value')" class="mt-2" />
    </div>

    @if ($model)
        <div class="sm:col-span-2">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $model->is_active))
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                Active and available for new units and purchase orders
            </label>
        </div>
    @endif
</div>
