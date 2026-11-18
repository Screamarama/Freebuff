<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New reservation') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-800">
                Booking ahead does <strong>not</strong> lock the item. It stays rentable until
                <strong>24 hours before it starts</strong>, then flips to locked for prep.
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('reservations.store') }}" class="space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <x-input-label for="product_id" value="Rental item" />
                            <select id="product_id" name="product_id" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">Select an item</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}" @selected((string) old('product_id', $selectedProductId) === (string) $product->id)>
                                        {{ $product->name }} (stock: {{ $product->stock_quantity }})
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('product_id')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="customer_id" value="Customer" />
                            <select id="customer_id" name="customer_id" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">Select a customer</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>
                                        {{ $customer->first_name }} {{ $customer->last_name }} ({{ $customer->customer_code }})
                                    </option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('customer_id')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="quantity" value="Quantity" />
                            <x-text-input id="quantity" name="quantity" type="number" min="1" class="mt-1 block w-full"
                                          value="{{ old('quantity', 1) }}" required />
                            <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
                        </div>

                        <div></div>

                        <div>
                            <x-input-label for="starts_at" value="Starts at" />
                            <x-text-input id="starts_at" name="starts_at" type="datetime-local" class="mt-1 block w-full"
                                          value="{{ old('starts_at') }}" required />
                            <x-input-error :messages="$errors->get('starts_at')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="ends_at" value="Ends at" />
                            <x-text-input id="ends_at" name="ends_at" type="datetime-local" class="mt-1 block w-full"
                                          value="{{ old('ends_at') }}" required />
                            <x-input-error :messages="$errors->get('ends_at')" class="mt-2" />
                        </div>

                        <div class="sm:col-span-2 p-4 bg-indigo-50 border border-indigo-200 rounded-md">
                            <div class="text-xs font-medium uppercase tracking-widest text-indigo-600">Estimated total rental fee</div>
                            <div id="fee-estimate" class="mt-1 text-2xl font-semibold text-gray-900">—</div>
                            <p id="fee-breakdown" class="mt-1 text-xs text-gray-500">Select an item and dates to see the closest hour / day / week / month calculation.</p>
                        </div>

                        <div class="sm:col-span-2">
                            <x-input-label for="notes" value="Notes" />
                            <textarea id="notes" name="notes" rows="3"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notes') }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Save reservation</x-primary-button>
                        <a href="{{ route('reservations.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        {{-- Js::from, not @json: the directive splits its argument on commas. --}}
        const PRODUCTS = {{ Illuminate\Support\Js::from($products->map(fn ($p) => [
            'id' => $p->id,
            'hour' => $p->rental_price_hour !== null ? (float) $p->rental_price_hour : null,
            'day' => $p->rental_price_day !== null ? (float) $p->rental_price_day : null,
            'week' => $p->rental_price_week !== null ? (float) $p->rental_price_week : null,
            'month' => $p->rental_price_month !== null ? (float) $p->rental_price_month : null,
        ])->keyBy('id')) }};
        const moneyPrefix = @json(\App\Support\Money::symbol(auth()->user()?->business?->currency));
        const money = (n) => moneyPrefix + Number(n).toFixed(2);
        function calcEstimate() {
            const pid = document.getElementById('product_id').value;
            const qty = Math.max(1, parseInt(document.getElementById('quantity').value || '1', 10));
            const s = document.getElementById('starts_at').value;
            const e = document.getElementById('ends_at').value;
            const totalEl = document.getElementById('fee-estimate');
            const subEl = document.getElementById('fee-breakdown');
            const product = PRODUCTS[pid];
            if (!product || !s || !e) {
                totalEl.textContent = '—';
                subEl.textContent = 'Select an item and dates to see the closest hour / day / week / month calculation.';
                return;
            }
            const hours = Math.max(1, Math.ceil((new Date(e) - new Date(s)) / 3600000));
            if (isNaN(hours) || hours <= 0) {
                totalEl.textContent = '—';
                subEl.textContent = 'End must be after start.';
                return;
            }
            let months = Math.floor(hours / 720), rem = hours % 720;
            let weeks = Math.floor(rem / 168); rem %= 168;
            let days = Math.floor(rem / 24), hrs = rem % 24;
            if (product.month === null && months > 0) { days += months * 30; months = 0; }
            if (product.week === null && (weeks > 0 || months > 0)) { days += weeks * 7 + months * 30; weeks = months = 0; }
            if (product.day === null && (days > 0 || weeks > 0 || months > 0)) { hrs += days * 24 + weeks * 168 + months * 720; days = weeks = months = 0; }
            if (product.hour === null && hrs > 0) {
                if (product.day !== null) { days += Math.ceil(hrs / 24); hrs = 0; }
                else if (product.week !== null) { weeks += Math.ceil(hrs / 168); hrs = 0; }
                else if (product.month !== null) { months += Math.ceil(hrs / 720); hrs = 0; }
            }
            const parts = [];
            [['month', months], ['week', weeks], ['day', days], ['hour', hrs]].forEach(([unit, count]) => {
                if (count > 0 && product[unit] !== null) parts.push(`${count} ${unit}${count > 1 ? 's' : ''} @ ${money(product[unit])}`);
            });
            if (!parts.length) { totalEl.textContent = '—'; subEl.textContent = 'No rental rate set for this item.'; return; }
            let perUnit = 0;
            [['month', months], ['week', weeks], ['day', days], ['hour', hrs]].forEach(([unit, count]) => {
                if (count > 0 && product[unit] !== null) perUnit += count * product[unit];
            });
            totalEl.textContent = money(perUnit * qty);
            subEl.textContent = parts.join(' + ') + (qty > 1 ? ` × ${qty} units` : '');
        }
        ['product_id', 'quantity', 'starts_at', 'ends_at'].forEach((id) => {
            document.getElementById(id).addEventListener('change', calcEstimate);
            document.getElementById(id).addEventListener('input', calcEstimate);
        });
        calcEstimate();
    </script>
</x-app-layout>
