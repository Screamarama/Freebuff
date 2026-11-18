<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit tenant') }} — {{ $tenant->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="p-4 bg-red-100 border border-red-200 rounded-md text-sm text-red-800">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <p class="text-sm text-gray-500">
                    These details appear on the tenant's rental agreements and printed statements.
                    Changing the currency does not restate charges and receipts already recorded.
                </p>

                <form method="POST" action="{{ route('tenants.update', $tenant) }}" class="mt-6 space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="name" value="Business name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                          value="{{ old('name', $tenant->name) }}" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Contact email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                          value="{{ old('email', $tenant->email) }}" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="phone" value="Phone" />
                            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                          value="{{ old('phone', $tenant->phone) }}" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="address" value="Address" />
                            <textarea id="address" name="address" rows="2"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('address', $tenant->address) }}</textarea>
                            <x-input-error :messages="$errors->get('address')" class="mt-2" />
                        </div>

                        <x-country-state-select :country="$tenant->country ?? 'US'" :state="$tenant->state" />

                        <div class="sm:col-span-2">
                            <x-input-label for="city" value="City" />
                            <x-text-input id="city" name="city" type="text" class="mt-1 block w-full"
                                          value="{{ old('city', $tenant->city) }}" maxlength="100" />
                            <x-input-error :messages="$errors->get('city')" class="mt-2" />
                        </div>

                        <x-currency-select :selected="$tenant->currency" />

                        <x-tax-fields :state-sales="$tenant->state_sales_tax"
                                      :county-sales="$tenant->county_sales_tax"
                                      :city-sales="$tenant->city_sales_tax"
                                      :vat="$tenant->vat" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save changes') }}</x-primary-button>
                        <a href="{{ route('tenants.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
