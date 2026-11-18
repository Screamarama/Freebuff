<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Edit items — {{ $contract->contract_number }}
            </h2>
            <a href="{{ route('rental-agreements.show', $contract) }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Done
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

            @if ($contract->customer?->taxExemptionHasExpired())
                <div class="p-4 bg-amber-100 border border-amber-200 rounded-md text-sm text-amber-900">
                    This customer's tax exemption certificate expired on
                    {{ $contract->customer->tax_exemption_expires_on->format('Y-m-d') }}. Repricing will
                    charge tax — record a current certificate first if they should stay exempt.
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-4xl">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Line items</h3>
                    <p class="text-sm text-gray-700">
                        Subtotal <strong><x-money :amount="$contract->subtotal" /></strong>
                        · Total <strong class="text-lg"><x-money :amount="$contract->total_amount" /></strong>
                    </p>
                </div>

                <div class="mt-4 space-y-4">
                    @forelse ($contract->items as $item)
                        <form method="POST" action="{{ route('rental-agreements.items.update', [$contract, $item]) }}"
                              class="flex flex-wrap items-end gap-3 border border-gray-200 rounded-md p-3">
                            @csrf
                            @method('PATCH')
                            <div class="flex-1 min-w-40">
                                <div class="text-sm font-medium text-gray-900">{{ $item->product?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500">
                                    @if ($item->rate_type === 'sale')
                                        Sale item
                                    @else
                                        {{ $item->duration }} {{ $item->rate_type }}{{ $item->duration > 1 ? 's' : '' }} (list
                                        <x-money :amount="$item->product?->{'rental_price_' . $item->rate_type}" />)
                                    @endif
                                </div>
                                <x-input-error :messages="$errors->get('quantity')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="qty-{{ $item->id }}" value="Qty" />
                                <x-text-input id="qty-{{ $item->id }}" name="quantity" type="number" min="1" class="mt-1 block w-20"
                                              value="{{ old('quantity', $item->quantity) }}" required />
                            </div>
                            @if ($item->rate_type !== 'sale')
                                <div>
                                    <x-input-label for="dur-{{ $item->id }}" value="Duration" />
                                    <x-text-input id="dur-{{ $item->id }}" name="duration" type="number" min="1" class="mt-1 block w-24"
                                                  value="{{ old('duration', $item->duration) }}" required />
                                </div>
                            @endif
                            <div>
                                <x-input-label for="price-{{ $item->id }}" value="Unit price ($)" />
                                <x-text-input id="price-{{ $item->id }}" name="unit_price" type="number" step="0.01" min="0" class="mt-1 block w-28"
                                              value="{{ old('unit_price', $item->unit_price) }}" required />
                            </div>
                            <div>
                                <x-input-label for="serial-{{ $item->id }}" value="Serial #" />
                                <x-text-input id="serial-{{ $item->id }}" name="serial_number" type="text" maxlength="100" class="mt-1 block w-32"
                                              value="{{ old('serial_number', $item->serial_number) }}" placeholder="Optional" />
                            </div>
                            <div class="text-sm font-medium text-gray-900 whitespace-nowrap">
                                <x-money :amount="$item->subtotal" />
                            </div>
                            <x-primary-button>Save</x-primary-button>
                        </form>
                    @empty
                        <p class="text-sm text-gray-500">No items yet — add one below.</p>
                    @endforelse
                </div>

                <p class="mt-3 text-xs text-gray-500">Changing a unit price overrides the list rate for that line only. Totals recalculate on save.</p>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 max-w-4xl">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Add rental item</h3>
                    <form method="POST" action="{{ route('rental-agreements.items.store', $contract) }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="kind" value="rental" />
                        <x-agreement-item-fields kind="rental" :products="$rentalProducts" required
                                                 id-prefix="rental-add" :default-duration="$defaultRentalDuration"
                                                 :contract-id="$contract->id"
                                                 :start-date="$contract->start_date->toDateString()"
                                                 :end-date="$contract->end_date->toDateString()" />
                        <x-primary-button>Add rental item</x-primary-button>
                    </form>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Add sale item</h3>
                    <form method="POST" action="{{ route('rental-agreements.items.store', $contract) }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="kind" value="sale" />
                        <x-agreement-item-fields kind="sale" :products="$saleProducts" required
                                                 id-prefix="sale-add"
                                                 :contract-id="$contract->id"
                                                 :start-date="$contract->start_date->toDateString()"
                                                 :end-date="$contract->end_date->toDateString()" />
                        <x-primary-button>Add sale item</x-primary-button>
                    </form>
                </div>
            </div>

            <div class="max-w-4xl">
                <div class="p-4 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Remove items</h3>
                    @forelse ($contract->items as $item)
                        <div class="mt-2 flex items-center justify-between gap-3 text-sm">
                            <span class="text-gray-700">{{ $item->product?->name ?? '—' }} × {{ $item->quantity }}@if ($item->serial_number) <span class="text-gray-400">(SN: {{ $item->serial_number }})</span>@endif</span>
                            <form method="POST" action="{{ route('rental-agreements.items.destroy', [$contract, $item]) }}"
                                  onsubmit="return confirm('Remove this item?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-semibold uppercase tracking-widest text-red-600 hover:text-red-900">Remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-gray-500">Nothing to remove.</p>
                    @endforelse
                </div>
            </div>

            <div>
                <a href="{{ route('rental-agreements.show', $contract) }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to agreement
                </a>
            </div>
        </div>
    </div>

</x-app-layout>
