<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New purchase order') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-600">
                    Rental equipment and sales stock are kept in separate purchase order series.
                </p>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <a href="{{ route('purchase-orders.create', ['type' => 'rental']) }}"
                       class="block rounded-lg border border-gray-200 p-5 hover:border-indigo-400 hover:bg-gray-50">
                        <span class="block font-semibold text-gray-900">Rental items</span>
                        <span class="mt-1 block text-sm text-gray-600">
                            Capital equipment. Each received unit becomes its own product record with a
                            purchase cost, purchase date and book value.
                        </span>
                    </a>

                    <a href="{{ route('purchase-orders.create', ['type' => 'sales']) }}"
                       class="block rounded-lg border border-gray-200 p-5 hover:border-indigo-400 hover:bg-gray-50">
                        <span class="block font-semibold text-gray-900">Sales items</span>
                        <span class="mt-1 block text-sm text-gray-600">
                            Resale stock. Receiving a line adds the units to the item's stock and
                            available quantity.
                        </span>
                    </a>
                </div>

                <div class="mt-6">
                    <a href="{{ route('purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                        Back to purchase orders
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
