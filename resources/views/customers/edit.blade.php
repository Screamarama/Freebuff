<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit customer') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('customers.update', $customer) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <x-input-label for="customer_code" value="Customer code" />
                            <x-text-input id="customer_code" name="customer_code" type="text" class="mt-1 block w-full"
                                          value="{{ old('customer_code', $customer->customer_code) }}" required />
                            <x-input-error :messages="$errors->get('customer_code')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="phone" value="Phone" />
                            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full"
                                          value="{{ old('phone', $customer->phone) }}" required />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="first_name" value="First name" />
                            <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full"
                                          value="{{ old('first_name', $customer->first_name) }}" required />
                            <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="last_name" value="Last name" />
                            <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full"
                                          value="{{ old('last_name', $customer->last_name) }}" required />
                            <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                          value="{{ old('email', $customer->email) }}" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="id_number" value="ID number" />
                            <x-text-input id="id_number" name="id_number" type="text" class="mt-1 block w-full"
                                          value="{{ old('id_number', $customer->id_number) }}" />
                            <x-input-error :messages="$errors->get('id_number')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="date_of_birth" value="Date of birth" />
                            <x-text-input id="date_of_birth" name="date_of_birth" type="date" class="mt-1 block w-full"
                                          value="{{ old('date_of_birth', $customer->date_of_birth?->format('Y-m-d')) }}" />
                            <x-input-error :messages="$errors->get('date_of_birth')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="city" value="City" />
                            <x-text-input id="city" name="city" type="text" class="mt-1 block w-full"
                                          value="{{ old('city', $customer->city) }}" />
                            <x-input-error :messages="$errors->get('city')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="state" value="State" />
                            <x-text-input id="state" name="state" type="text" class="mt-1 block w-full"
                                          value="{{ old('state', $customer->state) }}" />
                            <x-input-error :messages="$errors->get('state')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="postal_code" value="Postal code" />
                            <x-text-input id="postal_code" name="postal_code" type="text" class="mt-1 block w-full"
                                          value="{{ old('postal_code', $customer->postal_code) }}" />
                            <x-input-error :messages="$errors->get('postal_code')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="country" value="Country" />
                            <x-text-input id="country" name="country" type="text" class="mt-1 block w-full"
                                          value="{{ old('country', $customer->country) }}" />
                            <x-input-error :messages="$errors->get('country')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="account_type" value="Account" />
                            <select id="account_type" name="account_type"
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="cash" @selected(old('account_type', $customer->account_type) === 'cash')>Cash — pays at the counter</option>
                                <option value="charge" @selected(old('account_type', $customer->account_type) === 'charge')>Charge account</option>
                            </select>
                            <x-input-error :messages="$errors->get('account_type')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="credit_limit" value="Credit limit" />
                            <x-text-input id="credit_limit" name="credit_limit" type="number" step="0.01" min="0"
                                          class="mt-1 block w-full" value="{{ old('credit_limit', $customer->credit_limit) }}" />
                            <p class="mt-1 text-xs text-gray-500">Required when the customer runs a charge account.</p>
                            <x-input-error :messages="$errors->get('credit_limit')" class="mt-2" />
                        </div>

                        @if (in_array(auth()->user()->role, ['super_admin', 'admin', 'manager'], true))
                        <div>
                            <x-input-label for="tax_exemption_number" value="Tax exemption number" />
                            <x-text-input id="tax_exemption_number" name="tax_exemption_number" type="text"
                                          class="mt-1 block w-full" value="{{ old('tax_exemption_number', $customer->tax_exemption_number) }}" maxlength="100" />
                            <x-input-error :messages="$errors->get('tax_exemption_number')" class="mt-2" />
                            <p class="mt-1 text-xs text-gray-500">
                                With a number and the copy on file, tax is left off this customer's agreements.
                            </p>
                        </div>

                        <div>
                            <x-input-label for="tax_exemption_expires_on" value="Certificate expires on" />
                            <x-text-input id="tax_exemption_expires_on" name="tax_exemption_expires_on" type="date"
                                          class="mt-1 block w-full" value="{{ old('tax_exemption_expires_on', $customer->tax_exemption_expires_on?->format('Y-m-d')) }}" />
                            <p class="mt-1 text-xs text-gray-500">
                                Leave blank when the certificate has no expiry. Once it passes, tax applies again.
                            </p>
                            <x-input-error :messages="$errors->get('tax_exemption_expires_on')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="tax_exemption_reason" value="Exemption reason" />
                            <x-text-input id="tax_exemption_reason" name="tax_exemption_reason" type="text"
                                          class="mt-1 block w-full" value="{{ old('tax_exemption_reason', $customer->tax_exemption_reason) }}" maxlength="150" />
                            <x-input-error :messages="$errors->get('tax_exemption_reason')" class="mt-2" />
                            <p class="mt-1 text-xs text-gray-500">
                                For example “Resale” or “Government”. Kept with the sale when it is billed.
                            </p>
                        </div>

                        <div>
                            <x-input-label value="Exemption certificate" />
                            <label class="mt-2 inline-flex items-center gap-2">
                                <input type="hidden" name="tax_exempt_copy_on_file" value="0">
                                <input type="checkbox" name="tax_exempt_copy_on_file" value="1"
                                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                       @checked(old('tax_exempt_copy_on_file', $customer->tax_exempt_copy_on_file))>
                                <span class="text-sm text-gray-700">Copy on file</span>
                            </label>
                            <x-input-error :messages="$errors->get('tax_exempt_copy_on_file')" class="mt-2" />
                        </div>
                        @else
                            <div class="sm:col-span-2">
                                <p class="text-xs text-gray-500">Tax exemptions are set and changed by an admin or manager.</p>
                            </div>
                        @endif

                        <div class="sm:col-span-2">
                            <x-input-label for="address" value="Address" />
                            <textarea id="address" name="address" rows="2"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('address', $customer->address) }}</textarea>
                            <x-input-error :messages="$errors->get('address')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="notes" value="Notes" />
                            <textarea id="notes" name="notes" rows="3"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes', $customer->notes) }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Update customer</x-primary-button>
                        <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
