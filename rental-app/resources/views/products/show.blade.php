<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $product->name }}
            </h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('products.edit', $product) }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Edit
                </a>
                <form method="POST" action="{{ route('products.destroy', $product) }}"
                      onsubmit="return confirm('Delete this product?');">
                    @csrf
                    @method('DELETE')
                    <x-secondary-button class="text-red-600">Delete</x-secondary-button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Type</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ ucfirst($product->product_type) }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">
                            {{ $product->isRental() ? 'Equipment number' : 'SKU' }}
                        </dt>
                        <dd class="mt-1 font-mono text-sm text-gray-900">
                            {{ $product->isRental() ? ($product->equipment_number ?: '—') : ($product->sku ?: '—') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Manufacturer</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $product->manufacturer ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Category</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $product->category ?: '—' }}</dd>
                    </div>

                    @if ($product->isRental())
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Model number</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $product->model_number ?: '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Serial number</dt>
                            <dd class="mt-1 font-mono text-sm text-gray-900">{{ $product->serial_number ?: '—' }}</dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Status</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ $product->is_active ? 'Active' : 'Inactive' }}
                            @if ($product->is_featured) <span class="text-indigo-600">(featured)</span> @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Purchase order</dt>
                        <dd class="mt-1 font-mono text-sm text-gray-900">{{ $product->purchase_order ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Purchase date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $product->purchase_date?->format('M j, Y') ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Purchase price</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if ($product->cost_price !== null)
                                <x-money :amount="$product->cost_price" />
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    @if ($product->isRental() && $product->monthlyDepreciation() !== null)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Useful life</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $product->useful_life_months }} months</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Monthly depreciation</dt>
                            <dd class="mt-1 text-sm text-gray-900"><x-money :amount="$product->monthlyDepreciation()" /></dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Book value today</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                <x-money :amount="$product->bookValue() ?? $product->cost_price" />
                                @if ($product->depreciation_started_on)
                                    <span class="font-normal text-gray-500">since {{ $product->depreciation_started_on->format('M j, Y') }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif

                    @if ($product->isRental())
                        @foreach (['hour' => 'Rental price / hour', 'day' => 'Rental price / day', 'week' => 'Rental price / week', 'month' => 'Rental price / month'] as $field => $label)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">{{ $label }}</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    @if ($product->{'rental_price_'.$field} !== null)
                                        <x-money :amount="$product->{'rental_price_'.$field}" />
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        @endforeach

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Available quantity</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $product->available_quantity }} of {{ $product->stock_quantity }} available</dd>
                        </div>
                    @else
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Sale price</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                @if ($product->sale_price !== null)
                                    <x-money :amount="$product->sale_price" />
                                @else
                                    —
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Stock quantity</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $product->stock_quantity }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Available quantity</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $product->available_quantity }}</dd>
                        </div>
                    @endif

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Description</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $product->description ?: '—' }}</dd>
                    </div>

                    @if ($product->image_source)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Image</dt>
                            <dd class="mt-1">
                                <img src="{{ $product->image_source }}" alt="{{ $product->name }}"
                                     class="h-40 w-40 rounded-md border border-gray-200 object-cover" />
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ $product->image_path ? 'Uploaded image' : 'Linked from image URL' }}
                                </p>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($product->isRental())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Availability</h3>

                        <form method="GET" action="{{ route('products.show', $product) }}" class="flex flex-wrap items-end gap-2">
                            <div>
                                <x-input-label for="from" value="From" />
                                <x-text-input id="from" name="from" type="date" class="mt-1"
                                              value="{{ $from->toDateString() }}" />
                            </div>
                            <div>
                                <x-input-label for="to" value="To" />
                                <x-text-input id="to" name="to" type="date" class="mt-1"
                                              value="{{ $to->toDateString() }}" />
                            </div>
                            <x-primary-button>Show</x-primary-button>
                        </form>
                    </div>

                    <x-input-error :messages="$errors->get('from')" class="mt-2" />
                    <x-input-error :messages="$errors->get('to')" class="mt-2" />

                    @if ($truncated)
                        <p class="mt-2 text-xs text-amber-700">
                            The range was shortened to 31 days; showing {{ $from->format('M j, Y') }} to {{ $to->format('M j, Y') }}.
                        </p>
                    @endif

                    <div class="mt-4 overflow-auto relative">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-3 py-2">Day</th>
                                    <th class="px-3 py-2 text-right">Booked</th>
                                    <th class="px-3 py-2 text-right">Free</th>
                                    <th class="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($calendar as $day)
                                    <tr>
                                        <td class="px-3 py-2 text-gray-900">{{ $day['date']->format('D, M j') }}</td>
                                        <td class="px-3 py-2 text-right text-gray-700">{{ $day['booked'] }} of {{ $product->stock_quantity }}</td>
                                        <td class="px-3 py-2 text-right text-gray-700">{{ $day['available'] }}</td>
                                        <td class="px-3 py-2">
                                            @if ($day['booked'] === 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Free</span>
                                            @elseif ($day['available'] > 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Partly booked</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Fully booked</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div>
                <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to products
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
