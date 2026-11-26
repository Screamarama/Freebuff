<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Fleet value') }}</h2>
            <a href="{{ route('reports.fleet-value.export') }}" class="text-sm text-gray-600 hover:text-gray-900">
                Download CSV
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    What the rental fleet is worth on the books today, straight-line. Cost, useful life and salvage
                    come from each unit's own terms, so correcting a model's terms changes the figures here. Units
                    waiting in service hold are not fleet yet and are left out until committed; retired units and units
                    away for maintenance stay on the register because their cost is still on the books.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Fleet book value</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$book" :currency="$currency" />
                        </p>
                        @if ($unvalued > 0)
                            <p class="mt-1 text-xs text-amber-700">
                                Excludes {{ $unvalued }} {{ \Illuminate\Support\Str::plural('unit', $unvalued) }} that cannot be valued yet.
                            </p>
                        @endif
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Original cost</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$cost" :currency="$currency" />
                        </p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Depreciation to date</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$depreciated" :currency="$currency" />
                        </p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Monthly depreciation</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$monthly" :currency="$currency" />
                        </p>
                    </div>
                </div>

                <p class="mt-4 text-sm text-gray-600">
                    {{ $count }} {{ \Illuminate\Support\Str::plural('unit', $count) }} ·
                    {{ $fullyDepreciated->count() }} fully depreciated ·
                    {{ $atSalvage->count() }} at salvage
                    @if ($attention > 0)
                        · <span class="text-amber-700">{{ $attention }} needing attention</span>
                    @endif
                </p>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200">
                    <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">By catalogue model</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Model</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Units</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Cost</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Book value</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Monthly</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Needs attention</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($models as $model)
                                <tr>
                                    <td class="px-4 py-2 text-gray-900">{{ $model['label'] }}</td>
                                    <td class="px-4 py-2 text-right text-gray-700">{{ $model['units'] }}</td>
                                    <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap">
                                        <x-money :amount="$model['cost']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-900 whitespace-nowrap">
                                        <x-money :amount="$model['book']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap">
                                        <x-money :amount="$model['monthly']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-right {{ $model['attention'] > 0 ? 'text-amber-700' : 'text-gray-400' }}">
                                        {{ $model['attention'] ?: '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-4 text-sm text-gray-600">No rental units in the fleet yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200">
                    <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">At salvage — no further depreciation</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Unit</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Model</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Cost</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Salvage</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Book value</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Depreciation start</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($atSalvage as $row)
                                <tr>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('products.show', $row['unit']) }}" class="font-mono text-xs text-indigo-600 hover:text-indigo-900">
                                            {{ $row['unit']->equipment_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">{{ $row['model'] }}</td>
                                    <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap">
                                        <x-money :amount="$row['cost']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap">
                                        <x-money :amount="$row['salvage']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-900 whitespace-nowrap">
                                        <x-money :amount="$row['book']" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $row['started']?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $row['fully_depreciated'] ? 'bg-gray-100 text-gray-700' : 'bg-amber-100 text-amber-800' }}">
                                            {{ $row['status'] }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-4 text-sm text-gray-600">
                                        No unit has reached its salvage value yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200">
                    <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Missing cost, life or start date</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        These units cannot be valued or depreciated until their terms are completed.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Unit</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Model</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Missing</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Cost</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Life (months)</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Start</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($attentionRows as $row)
                                <tr>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('products.edit', $row['unit']) }}" class="font-mono text-xs text-indigo-600 hover:text-indigo-900">
                                            {{ $row['unit']->equipment_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">{{ $row['model'] }}</td>
                                    <td class="px-4 py-2 text-amber-700">{{ implode(', ', $row['issues']) }}</td>
                                    <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap">
                                        @if ($row['cost'] > 0)
                                            <x-money :amount="$row['cost']" :currency="$currency" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-700">{{ $row['life'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $row['started']?->format('Y-m-d') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-4 text-sm text-gray-600">
                                        Every unit has the terms it needs.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
