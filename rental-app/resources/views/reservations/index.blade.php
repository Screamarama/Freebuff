<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Reservations') }}
            </h2>
            <a href="{{ route('reservations.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                New reservation
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
                <form method="GET" action="{{ route('reservations.index') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="search" value="Search" />
                        <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                                      placeholder="Item, customer or note" value="{{ request('search') }}" />
                    </div>

                    <div class="sm:w-48">
                        <x-input-label for="status" value="Status" />
                        <select id="status" name="status"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All statuses</option>
                            @foreach (['upcoming', 'active', 'completed', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Filter</x-primary-button>
                        @if (request('search') || request('status'))
                            <a href="{{ route('reservations.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Reset</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($reservations->isEmpty())
                    <p class="text-sm text-gray-500">
                        No reservations yet. <a href="{{ route('reservations.create') }}" class="text-indigo-600 hover:text-indigo-900">Create the first one</a>.
                        Items stay available until 24h before a reservation starts.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Item</th>
                                    <th class="px-4 py-3">Customer</th>
                                    <th class="px-4 py-3 text-right">Qty</th>
                                    <th class="px-4 py-3">Starts</th>
                                    <th class="px-4 py-3">Ends</th>
                                    <th class="px-4 py-3 text-right">Total fee</th>
                                    <th class="px-4 py-3">Availability</th>
                                    <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($reservations as $reservation)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <a href="{{ route('reservations.show', $reservation) }}" class="hover:text-indigo-600">
                                                {{ $reservation->product?->name ?? '—' }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ $reservation->customer?->full_name ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $reservation->quantity }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $reservation->starts_at->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700">{{ $reservation->ends_at->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3 text-right font-medium text-gray-900 whitespace-nowrap" title="{{ $reservation->price_breakdown ?? '' }}">
                                            <x-money :amount="$reservation->total_amount ?? 0" />
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $reservation->holdColor() }}">
                                                {{ $reservation->holdLabel() }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ route('reservations.edit', $reservation) }}"
                                               class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">Edit</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $reservations->withQueryString()->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
