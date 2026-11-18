<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Rental Agreements') }}
            </h2>
            <a href="{{ route('rental-agreements.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                New agreement
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
                <form method="GET" action="{{ route('rental-agreements.index') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="search" value="Search" />
                        <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                                      placeholder="Contract number or customer" value="{{ request('search') }}" />
                    </div>

                    <div class="sm:w-48">
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All statuses</option>
                            @foreach (['draft', 'active', 'returned', 'overdue', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Filter</x-primary-button>
                        @if (request('search') || request('status'))
                            <a href="{{ route('rental-agreements.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Reset</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($contracts->isEmpty())
                    <p class="text-sm text-gray-500">
                        No rental agreements yet.
                        <a href="{{ route('rental-agreements.create') }}" class="text-indigo-600 hover:text-indigo-900">Create one</a>
                        or convert a
                        <a href="{{ route('reservations.index') }}" class="text-indigo-600 hover:text-indigo-900">reservation</a>.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Contract</th>
                                    <th class="px-4 py-3">Customer</th>
                                    <th class="px-4 py-3">Period</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($contracts as $contract)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-700 whitespace-nowrap">
                                            <a href="{{ route('rental-agreements.show', $contract) }}" class="hover:text-indigo-600">
                                                {{ $contract->contract_number }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ $contract->customer?->full_name ?? '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $contract->start_date->format('Y-m-d') }} → {{ $contract->end_date->format('Y-m-d') }}</td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-900 whitespace-nowrap">
                                            <x-money :amount="$contract->total_amount" />
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ ucfirst($contract->status) }}</td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ route('rental-agreements.show', $contract) }}"
                                               class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">View</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $contracts->withQueryString()->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
