<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New product') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-yellow-100 border border-yellow-200 rounded-md text-sm text-yellow-800">
                    {{ session('status') }}
                </div>
            @endif

            <p class="text-sm text-gray-600">
                What are you adding? Rental items are tracked by equipment number and priced per hour, day, week, or month.
                Sales items are tracked by SKU and sold at a fixed price.
            </p>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <a href="{{ route('products.create', ['type' => 'rental']) }}"
                   class="group p-6 bg-white shadow sm:rounded-lg border border-transparent hover:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition">
                    <h3 class="text-base font-semibold text-gray-900 group-hover:text-indigo-700">Rental item</h3>
                    <p class="mt-2 text-sm text-gray-500">
                        Equipment number, manufacturer, model and serial, purchase details, and hourly / daily / weekly / monthly rates.
                    </p>
                    <span class="mt-4 inline-block text-xs font-semibold uppercase tracking-widest text-indigo-600">Add rental item</span>
                </a>

                <a href="{{ route('products.create', ['type' => 'sale']) }}"
                   class="group p-6 bg-white shadow sm:rounded-lg border border-transparent hover:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition">
                    <h3 class="text-base font-semibold text-gray-900 group-hover:text-indigo-700">Sales item</h3>
                    <p class="mt-2 text-sm text-gray-500">
                        SKU, manufacturer, purchase details, sale price, and stock quantities.
                    </p>
                    <span class="mt-4 inline-block text-xs font-semibold uppercase tracking-widest text-indigo-600">Add sales item</span>
                </a>
            </div>

            <div>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to products
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
