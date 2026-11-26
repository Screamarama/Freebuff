<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Reservation #{{ $reservation->id }}
            </h2>
            <div class="flex items-center gap-3">
                @if ($reservation->rentalContract)
                    <a href="{{ route('rental-agreements.show', $reservation->rentalContract) }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        View agreement
                    </a>
                @elseif ($reservation->status !== 'cancelled')
                    <form method="POST" action="{{ route('reservations.convert', $reservation) }}"
                          @if ($reservation->customer?->taxExemptionHasExpired())
                              onsubmit="return confirm('Tax exemption certificate expired on {{ $reservation->customer->tax_exemption_expires_on->format('Y-m-d') }}. The agreement will be taxed. Convert anyway?');"
                          @endif>
                        @csrf
                        <x-primary-button>Convert to agreement</x-primary-button>
                    </form>
                @endif
                <a href="{{ route('reservations.edit', $reservation) }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Edit
                </a>
                @if (! in_array($reservation->status, ['completed', 'cancelled'], true))
                    <form method="POST" action="{{ route('reservations.cancel', $reservation) }}">
                        @csrf
                        @method('PATCH')
                        <x-secondary-button type="submit" class="text-red-600">Cancel</x-secondary-button>
                    </form>
                    <form method="POST" action="{{ route('reservations.complete', $reservation) }}">
                        @csrf
                        @method('PATCH')
                        <x-secondary-button type="submit">Complete</x-secondary-button>
                    </form>
                @endif
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

            @if ($reservation->customer?->taxExemptionHasExpired())
                <div class="p-4 bg-amber-100 border border-amber-200 rounded-md text-sm text-amber-900">
                    This customer's tax exemption certificate expired on
                    {{ $reservation->customer->tax_exemption_expires_on->format('Y-m-d') }}. Converting will
                    charge tax on the agreement — record a current certificate first if they should stay exempt.
                </div>
            @endif

            <div class="p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-800">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $reservation->holdColor() }}">
                    {{ $reservation->holdLabel() }}
                </span>
                <span class="ml-2">
                    Item stays available until <strong>{{ $reservation->lockFrom()->format('Y-m-d H:i') }}</strong>
                    (24h before start). Stock counts are never decremented by reservations.
                </span>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Item</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->product?->name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Customer</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->customer?->full_name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Quantity</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->quantity }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Status</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ ucfirst($reservation->status) }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Starts at</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->starts_at->format('Y-m-d H:i') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Ends at</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->ends_at->format('Y-m-d H:i') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Total rental fee</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            <x-money :amount="$reservation->total_amount ?? 0" />
                        </dd>
                        @if ($reservation->price_breakdown)
                            <p class="mt-1 text-xs text-gray-500">{{ $reservation->price_breakdown }}</p>
                        @endif
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Billing block</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->billing_unit ? ucfirst($reservation->billing_unit) : '—' }}</dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Notes</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $reservation->notes ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div>
                <a href="{{ route('reservations.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to reservations
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
