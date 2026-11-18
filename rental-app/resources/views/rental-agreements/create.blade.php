<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New rental agreement') }}
        </h2>
    </x-slot>


    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-6 max-w-3xl p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('rental-agreements.store') }}" class="space-y-6">
                    @csrf

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <x-input-label for="customer_id" value="Customer" />
                            <button type="submit"
                                    formaction="{{ route('rental-agreements.create.customer') }}"
                                    formnovalidate
                                    class="text-sm font-medium text-indigo-600 hover:text-indigo-900">
                                + New customer
                            </button>
                        </div>
                        <select id="customer_id" name="customer_id" required
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Choose a customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id', $draft['customer_id']) == $customer->id)>
                                    {{ $customer->full_name }} ({{ $customer->customer_code }})
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="start_date" value="Start date" />
                            <x-text-input id="start_date" name="start_date" type="date" class="mt-1 block w-full"
                                          value="{{ old('start_date', $draft['start_date']) }}" required />
                            <x-input-error :messages="$errors->get('start_date')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="end_date" value="End date" />
                            <x-text-input id="end_date" name="end_date" type="date" class="mt-1 block w-full"
                                          value="{{ old('end_date', $draft['end_date']) }}" required />
                            <x-input-error :messages="$errors->get('end_date')" class="mt-2" />
                        </div>
                    </div>

                    <div class="rounded-md border border-gray-200 p-4 sm:p-5">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Items (optional)</h3>
                            <p class="text-xs text-gray-500">Add as many lines as you need, or add more later.</p>
                        </div>

                        <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-2">
                                    <h4 class="text-sm font-medium text-gray-700">Rental items</h4>
                                    <button type="button" data-add-row="rental"
                                            class="text-sm font-medium text-indigo-600 hover:text-indigo-900">
                                        + Add rental item
                                    </button>
                                </div>

                                <div id="rental-rows" data-next-index="{{ $nextRentalIndex }}" class="space-y-3">
                                    @foreach ($rentalRows as $index => $values)
                                        <x-agreement-item-row kind="rental" :index="$index"
                                                              :products="$rentalProducts" :values="$values"
                                                              :default-duration="$defaultRentalDuration" />
                                    @endforeach
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-2">
                                    <h4 class="text-sm font-medium text-gray-700">Sale items</h4>
                                    <button type="button" data-add-row="sale"
                                            class="text-sm font-medium text-indigo-600 hover:text-indigo-900">
                                        + Add sale item
                                    </button>
                                </div>

                                <div id="sale-rows" data-next-index="{{ $nextSaleIndex }}" class="space-y-3">
                                    @foreach ($saleRows as $index => $values)
                                        <x-agreement-item-row kind="sale" :index="$index"
                                                              :products="$saleProducts" :values="$values" />
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="notes" value="Notes" />
                        <textarea id="notes" name="notes" rows="3"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes', $draft['notes']) }}</textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create agreement</x-primary-button>
                        <a href="{{ route('rental-agreements.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <template id="rental-row-template">
        <x-agreement-item-row kind="rental" index="__INDEX__" :products="$rentalProducts"
                             :default-duration="$defaultRentalDuration" />
    </template>

    <template id="sale-row-template">
        <x-agreement-item-row kind="sale" index="__INDEX__" :products="$saleProducts" />
    </template>

</x-app-layout>
