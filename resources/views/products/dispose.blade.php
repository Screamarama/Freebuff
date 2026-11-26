<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dispose of {{ $product->equipment_number }}
            </h2>
            <a href="{{ route('products.show', $product) }}" class="text-sm text-gray-600 hover:text-gray-900">Back to unit</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    Recording a sale or scrap takes this unit out of availability and the fleet register. Its terms,
                    bookings and audit trail stay in history, and the gain or loss is fixed against its book value on
                    the date below.
                </p>

                <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 rounded-md bg-gray-50 p-4 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Book value today</dt>
                        <dd class="mt-1 font-semibold text-gray-900">
                            @if ($bookValue !== null)
                                <x-money :amount="$bookValue" />
                            @else
                                Not available
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-gray-500">Used sales price</dt>
                        <dd class="mt-1 font-semibold text-gray-900">
                            @if ($product->used_sale_price !== null)
                                <x-money :amount="$product->used_sale_price" />
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($bookValue === null)
                    <p class="mt-3 text-xs text-amber-700">
                        This unit has no cost, useful life or start date to depreciate, so no gain or loss can be
                        recorded. The sale itself is still kept.
                    </p>
                @endif

                <form method="POST" action="{{ route('products.dispose.store', $product) }}" class="mt-6 space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <x-input-label for="type" value="What happened" />
                            <select id="type" name="type" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="sold" @selected(old('type', 'sold') === 'sold')>Sold</option>
                                <option value="scrapped" @selected(old('type') === 'scrapped')>Scrapped</option>
                            </select>
                            <x-input-error :messages="$errors->get('type')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="disposed_on" value="Date it left the fleet" />
                            <x-text-input id="disposed_on" name="disposed_on" type="date" class="mt-1 block w-full"
                                          max="{{ now()->toDateString() }}"
                                          value="{{ old('disposed_on', now()->toDateString()) }}" required />
                            <x-input-error :messages="$errors->get('disposed_on')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="price" value="Price received" />
                            <x-text-input id="price" name="price" type="number" step="0.01" min="0"
                                          class="mt-1 block w-full" value="{{ old('price') }}"
                                          placeholder="0.00 for scrap" />
                            <p class="mt-1 text-xs text-gray-500">What the buyer paid, or what the scrap was worth.</p>
                            <x-input-error :messages="$errors->get('price')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="notes" value="Notes (optional)" />
                            <textarea id="notes" name="notes" rows="3"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                      placeholder="Buyer, reason for scrapping, anything worth keeping">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Record disposal</x-primary-button>
                        <a href="{{ route('products.show', $product) }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
