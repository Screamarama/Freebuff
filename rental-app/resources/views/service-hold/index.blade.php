<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Service hold</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Rental equipment received from purchase orders. Set the rates and terms, then commit to the fleet.
                </p>
            </div>
            <a href="{{ route('purchase-orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                Purchase orders
            </a>
        </div>
    </x-slot>

    @php
        $canCommit = in_array(auth()->user()?->role, ['super_admin', 'admin', 'manager'], true);
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($units->isEmpty())
                    <p class="text-sm text-gray-500">
                        Nothing in service hold. Receiving a rental purchase order puts its units here.
                    </p>
                @else
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">
                            {{ $units->count() }} {{ \Illuminate\Support\Str::plural('unit', $units->count()) }} waiting
                        </h3>
                        <span class="text-xs text-gray-400">Not bookable until committed</span>
                    </div>

                    <div class="mt-4 space-y-6">
                        @foreach ($lines as $line)
                            @php
                                $lineUnits = $line['units'];
                                $bulk = $line['item'] && $lineUnits->count() > 1;
                            @endphp

                            <div class="rounded-lg border border-gray-200">
                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">
                                            {{ $line['description'] }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-gray-500">
                                            {{ $line['purchase_order'] ?: 'Purchase order' }}
                                            · {{ $lineUnits->count() }} {{ \Illuminate\Support\Str::plural('unit', $lineUnits->count()) }} on this line
                                        </p>
                                    </div>
                                    @if ($bulk && $canCommit)
                                        <a href="{{ route('service-hold.line.setup', $line['item']) }}"
                                           class="inline-flex items-center px-3 py-1.5 bg-gray-800 rounded-md text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                                            Set up all {{ $lineUnits->count() }}
                                        </a>
                                    @elseif ($bulk)
                                        <span class="text-xs text-gray-400">Admins and managers only</span>
                                    @endif
                                </div>

                                <div class="overflow-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead>
                                            <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <th class="px-4 py-3">Temporary tag</th>
                                                <th class="px-4 py-3">Equipment</th>
                                                <th class="px-4 py-3">Purchase order</th>
                                                <th class="px-4 py-3">Received</th>
                                                <th class="px-4 py-3 text-right">Unit cost</th>
                                                <th class="px-4 py-3"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            @foreach ($lineUnits as $unit)
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $unit->equipment_number ?: '—' }}</td>
                                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $unit->name ?: 'Unnamed equipment' }}</td>
                                                    <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $unit->purchase_order ?: '—' }}</td>
                                                    <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                                        {{ $unit->purchase_date?->format('M j, Y') ?: '—' }}
                                                    </td>
                                                    <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">
                                                        @if ($unit->cost_price !== null)
                                                            <x-money :amount="$unit->cost_price" />
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-3 text-right">
                                                        @if ($canCommit)
                                                            <a href="{{ route('service-hold.setup', $unit) }}"
                                                               class="inline-flex items-center px-3 py-1.5 bg-gray-800 rounded-md text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                                                                Set up &amp; commit
                                                            </a>
                                                        @else
                                                            <span class="text-xs text-gray-400">Admins and managers only</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
