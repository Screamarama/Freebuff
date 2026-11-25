<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Backordered items') }}
            </h2>
            <a href="{{ route('purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                All purchase orders
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="grid gap-4 sm:grid-cols-3 text-sm">
                    <div>
                        <span class="block text-xs uppercase tracking-wider text-gray-500">Lines outstanding</span>
                        <span class="mt-1 block text-lg font-semibold text-gray-900">{{ $lines->count() }}</span>
                    </div>
                    <div>
                        <span class="block text-xs uppercase tracking-wider text-gray-500">Units owed</span>
                        <span class="mt-1 block text-lg font-semibold text-amber-700">{{ $outstandingUnits }}</span>
                    </div>
                    <div>
                        <span class="block text-xs uppercase tracking-wider text-gray-500">Outstanding value</span>
                        <span class="mt-1 block text-lg font-semibold text-gray-900">
                            {{ \App\Support\Money::format($outstandingValue, auth()->user()?->business?->currency) }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($lines->isEmpty())
                    <p class="text-sm text-gray-500">Nothing is backordered: every open order line has been received in full.</p>
                @else
                    <div class="overflow-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Order</th>
                                    <th class="px-4 py-3">Supplier</th>
                                    <th class="px-4 py-3">Item</th>
                                    <th class="px-4 py-3 text-right">Ordered</th>
                                    <th class="px-4 py-3 text-right">Received</th>
                                    <th class="px-4 py-3 text-right">Outstanding</th>
                                    <th class="px-4 py-3">Expected</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($lines as $line)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <a href="{{ route('purchase-orders.show', $line->purchaseOrder) }}" class="font-medium text-indigo-600 hover:text-indigo-900">
                                                {{ $line->purchaseOrder->number }}
                                            </a>
                                            <span class="ms-2 text-xs text-gray-500">
                                                {{ $line->purchaseOrder->isRental() ? 'Rental' : 'Sales' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">{{ $line->purchaseOrder->supplier_name ?: '—' }}</td>
                                        <td class="px-4 py-3">{{ $line->label() }}</td>
                                        <td class="px-4 py-3 text-right">{{ $line->quantity_ordered }}</td>
                                        <td class="px-4 py-3 text-right">{{ $line->quantity_received }}</td>
                                        <td class="px-4 py-3 text-right font-semibold text-amber-700">{{ $line->outstanding() }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            {{ $line->purchaseOrder->expected_on?->format('Y-m-d') ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
