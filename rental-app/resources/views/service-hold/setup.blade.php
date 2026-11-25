<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Set up {{ $unit->name ?: 'equipment' }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    In service hold since {{ $unit->purchase_date?->format('M j, Y') ?: 'receipt' }}
                    @if ($unit->purchase_order) · {{ $unit->purchase_order }} @endif
                </p>
            </div>
            <a href="{{ route('service-hold.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Back to service hold</a>
        </div>
    </x-slot>

    @php
        $updateDefaults = (bool) old('update_model_defaults', false);
        $hideDefaults = $matching && ! $updateDefaults;
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <div class="mb-6 grid gap-4 sm:grid-cols-3 rounded-md bg-gray-50 p-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Purchase order</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $unit->purchase_order ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Unit cost</dt>
                        <dd class="mt-1 text-gray-900">
                            @if ($unit->cost_price !== null)
                                <x-money :amount="$unit->cost_price" />
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Received</dt>
                        <dd class="mt-1 text-gray-900">{{ $unit->purchase_date?->format('M j, Y') ?: '—' }}</dd>
                    </div>
                </div>

                <form method="POST" action="{{ route('service-hold.commit', $unit) }}" class="space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2 border-b border-gray-200 pb-2">
                            <h3 class="text-sm font-semibold text-gray-700">Unit</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                The temporary tag comes from the purchase order; replace it with your own asset number.
                            </p>
                        </div>

                        <div>
                            <x-input-label for="equipment_number" value="Equipment number" />
                            <x-text-input id="equipment_number" name="equipment_number" type="text" maxlength="50"
                                          class="mt-1 block w-full" value="{{ old('equipment_number', $unit->equipment_number) }}" required />
                            <x-input-error :messages="$errors->get('equipment_number')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="serial_number" value="Serial number" />
                            <x-text-input id="serial_number" name="serial_number" type="text" maxlength="150"
                                          class="mt-1 block w-full" value="{{ old('serial_number', $unit->serial_number) }}" />
                            <x-input-error :messages="$errors->get('serial_number')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2 border-b border-gray-200 pb-2">
                            <h3 class="text-sm font-semibold text-gray-700">Catalogue entry</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                Matched on name, manufacturer and model number. Matching an existing model joins it
                                instead of creating a duplicate.
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" type="text" maxlength="200" class="mt-1 block w-full"
                                          value="{{ old('name', $unit->name) }}" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="manufacturer" value="Manufacturer" />
                            <x-text-input id="manufacturer" name="manufacturer" type="text" maxlength="150"
                                          class="mt-1 block w-full" value="{{ old('manufacturer', $unit->manufacturer) }}"
                                          placeholder="e.g. Caterpillar" />
                            <x-input-error :messages="$errors->get('manufacturer')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="model_number" value="Model number" />
                            <x-text-input id="model_number" name="model_number" type="text" maxlength="150"
                                          class="mt-1 block w-full" value="{{ old('model_number', $unit->model_number) }}"
                                          placeholder="e.g. 320 GC" />
                            <x-input-error :messages="$errors->get('model_number')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="category" value="Category" />
                            <x-text-input id="category" name="category" type="text" maxlength="100"
                                          class="mt-1 block w-full" value="{{ old('category', $unit->category) }}"
                                          placeholder="e.g. Earthmoving" />
                            <x-input-error :messages="$errors->get('category')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <div id="model-match"
                                 class="rounded-md border px-3 py-2 text-sm {{ $matching ? 'border-indigo-200 bg-indigo-50 text-indigo-900' : 'border-gray-200 bg-gray-50 text-gray-600' }}"
                                 data-empty="No catalogue model matches yet — this unit will create a new one."
                                 data-joining="Joins existing model:"
                                 data-keep="its catalogue rates and depreciation terms apply"
                                 data-update="its defaults will be updated from the values below."
                                 data-retired="This model is retired; committing returns it to the catalogue.">
                                @if ($matching)
                                    Joins existing model: {{ $matching->label() }} ({{ $matching->name }})
                                    — {{ $updateDefaults ? 'its defaults will be updated from the values below.' : 'its catalogue rates and depreciation terms apply — '.$matchingSummary.'.' }}
                                    @unless ($matching->is_active)
                                        This model is retired; committing returns it to the catalogue.
                                    @endunless
                                @else
                                    No catalogue model matches yet — this unit will create a new one.
                                @endif
                            </div>
                        </div>

                        <div id="update-model-wrap" class="sm:col-span-2 rounded-md border border-gray-200 bg-gray-50 p-3 {{ $matching ? '' : 'hidden' }}">
                            <label class="flex items-start gap-2 text-sm text-gray-700">
                                <input type="checkbox" id="update_model_defaults" name="update_model_defaults" value="1"
                                       @checked($updateDefaults)
                                       class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                <span>
                                    Update this model's catalogue defaults
                                    <span class="block text-xs text-gray-500">
                                        Left unticked, the model keeps its current rates, useful life and salvage value.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="description" value="Description (optional)" />
                            <textarea id="description" name="description" rows="2"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('description', $unit->description) }}</textarea>
                            <x-input-error :messages="$errors->get('description')" class="mt-2" />
                        </div>

                        <div id="model-default-fields" class="sm:col-span-2 {{ $hideDefaults ? 'hidden' : '' }}">
                            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <div class="sm:col-span-2 border-b border-gray-200 pb-2">
                                    <h3 class="text-sm font-semibold text-gray-700">Catalogue rates and depreciation</h3>
                                    <p class="mt-1 text-xs text-gray-500" id="model-default-fields-note">
                                        These are saved as the model's defaults and copied to this unit.
                                    </p>
                                </div>

                                <div>
                                    <x-input-label for="rental_price_hour" value="Rental price / hour" />
                                    <x-text-input id="rental_price_hour" name="rental_price_hour" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" value="{{ old('rental_price_hour') }}" :disabled="$hideDefaults" />
                                    <x-input-error :messages="$errors->get('rental_price_hour')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="rental_price_day" value="Rental price / day" />
                                    <x-text-input id="rental_price_day" name="rental_price_day" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" value="{{ old('rental_price_day') }}" :disabled="$hideDefaults" />
                                    <x-input-error :messages="$errors->get('rental_price_day')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="rental_price_week" value="Rental price / week" />
                                    <x-text-input id="rental_price_week" name="rental_price_week" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" value="{{ old('rental_price_week') }}" :disabled="$hideDefaults" />
                                    <x-input-error :messages="$errors->get('rental_price_week')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="rental_price_month" value="Rental price / month" />
                                    <x-text-input id="rental_price_month" name="rental_price_month" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" value="{{ old('rental_price_month') }}" :disabled="$hideDefaults" />
                                    <x-input-error :messages="$errors->get('rental_price_month')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="deposit_amount" value="Default deposit" />
                                    <x-text-input id="deposit_amount" name="deposit_amount" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" value="{{ old('deposit_amount') }}" :disabled="$hideDefaults" />
                                    <x-input-error :messages="$errors->get('deposit_amount')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="useful_life_months" value="Useful life (months)" />
                                    <x-text-input id="useful_life_months" name="useful_life_months" type="number" step="1" min="1" max="600"
                                                  class="mt-1 block w-full" value="{{ old('useful_life_months') }}"
                                                  placeholder="e.g. 60" :disabled="$hideDefaults" />
                                    <p class="mt-1 text-xs text-gray-500">60 months is five years.</p>
                                    <x-input-error :messages="$errors->get('useful_life_months')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="salvage_value" value="Salvage value" />
                                    <x-text-input id="salvage_value" name="salvage_value" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" value="{{ old('salvage_value') }}" placeholder="0.00"
                                                  :disabled="$hideDefaults" />
                                    <p class="mt-1 text-xs text-gray-500">Expected value at the end of its life; drives book value.</p>
                                    <x-input-error :messages="$errors->get('salvage_value')" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <div class="sm:col-span-2 border-b border-gray-200 pb-2">
                            <h3 class="text-sm font-semibold text-gray-700">This unit</h3>
                        </div>

                        <div>
                            <x-input-label for="used_sale_price" value="Used sales price" />
                            <x-text-input id="used_sale_price" name="used_sale_price" type="number" step="0.01" min="0"
                                          class="mt-1 block w-full" value="{{ old('used_sale_price') }}" placeholder="Asking price when sold used" />
                            <x-input-error :messages="$errors->get('used_sale_price')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="depreciation_started_on" value="Depreciation start" />
                            <x-text-input id="depreciation_started_on" name="depreciation_started_on" type="date"
                                          class="mt-1 block w-full"
                                          value="{{ old('depreciation_started_on', $unit->purchase_date?->format('Y-m-d')) }}" />
                            <p class="mt-1 text-xs text-gray-500">Defaults to the purchase date.</p>
                            <x-input-error :messages="$errors->get('depreciation_started_on')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Commit to rental fleet</x-primary-button>
                        <a href="{{ route('service-hold.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @include('service-hold._match-script')
</x-app-layout>
