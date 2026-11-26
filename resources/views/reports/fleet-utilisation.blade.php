<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Fleet utilisation') }}</h2>
            <a href="{{ route('reports.fleet-value') }}" class="text-sm text-gray-600 hover:text-gray-900">
                Fleet value
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    Each unit's life since it entered service. Days on hire come from its agreements, counted from
                    the agreement start to the actual return (or today while it is still out). Maintenance days are
                    repair stints, and available days are what is left. Revenue is the rental lines' subtotal before
                    agreement-level discount and tax; maintenance is completed repair cost only, so open estimates are
                    not counted as spend.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Fleet utilisation</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $total['utilisation'] }}%</p>
                        <p class="mt-1 text-xs text-gray-500">
                            {{ $total['hire_days'] }} of {{ $total['days_in_service'] }} unit-days on hire
                        </p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Rental revenue</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$total['revenue']" :currency="$currency" />
                        </p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Maintenance spend</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$total['maintenance']" :currency="$currency" />
                        </p>
                        <p class="mt-1 text-xs text-gray-500">{{ $total['downtime'] }} days off the road</p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Contribution</p>
                        <p class="mt-1 text-xl font-semibold {{ $total['contribution'] < 0 ? 'text-red-700' : 'text-gray-900' }}">
                            <x-money :amount="$total['contribution']" :currency="$currency" />
                        </p>
                    </div>
                </div>

                <p class="mt-4 text-sm text-gray-600">
                    {{ $count }} {{ \Illuminate\Support\Str::plural('unit', $count) }} ·
                    {{ $total['available'] }} available unit-days · lowest utilisation first
                </p>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Unit</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Model</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">In service</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Days</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">On hire</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Maintenance</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Available</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Utilisation</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Revenue</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Repairs</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Contribution</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Book value</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Return on cost</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Signal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-xs text-gray-700 whitespace-nowrap">
                                        <a href="{{ route('products.show', $row['unit']) }}" class="hover:text-indigo-600">
                                            {{ $row['unit']->equipment_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $row['unit']->name }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                                        {{ $row['since']?->toDateString() ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $row['days_in_service'] }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $row['hire_days'] }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $row['downtime'] }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $row['available'] }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900">{{ $row['utilisation'] }}%</td>
                                    <td class="px-4 py-3 text-right text-gray-700">
                                        <x-money :amount="$row['revenue']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">
                                        <x-money :amount="$row['maintenance']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium {{ $row['contribution'] < 0 ? 'text-red-700' : 'text-gray-900' }}">
                                        <x-money :amount="$row['contribution']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">
                                        <x-money :amount="$row['book']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">
                                        {{ $row['return_on_cost'] === null ? '—' : number_format($row['return_on_cost'], 1).'%' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                            @class([
                                                'bg-red-100 text-red-800' => $row['signal'] === 'Stop repairing',
                                                'bg-amber-100 text-amber-800' => $row['signal'] === 'Low use',
                                                'bg-green-100 text-green-800' => $row['signal'] === 'Strong',
                                                'bg-gray-100 text-gray-600' => in_array($row['signal'], ['Watch', 'Too new'], true),
                                            ])">
                                            {{ $row['signal'] }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="px-4 py-6 text-center text-sm text-gray-500">
                                        No rental units in the fleet yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-xs text-gray-500">
                Signal: <strong>Stop repairing</strong> when completed repairs equal or exceed the unit's rental
                revenue; <strong>Low use</strong> below 25% utilisation; <strong>Strong</strong> at 60% or more with
                positive contribution; <strong>Too new</strong> under 30 days in service with no hire yet. It is a prompt
                to look, not a decision.
            </p>
        </div>
    </div>
</x-app-layout>
