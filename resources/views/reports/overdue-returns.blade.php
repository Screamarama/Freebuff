<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Overdue returns') }}</h2>
            <a href="{{ route('rental-agreements.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                All agreements
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    Agreements whose end date has passed and that have not been returned or cancelled.
                    The suggested late fee is one day's agreed rent for each day late, calculated from the
                    agreement's own subtotal and dates. It is a prompt, not a charge: enter the fee actually
                    applied when the unit comes back.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Overdue agreements</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $count }}</p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Units still out</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $units }}</p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Longest overdue</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            {{ $oldest }} {{ \Illuminate\Support\Str::plural('day', $oldest) }}
                        </p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Suggested fees</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            <x-money :amount="$fee" />
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Agreement</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Customer</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Due back</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Days late</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Units</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Daily rent</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Suggested fee</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($rows as $row)
                                @php($contract = $row['contract'])
                                <tr>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('rental-agreements.show', $contract) }}"
                                           class="font-medium text-indigo-600 hover:text-indigo-800">
                                            {{ $contract->contract_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-gray-900">{{ $contract->customer?->full_name ?? '—' }}</div>
                                        @if ($contract->customer?->phone)
                                            <div class="text-xs text-gray-500">{{ $contract->customer->phone }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ $contract->end_date?->format('Y-m-d') }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                            {{ $row['days_late'] }} {{ \Illuminate\Support\Str::plural('day', $row['days_late']) }} late
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ (int) $contract->units_out }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">
                                        <x-money :amount="$row['daily_rent']" />
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900">
                                        <x-money :amount="$row['fee']" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                                        Nothing is overdue — every unit due back has been returned.
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
