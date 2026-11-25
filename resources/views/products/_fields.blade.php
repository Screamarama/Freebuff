@php
    $product = $product ?? null;
    $models = $models ?? collect();
    $purchaseDate = old('purchase_date', $product?->purchase_date?->format('Y-m-d'));
    $depreciationStart = old('depreciation_started_on', $product?->depreciation_started_on?->format('Y-m-d'));
@endphp

<div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
    @if ($type === 'rental')
        <div class="sm:col-span-2">
            <x-input-label for="equipment_model_id" value="Equipment model" />

            @if ($models->isEmpty())
                <p class="mt-1 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    No equipment models yet.
                    <a href="{{ route('equipment-models.create') }}" class="font-semibold underline">Create a model first</a>
                    — it holds the make, rates and depreciation terms this unit copies.
                </p>
            @else
                <select id="equipment_model_id" name="equipment_model_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    <option value="">Choose a model</option>
                    @foreach ($models as $model)
                        <option value="{{ $model->id }}" @selected(old('equipment_model_id', $product?->equipment_model_id) == $model->id)>
                            {{ $model->name }}{{ $model->label() !== $model->name ? ' — '.$model->label() : '' }}{{ $model->is_active ? '' : ' (retired)' }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">
                    Rates, useful life and salvage value come from the model.
                    <a href="{{ route('equipment-models.index') }}" class="text-indigo-600 hover:text-indigo-900">Manage models</a>
                </p>
            @endif

            <x-input-error :messages="$errors->get('equipment_model_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="equipment_number" value="Equipment number" />
            <x-text-input id="equipment_number" name="equipment_number" type="text" maxlength="50"
                          class="mt-1 block w-full"
                          value="{{ old('equipment_number', $product?->equipment_number) }}" required />
            <x-input-error :messages="$errors->get('equipment_number')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="serial_number" value="Serial number" />
            <x-text-input id="serial_number" name="serial_number" type="text" maxlength="150"
                          class="mt-1 block w-full" value="{{ old('serial_number', $product?->serial_number) }}"
                          placeholder="Manufacturer serial / VIN" />
            <x-input-error :messages="$errors->get('serial_number')" class="mt-2" />
        </div>
    @else
        <div>
            <x-input-label for="sku" value="SKU" />
            <x-text-input id="sku" name="sku" type="text" maxlength="50" class="mt-1 block w-full"
                          value="{{ old('sku', $product?->sku) }}" placeholder="e.g. HAT-YEL" required />
            <x-input-error :messages="$errors->get('sku')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="manufacturer" value="Manufacturer" />
            <x-text-input id="manufacturer" name="manufacturer" type="text" maxlength="150"
                          class="mt-1 block w-full" value="{{ old('manufacturer', $product?->manufacturer) }}"
                          placeholder="e.g. 3M" />
            <x-input-error :messages="$errors->get('manufacturer')" class="mt-2" />
        </div>
    @endif

    @if ($type === 'sale')
        <div class="sm:col-span-2">
            <x-input-label for="name" value="Name" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                          value="{{ old('name', $product?->name) }}" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
    @endif

    <div class="sm:col-span-2">
        <x-input-label for="description" value="{{ $type === 'rental' ? 'Unit notes (optional)' : 'Description' }}" />
        <textarea id="description" name="description" rows="3"
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $product?->description) }}</textarea>
        @if ($type === 'rental')
            <p class="mt-1 text-xs text-gray-500">Left blank, the model's description is used.</p>
        @endif
        <x-input-error :messages="$errors->get('description')" class="mt-2" />
    </div>

    @if ($type === 'sale')
        <div>
            <x-input-label for="category" value="Category" />
            <x-text-input id="category" name="category" type="text" maxlength="100" class="mt-1 block w-full"
                          value="{{ old('category', $product?->category) }}" />
            <x-input-error :messages="$errors->get('category')" class="mt-2" />
        </div>
    @endif

    <div class="sm:col-span-2 border-t border-gray-200 pt-2">
        <h3 class="text-sm font-semibold text-gray-700">Image</h3>
        @if ($type === 'rental')
            <p class="mt-1 text-xs text-gray-500">Photo of this unit. The model can be pictured separately.</p>
        @endif
    </div>

    <div class="sm:col-span-2 flex items-start gap-4">
        @if ($product?->image_source)
            <img src="{{ $product->image_source }}" alt="{{ $product->name }}"
                 class="h-20 w-20 rounded-md border border-gray-200 object-cover" />
        @endif

        <div class="flex-1">
            <x-input-label for="image" value="Upload image" />
            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp,image/gif"
                   class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-800 file:px-3 file:py-2 file:text-xs file:font-semibold file:uppercase file:tracking-widest file:text-white hover:file:bg-gray-700" />
            <p class="mt-1 text-xs text-gray-500">JPG, PNG, WebP or GIF, up to 5 MB.</p>
            <x-input-error :messages="$errors->get('image')" class="mt-2" />

            @if ($product?->image_path)
                <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="remove_image" value="1" @checked(old('remove_image'))
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Remove the uploaded image
                </label>
            @endif
        </div>
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="image_url" value="Or image URL" />
        <x-text-input id="image_url" name="image_url" type="url" class="mt-1 block w-full"
                      value="{{ old('image_url', $product?->image_url) }}" placeholder="https://" />
        <p class="mt-1 text-xs text-gray-500">Used only when no image is uploaded.</p>
        <x-input-error :messages="$errors->get('image_url')" class="mt-2" />
    </div>

    <div class="sm:col-span-2 border-t border-gray-200 pt-2">
        <h3 class="text-sm font-semibold text-gray-700">Purchase</h3>
    </div>

    <div>
        <x-input-label for="purchase_order" value="Purchase order" />
        <x-text-input id="purchase_order" name="purchase_order" type="text" maxlength="100"
                      class="mt-1 block w-full" value="{{ old('purchase_order', $product?->purchase_order) }}"
                      placeholder="e.g. PO-1042" />
        <x-input-error :messages="$errors->get('purchase_order')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="purchase_date" value="Purchase date" />
        <x-text-input id="purchase_date" name="purchase_date" type="date" class="mt-1 block w-full"
                      value="{{ $purchaseDate }}" />
        <x-input-error :messages="$errors->get('purchase_date')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="purchase_price" value="Purchase price" />
        <x-text-input id="purchase_price" name="purchase_price" type="number" step="0.01" min="0"
                      class="mt-1 block w-full" value="{{ old('purchase_price', $product?->cost_price) }}" />
        <x-input-error :messages="$errors->get('purchase_price')" class="mt-2" />
    </div>

    @if ($type === 'rental')
        <div>
            <x-input-label for="depreciation_started_on" value="Depreciation start" />
            <x-text-input id="depreciation_started_on" name="depreciation_started_on" type="date"
                          class="mt-1 block w-full" value="{{ $depreciationStart }}" />
            <p class="mt-1 text-xs text-gray-500">Leave blank to count from the purchase date. Life and salvage come from the model.</p>
            <x-input-error :messages="$errors->get('depreciation_started_on')" class="mt-2" />
        </div>
    @else
        <div class="sm:col-span-2 border-t border-gray-200 pt-2">
            <h3 class="text-sm font-semibold text-gray-700">Sales pricing and stock</h3>
        </div>

        <div>
            <x-input-label for="sale_price" value="Sale price" />
            <x-text-input id="sale_price" name="sale_price" type="number" step="0.01" min="0"
                          class="mt-1 block w-full" value="{{ old('sale_price', $product?->sale_price) }}" />
            <x-input-error :messages="$errors->get('sale_price')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="stock_quantity" value="Stock quantity" />
            <x-text-input id="stock_quantity" name="stock_quantity" type="number" min="0"
                          class="mt-1 block w-full" value="{{ old('stock_quantity', $product?->stock_quantity ?? 0) }}" />
            <x-input-error :messages="$errors->get('stock_quantity')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="available_quantity" value="Available quantity" />
            <x-text-input id="available_quantity" name="available_quantity" type="number" min="0"
                          class="mt-1 block w-full" value="{{ old('available_quantity', $product?->available_quantity ?? 0) }}" />
            <x-input-error :messages="$errors->get('available_quantity')" class="mt-2" />
        </div>
    @endif
</div>
