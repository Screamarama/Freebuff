<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Dashboard') }}
            </h2>
            <p class="text-sm text-gray-600">
                {{ $business->name }} · {{ $today->format('l, j F Y') }}
            </p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- Pickups --}}
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Today's pickups</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-600">{{ $pickups->count() }}</span>
                            <a href="{{ route('rental-agreements.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">Agreements</a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse ($pickups->take(5) as $contract)
                            <div class="px-4 py-2 text-sm flex items-baseline justify-between gap-3">
                                <a href="{{ route('rental-agreements.show', $contract) }}" class="font-semibold text-indigo-600 hover:text-indigo-900">
                                    {{ $contract->contract_number }}
                                </a>
                                <span class="text-gray-600 truncate">{{ $contract->customer?->full_name ?? '—' }}</span>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500">No pickups scheduled today.</p>
                        @endforelse
                    </div>
                    @if ($pickups->count() > 5)
                        <p class="px-4 py-2 border-t border-gray-200 text-xs text-gray-500">
                            and {{ $pickups->count() - 5 }} more today.
                        </p>
                    @endif
                </div>

                {{-- Returns due --}}
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Returns due today</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-600">{{ $returns->count() }}</span>
                            <a href="{{ route('rental-agreements.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">Agreements</a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse ($returns->take(5) as $contract)
                            <div class="px-4 py-2 text-sm flex items-baseline justify-between gap-3">
                                <a href="{{ route('rental-agreements.show', $contract) }}" class="font-semibold text-indigo-600 hover:text-indigo-900">
                                    {{ $contract->contract_number }}
                                </a>
                                <span class="text-gray-600 truncate">{{ $contract->customer?->full_name ?? '—' }}</span>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500">Nothing due back today.</p>
                        @endforelse
                    </div>
                    @if ($returns->count() > 5)
                        <p class="px-4 py-2 border-t border-gray-200 text-xs text-gray-500">
                            and {{ $returns->count() - 5 }} more today.
                        </p>
                    @endif
                </div>

                {{-- Overdue returns --}}
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Overdue returns</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <a href="{{ route('overdue-returns.index') }}"
                               class="font-semibold underline-offset-2 hover:underline {{ $overdue->isNotEmpty() ? 'text-red-700' : 'text-gray-600' }}"
                               title="Open the overdue returns board">
                                {{ $overdue->count() }}
                            </a>
                            <a href="{{ route('overdue-returns.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">Chase list</a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse ($overdue->take(5) as $contract)
                            <div class="px-4 py-2 text-sm flex items-baseline justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('rental-agreements.show', $contract) }}" class="font-semibold text-indigo-600 hover:text-indigo-900">
                                        {{ $contract->contract_number }}
                                    </a>
                                    <div class="text-xs text-gray-600 truncate">{{ $contract->customer?->full_name ?? '—' }}</div>
                                </div>
                                <span class="text-xs font-semibold text-red-700 whitespace-nowrap">
                                    {{ $contract->daysLate($today) }} {{ \Illuminate\Support\Str::plural('day', $contract->daysLate($today)) }} late
                                </span>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500">No equipment is overdue.</p>
                        @endforelse
                    </div>
                    @if ($overdue->count() > 5)
                        <p class="px-4 py-2 border-t border-gray-200 text-xs text-gray-500">
                            and {{ $overdue->count() - 5 }} more overdue.
                        </p>
                    @endif
                </div>

                {{-- Unpaid balances --}}
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Unpaid balances</h3>
                        <a href="{{ route('receivables.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">Receivables</a>
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Outstanding</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">
                                <x-money :amount="$aging['open']" :currency="$business->currency" />
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Overdue</p>
                            <p class="mt-1 text-2xl font-semibold {{ $aging['overdue'] > 0 ? 'text-red-700' : 'text-gray-900' }}">
                                <x-money :amount="$aging['overdue']" :currency="$business->currency" />
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Customers</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900">{{ $unpaidCustomers->count() }}</p>
                        </div>
                    </div>
                </div>

                {{-- Open reservations --}}
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Open reservations</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-600">{{ $reservations->count() }}</span>
                            <a href="{{ route('reservations.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">Reservations</a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse ($reservations->take(5) as $reservation)
                            <div class="px-4 py-2 text-sm">
                                <a href="{{ route('reservations.show', $reservation) }}" class="font-semibold text-indigo-600 hover:text-indigo-900">
                                    {{ $reservation->customer?->full_name ?? '—' }}
                                </a>
                                <div class="text-xs text-gray-600">
                                    {{ $reservation->product?->name ?? '—' }}
                                    · {{ $reservation->starts_at?->format('M j') }} – {{ $reservation->ends_at?->format('M j') }}
                                </div>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500">No open reservations.</p>
                        @endforelse
                    </div>
                    @if ($reservations->count() > 5)
                        <p class="px-4 py-2 border-t border-gray-200 text-xs text-gray-500">
                            and {{ $reservations->count() - 5 }} more open.
                        </p>
                    @endif
                </div>

                {{-- Out of stock: sale stock only, and only when the tenant sells --}}
                @if ($hasSaleItems)
                <div class="bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Out of stock</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-600">{{ $lowStock->count() }}</span>
                            <a href="{{ route('products.index') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">Products</a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse ($lowStock->take(5) as $product)
                            <div class="px-4 py-2 text-sm flex items-baseline justify-between gap-3">
                                <a href="{{ route('products.show', $product) }}" class="font-semibold text-indigo-600 hover:text-indigo-900">
                                    {{ $product->name }}
                                </a>
                                <span class="text-xs text-gray-600 whitespace-nowrap">
                                    {{ $product->available_quantity }} of {{ $product->stock_quantity }} available
                                </span>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-sm text-gray-500">Nothing is out of stock.</p>
                        @endforelse
                    </div>
                    @if ($lowStock->count() > 5)
                        <p class="px-4 py-2 border-t border-gray-200 text-xs text-gray-500">
                            and {{ $lowStock->count() - 5 }} more out of stock.
                        </p>
                    @endif
                </div>
                @endif

            </div>
        </div>
    </div>
</x-app-layout>
