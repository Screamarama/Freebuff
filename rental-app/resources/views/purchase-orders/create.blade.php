<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $type === 'rental' ? __('New rental purchase order') : __('New sales purchase order') }}
            </h2>
            <a href="{{ route('purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('purchase-orders.store') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                    <p class="text-sm text-gray-600">
                        @if ($type === 'rental')
                            Describe the equipment you are buying. Each unit received lands in service hold, where you set its
                            rental rates, salvage value and used sales price before committing it to the fleet.
                        @else
                            Receiving this order adds the units to the stock and available quantity of each sales item.
                        @endif
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="supplier_name" value="Supplier" />
                            <x-text-input id="supplier_name" name="supplier_name" type="text" class="mt-1 block w-full"
                                          list="known-suppliers" value="{{ old('supplier_name') }}" placeholder="Who is this ordered from?" />
                            <datalist id="known-suppliers">
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier }}"></option>
                                @endforeach
                            </datalist>
                            <x-input-error :messages="$errors->get('supplier_name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="notes" value="Notes (optional)" />
                            <x-text-input id="notes" name="notes" type="text" class="mt-1 block w-full" value="{{ old('notes') }}" />
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="ordered_on" value="Order date" />
                            <x-text-input id="ordered_on" name="ordered_on" type="date" class="mt-1 block w-full"
                                          value="{{ old('ordered_on', now()->toDateString()) }}" />
                            <x-input-error :messages="$errors->get('ordered_on')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="expected_on" value="Expected date (optional)" />
                            <x-text-input id="expected_on" name="expected_on" type="date" class="mt-1 block w-full"
                                          value="{{ old('expected_on') }}" />
                            <x-input-error :messages="$errors->get('expected_on')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Lines</h3>
                        <button type="button" id="add-line" class="text-sm text-indigo-600 hover:text-indigo-900">+ Add line</button>
                    </div>

                    @if ($type !== 'rental' && $products->isEmpty())
                        <p class="mt-4 text-sm text-gray-500">
                            There are no sales items yet.
                            <a href="{{ route('products.create', ['type' => 'sale']) }}" class="text-indigo-600 hover:text-indigo-900">Add one first</a>.
                        </p>
                    @endif

                    <x-input-error :messages="$errors->get('items')" class="mt-2" />
                    <x-input-error :messages="$errors->get('items.*')" class="mt-2" />

                    <div id="lines" class="mt-4 space-y-4">
                        <div class="line grid gap-3 sm:grid-cols-12 sm:items-end border border-gray-200 rounded-md p-3">
                            <div class="sm:col-span-6">
                                <label class="block text-xs font-medium text-gray-600">{{ $type === 'rental' ? 'Equipment' : 'Item' }}</label>
                                @if ($type === 'rental')
                                    <input type="text" name="items[0][description]" maxlength="150"
                                           value="{{ old('items.0.description') }}"
                                           placeholder="e.g. CAT 320 GC excavator"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                @else
                                    <select name="items[0][product_id]" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="">Choose an item</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}" @selected(old('items.0.product_id') == $product->id)>
                                                {{ $product->name }}{{ $product->sku ? ' — '.$product->sku : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-medium text-gray-600">Quantity</label>
                                <input type="number" name="items[0][quantity_ordered]" min="1" value="{{ old('items.0.quantity_ordered', 1) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            </div>
                            <div class="sm:col-span-3">
                                <label class="block text-xs font-medium text-gray-600">Unit cost</label>
                                <input type="number" step="0.01" min="0" name="items[0][unit_cost]" value="{{ old('items.0.unit_cost') }}"
                                       placeholder="{{ $type === 'rental' ? 'Purchase cost' : 'List cost' }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                            </div>
                            <div class="sm:col-span-1">
                                <button type="button" class="remove-line text-sm text-red-600 hover:text-red-800">Remove</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>{{ __('Create purchase order') }}</x-primary-button>
                    <a href="{{ route('purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <template id="line-template">
        <div class="line grid gap-3 sm:grid-cols-12 sm:items-end border border-gray-200 rounded-md p-3">
            <div class="sm:col-span-6">
                <label class="block text-xs font-medium text-gray-600">{{ $type === 'rental' ? 'Equipment' : 'Item' }}</label>
                @if ($type === 'rental')
                    <input type="text" data-field="description" maxlength="150" placeholder="e.g. CAT 320 GC excavator"
                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                @else
                    <select data-field="product_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">Choose an item</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">
                                {{ $product->name }}{{ $product->sku ? ' — '.$product->sku : '' }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-600">Quantity</label>
                <input type="number" data-field="quantity_ordered" min="1" value="1"
                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
            </div>
            <div class="sm:col-span-3">
                <label class="block text-xs font-medium text-gray-600">Unit cost</label>
                <input type="number" step="0.01" min="0" data-field="unit_cost" placeholder="{{ $type === 'rental' ? 'Purchase cost' : 'List cost' }}"
                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
            </div>
            <div class="sm:col-span-1">
                <button type="button" class="remove-line text-sm text-red-600 hover:text-red-800">Remove</button>
            </div>
        </div>
    </template>

    <script>
        (function () {
            const lines = document.getElementById('lines');
            const template = document.getElementById('line-template');

            function renumber() {
                lines.querySelectorAll('.line').forEach((line, index) => {
                    line.querySelectorAll('[data-field]').forEach((field) => {
                        field.name = `items[${index}][${field.dataset.field}]`;
                    });
                });
            }

            document.getElementById('add-line').addEventListener('click', function () {
                lines.appendChild(template.content.cloneNode(true));
                renumber();
            });

            lines.addEventListener('click', function (event) {
                const button = event.target.closest('.remove-line');
                if (!button) return;

                // Keep one row so the form always has somewhere to type.
                if (lines.querySelectorAll('.line').length > 1) {
                    button.closest('.line').remove();
                    renumber();
                }
            });
        })();
    </script>
</x-app-layout>
