<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ $order->number }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $order->isRental() ? 'Rental equipment order' : 'Sales stock order' }}
                    · {{ $order->statusLabel() }}
                </p>
            </div>
            <a href="{{ route('purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                All purchase orders
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-800">
                    <ul class="list-disc ps-5 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <dl class="grid gap-4 sm:grid-cols-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Supplier</dt>
                        <dd class="mt-1 text-gray-900">{{ $order->supplier_name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Ordered</dt>
                        <dd class="mt-1 text-gray-900">{{ $order->ordered_on?->format('Y-m-d') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Expected</dt>
                        <dd class="mt-1 text-gray-900">{{ $order->expected_on?->format('Y-m-d') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Ordered value</dt>
                        <dd class="mt-1 text-gray-900">{{ \App\Support\Money::format($order->orderedValue(), auth()->user()?->business?->currency) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Outstanding units</dt>
                        <dd class="mt-1 {{ $order->outstandingQuantity() > 0 ? 'font-semibold text-amber-700' : 'text-gray-900' }}">
                            {{ $order->outstandingQuantity() }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Outstanding value</dt>
                        <dd class="mt-1 text-gray-900">{{ \App\Support\Money::format($order->outstandingValue(), auth()->user()?->business?->currency) }}</dd>
                    </div>
                    @if ($order->received_at)
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-gray-500">Completed</dt>
                            <dd class="mt-1 text-gray-900">{{ $order->received_at->format('Y-m-d') }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($order->notes)
                    <p class="mt-4 text-sm text-gray-600">{{ $order->notes }}</p>
                @endif
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Lines</h3>

                <div class="mt-4 overflow-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <th class="px-4 py-3">Item</th>
                                <th class="px-4 py-3 text-right">Ordered</th>
                                <th class="px-4 py-3 text-right">Received</th>
                                <th class="px-4 py-3 text-right">Outstanding</th>
                                <th class="px-4 py-3 text-right">Unit cost</th>
                                <th class="px-4 py-3 text-right">Line total</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($order->items as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        @if ($item->equipmentModel)
                                            <a href="{{ route('equipment-models.edit', $item->equipmentModel) }}" class="font-medium text-gray-900 hover:text-indigo-600">
                                                {{ $item->label() }}
                                            </a>
                                            @if ($item->useful_life_months)
                                                <div class="text-xs text-gray-500">Booked over {{ $item->useful_life_months }} months</div>
                                            @endif
                                        @elseif ($item->product)
                                            <a href="{{ route('products.show', $item->product) }}" class="font-medium text-gray-900 hover:text-indigo-600">
                                                {{ $item->label() }}
                                            </a>
                                        @else
                                            <span class="font-medium text-gray-900">{{ $item->label() }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">{{ $item->quantity_ordered }}</td>
                                    <td class="px-4 py-3 text-right">{{ $item->quantity_received }}</td>
                                    <td class="px-4 py-3 text-right {{ $item->outstanding() > 0 ? 'font-semibold text-amber-700' : 'text-gray-500' }}">
                                        {{ $item->outstanding() }}
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        {{ \App\Support\Money::format($item->unit_cost, auth()->user()?->business?->currency) }}
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        {{ \App\Support\Money::format($item->lineTotal(), auth()->user()?->business?->currency) }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        @if ($order->isRental() && $item->committedUnits->isNotEmpty()
                                            && in_array(auth()->user()?->role, ['super_admin', 'admin', 'manager'], true))
                                            <a href="{{ route('service-hold.line.reopen.form', $item) }}" class="text-sm text-gray-600 hover:text-gray-900">
                                                Return {{ $item->committedUnits->count() }} to hold
                                            </a>
                                        @endif
                                        @if (! $order->isClosed() && $item->quantity_received === 0)
                                            <form method="POST" action="{{ route('purchase-orders.items.destroy', [$order, $item]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-sm text-red-600 hover:text-red-800">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @unless ($order->isClosed())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Receive</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        @if ($order->isRental())
                            Received units go into service hold with this cost and date. Set their rates, salvage value and
                            used sales price there, then commit them to the fleet.
                        @else
                            Received units are added to each item's stock and available quantity.
                        @endif
                    </p>

                    <form method="POST" action="{{ route('purchase-orders.receive', $order) }}" class="mt-4 space-y-4">
                        @csrf

                        <div class="grid gap-3 sm:grid-cols-3 sm:items-end">
                            @foreach ($order->items as $item)
                                <div>
                                    <label for="qty-{{ $item->id }}" class="block text-xs font-medium text-gray-600">
                                        {{ $item->label() }} <span class="text-gray-400">({{ $item->outstanding() }} outstanding)</span>
                                    </label>
                                    <input type="number" min="0" max="{{ $item->outstanding() }}"
                                           id="qty-{{ $item->id }}" name="quantities[{{ $item->id }}]" value="0"
                                           class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                </div>
                            @endforeach

                            <div>
                                <x-input-label for="received_on" value="Received on" />
                                <x-text-input id="received_on" name="received_on" type="date" class="mt-1 block w-full"
                                              value="{{ now()->toDateString() }}" />
                            </div>
                        </div>

                        <x-primary-button>Record receipt</x-primary-button>
                    </form>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Add a line</h3>

                    <form method="POST" action="{{ route('purchase-orders.items.store', $order) }}" class="mt-4 grid gap-3 sm:grid-cols-12 sm:items-end">
                        @csrf

                        <div class="sm:col-span-6">
                            @if ($order->isRental())
                                <x-input-label for="description" value="Equipment" />
                                <x-text-input id="description" name="description" type="text" maxlength="150"
                                              class="mt-1 block w-full" placeholder="e.g. CAT 320 GC excavator" />
                            @else
                                <x-input-label for="product_id" value="Item" />
                                <select id="product_id" name="product_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                                    <option value="">Choose an item</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="quantity_ordered" value="Quantity" />
                            <x-text-input id="quantity_ordered" name="quantity_ordered" type="number" min="1" value="1" class="mt-1 block w-full" />
                        </div>

                        <div class="sm:col-span-3">
                            <x-input-label for="unit_cost" value="Unit cost" />
                            <x-text-input id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                        </div>

                        <div class="sm:col-span-1">
                            <x-secondary-button>Add</x-secondary-button>
                        </div>
                    </form>
                </div>

                @if ($order->items()->where('quantity_received', '>', 0)->doesntExist())
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        <form method="POST" action="{{ route('purchase-orders.cancel', $order) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-sm text-red-600 hover:text-red-800">
                                Cancel this purchase order
                            </button>
                        </form>
                    </div>
                @endif
            @endunless
        </div>
    </div>
</x-app-layout>
