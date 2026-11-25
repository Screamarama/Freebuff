<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Purchase orders') }}
            </h2>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('purchase-orders.backorders') }}"
                   class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Backorders
                </a>
                <a href="{{ route('purchase-orders.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                    New purchase order
                </a>
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

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form method="GET" action="{{ route('purchase-orders.index') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="type" value="Order type" />
                        <select id="type" name="type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">All types</option>
                            <option value="rental" @selected($type === 'rental')>Rental items</option>
                            <option value="sales" @selected($type === 'sales')>Sales items</option>
                        </select>
                    </div>

                    <div class="flex-1">
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">All statuses</option>
                            @foreach (['ordered' => 'Ordered', 'partially_received' => 'Partially received', 'received' => 'Received', 'cancelled' => 'Cancelled'] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-primary-button>Filter</x-primary-button>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($orders->isEmpty())
                    <p class="text-sm text-gray-500">
                        No purchase orders yet.
                        <a href="{{ route('purchase-orders.create') }}" class="text-indigo-600 hover:text-indigo-900">Create one</a>.
                    </p>
                @else
                    <div class="overflow-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Number</th>
                                    <th class="px-4 py-3">Type</th>
                                    <th class="px-4 py-3">Supplier</th>
                                    <th class="px-4 py-3">Ordered</th>
                                    <th class="px-4 py-3">Expected</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Outstanding</th>
                                    <th class="px-4 py-3 text-right">Ordered value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($orders as $order)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <a href="{{ route('purchase-orders.show', $order) }}" class="font-medium text-indigo-600 hover:text-indigo-900">
                                                {{ $order->number }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $order->isRental() ? 'bg-blue-100 text-blue-800' : 'bg-emerald-100 text-emerald-800' }}">
                                                {{ $order->isRental() ? 'Rental' : 'Sales' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">{{ $order->supplier_name ?: '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $order->ordered_on?->format('Y-m-d') ?: '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $order->expected_on?->format('Y-m-d') ?: '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $order->statusLabel() }}</td>
                                        <td class="px-4 py-3 text-right {{ $order->outstandingQuantity() > 0 ? 'font-semibold text-amber-700' : 'text-gray-500' }}">
                                            {{ $order->outstandingQuantity() }}
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            {{ \App\Support\Money::format($order->orderedValue(), auth()->user()?->business?->currency) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $orders->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
