<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Send {{ $product->equipment_number }} for maintenance
            </h2>
            <a href="{{ route('products.show', $product) }}" class="text-sm text-gray-600 hover:text-gray-900">Back to unit</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    While it is away the unit is out of availability and cannot be booked, but it stays on the fleet
                    register and its book value keeps depreciating. Completing the job brings it straight back into
                    service. This is separate from service hold, which is supplier stock not yet in the fleet.
                </p>

                <form method="POST" action="{{ route('maintenance.store', $product) }}" class="mt-6 space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-input-label for="reason" value="What is wrong" />
                            <x-text-input id="reason" name="reason" type="text" maxlength="200" class="mt-1 block w-full"
                                          value="{{ old('reason') }}" placeholder="e.g. Hydraulic leak" required />
                            <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="vendor" value="Vendor / workshop" />
                            <x-text-input id="vendor" name="vendor" type="text" maxlength="150" class="mt-1 block w-full"
                                          value="{{ old('vendor') }}" placeholder="e.g. Local hydraulics shop" />
                            <x-input-error :messages="$errors->get('vendor')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="cost" value="Estimated cost" />
                            <x-text-input id="cost" name="cost" type="number" step="0.01" min="0" class="mt-1 block w-full"
                                          value="{{ old('cost', $previousCost) }}" placeholder="0.00" />
                            <p class="mt-1 text-xs text-gray-500">What the repair is expected to cost. The final cost is set when it returns.</p>
                            <x-input-error :messages="$errors->get('cost')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="started_on" value="Date it left" />
                            <x-text-input id="started_on" name="started_on" type="date" class="mt-1 block w-full"
                                          max="{{ now()->toDateString() }}"
                                          value="{{ old('started_on', now()->toDateString()) }}" required />
                            <x-input-error :messages="$errors->get('started_on')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="expected_return_on" value="Expected back" />
                            <x-text-input id="expected_return_on" name="expected_return_on" type="date" class="mt-1 block w-full"
                                          value="{{ old('expected_return_on') }}" />
                            <x-input-error :messages="$errors->get('expected_return_on')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="notes" value="Work notes" />
                            <textarea id="notes" name="notes" rows="3"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                      placeholder="Diagnosis, parts ordered, anything worth keeping">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Send for maintenance</x-primary-button>
                        <a href="{{ route('products.show', $product) }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
