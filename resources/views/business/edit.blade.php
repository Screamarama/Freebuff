<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Business profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <p class="text-sm text-gray-500">
                    These details appear on your rental agreements — the Owner name and address in the parties clause,
                    your contact details, and the state named in the governing law section.
                </p>

                <form method="POST" action="{{ route('business.update') }}" class="mt-6 space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="name" value="Business name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                          value="{{ old('name', $business->name) }}" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Contact email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                          value="{{ old('email', $business->email) }}" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="phone" value="Phone" />
                            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                          value="{{ old('phone', $business->phone) }}" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="address" value="Address" />
                            <textarea id="address" name="address" rows="2"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('address', $business->address) }}</textarea>
                            <x-input-error :messages="$errors->get('address')" class="mt-2" />
                        </div>

                        <x-state-select :country="$business->country ?? 'US'" :selected="$business->state" />

                        <x-currency-select :selected="$business->currency"
                                           hint="Used on new charges and receipts. Existing ones keep the currency they were made in." />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save changes') }}</x-primary-button>
                        <a href="{{ route('dashboard') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
