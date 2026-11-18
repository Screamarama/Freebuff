<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New tenant') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('tenants.store') }}" class="space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="name" value="Business name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                          value="{{ old('name') }}" required />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Contact email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                          value="{{ old('email') }}" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="phone" value="Phone" />
                            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                          value="{{ old('phone') }}" />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="address" value="Address" />
                            <textarea id="address" name="address" rows="2"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('address') }}</textarea>
                            <x-input-error :messages="$errors->get('address')" class="mt-2" />
                        </div>

                        <x-country-state-select :country="'US'" />

                        <div class="sm:col-span-2">
                            <x-input-label for="city" value="City" />
                            <x-text-input id="city" name="city" type="text" class="mt-1 block w-full"
                                          value="{{ old('city') }}" maxlength="100" />
                            <x-input-error :messages="$errors->get('city')" class="mt-2" />
                        </div>

                        <x-currency-select :selected="'USD'" />

                        <x-tax-fields />
                    </div>

                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wide">Admin account</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            An admin user is created for this tenant so someone can sign in right away.
                        </p>

                        <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <x-input-label for="admin_name" value="Name" />
                                <x-text-input id="admin_name" name="admin_name" type="text" class="mt-1 block w-full"
                                              value="{{ old('admin_name') }}" required />
                                <x-input-error :messages="$errors->get('admin_name')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="admin_email" value="Email" />
                                <x-text-input id="admin_email" name="admin_email" type="email" class="mt-1 block w-full"
                                              value="{{ old('admin_email') }}" required />
                                <x-input-error :messages="$errors->get('admin_email')" class="mt-2" />
                            </div>

                            <div class="sm:col-span-2">
                                <x-input-label for="password" value="Password" />
                                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full"
                                              required />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create tenant</x-primary-button>
                        <a href="{{ route('tenants.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>