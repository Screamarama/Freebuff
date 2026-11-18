<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Products') }}
            </h2>
            <a href="{{ route('products.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                New product
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form method="GET" action="{{ route('products.index') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="search" value="Search" />
                        <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                                      placeholder="Name, equipment number, SKU or serial" value="{{ request('search') }}" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Search</x-primary-button>
                        @if (request('search'))
                            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                                Reset
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <!-- Rental items: identified and sorted by Equipment Number -->
            <div id="rentals" class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Rental items</h3>
                    <span class="text-xs text-gray-400">Sorted by equipment number</span>
                </div>

                @if ($rentals->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">
                        No rental items yet. <a href="{{ route('products.create') }}" class="text-indigo-600 hover:text-indigo-900">Add one</a>.
                    </p>
                @else
                    <div class="mt-4 overflow-auto relative">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Equipment #</th>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Type</th>
                                    <th class="px-4 py-3">Rental rates</th>
                                    <th class="px-4 py-3 text-right">Available</th>
                                    <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($rentals as $product)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $product->equipment_number ?: '—' }}</td>
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <a href="{{ route('products.show', $product) }}" class="hover:text-indigo-600">
                                                {{ $product->name }}
                                            </a>
                                            @if ($product->equipment_label !== '')
                                                <div class="text-xs text-gray-500">{{ $product->equipment_label }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ ucfirst($product->product_type) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                            @php
                                                $rates = [];
                                                foreach (['hour' => $product->rental_price_hour, 'day' => $product->rental_price_day, 'week' => $product->rental_price_week, 'month' => $product->rental_price_month] as $unit => $price) {
                                                    if ($price !== null) {
                                                        $rates[] = \App\Support\Money::format($price, auth()->user()?->business?->currency).'/'.$unit;
                                                    }
                                                }
                                            @endphp
                                            {{ $rates ? implode(' · ', $rates) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">
                                            {{ $product->available_quantity }} of {{ $product->stock_quantity }}
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ route('products.edit', $product) }}"
                                               class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">
                                                Edit
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $rentals->fragment('rentals')->links() }}
                    </div>
                @endif
            </div>

            <!-- Sales items: identified and sorted by SKU -->
            <div id="sales" class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Sales items</h3>
                    <span class="text-xs text-gray-400">Sorted by SKU</span>
                </div>

                @if ($sales->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">
                        No sales items yet. <a href="{{ route('products.create') }}" class="text-indigo-600 hover:text-indigo-900">Add one</a>.
                    </p>
                @else
                    <div class="mt-4 overflow-auto relative">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">SKU</th>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Type</th>
                                    <th class="px-4 py-3">Sale price</th>
                                    <th class="px-4 py-3 text-right">Stock</th>
                                    <th class="px-4 py-3 text-right">Available</th>
                                    <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($sales as $product)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $product->sku ?: '—' }}</td>
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <a href="{{ route('products.show', $product) }}" class="hover:text-indigo-600">
                                                {{ $product->name }}
                                            </a>
                                            @if ($product->equipment_label !== '')
                                                <div class="text-xs text-gray-500">{{ $product->equipment_label }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                {{ ucfirst($product->product_type) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                            @if ($product->sale_price !== null)
                                                <x-money :amount="$product->sale_price" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $product->stock_quantity }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $product->available_quantity }}</td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ route('products.edit', $product) }}"
                                               class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">
                                                Edit
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $sales->fragment('sales')->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
