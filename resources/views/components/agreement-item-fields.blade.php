@props([
    'kind',
    'products',
    'values' => [],
    'namePrefix' => '',
    'errorPrefix' => '',
    'idPrefix' => null,
    'defaultDuration' => 1,
    'contractId' => null,
    'startDate' => null,
    'endDate' => null,
    'required' => false,
])

@php
    $isRental = $kind === 'rental';
    $field = fn (string $name) => $namePrefix === '' ? $name : $namePrefix . '[' . $name . ']';
    $idPrefix = $idPrefix ?? $kind . '-add';
    $id = fn (string $name) => $idPrefix . '-' . $name;
    $error = fn (string $name) => $errors->get($errorPrefix . $name);
    $invalid = fn (string $name) => $error($name) ? 'true' : 'false';
    $hasErrors = collect(['product_id', 'rate_type', 'duration', 'quantity', 'unit_price', 'serial_number'])
        ->contains(fn (string $name) => $errors->has($errorPrefix . $name));
@endphp

<div data-item-row data-item-kind="{{ $kind }}"
     data-availability-url="{{ route('rental-agreements.availability') }}"
     data-contract-id="{{ $contractId }}"
     data-start-date="{{ $startDate }}"
     data-end-date="{{ $endDate }}"
     @if ($hasErrors) data-invalid="1" @endif
     class="space-y-3">
    <div>
        <x-input-label :for="$id('product_id')" value="Item" />
        <select id="{{ $id('product_id') }}" name="{{ $field('product_id') }}" data-product-select
                @required($required) aria-invalid="{{ $invalid('product_id') }}"
                aria-describedby="{{ $id('stock-hint') }}"
                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
            <option value="">No {{ $isRental ? 'rental' : 'sale' }} item</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}"
                        @if ($isRental)
                            data-hour="{{ $product->rental_price_hour }}"
                            data-day="{{ $product->rental_price_day }}"
                            data-week="{{ $product->rental_price_week }}"
                            data-month="{{ $product->rental_price_month }}"
                        @else
                            data-sale="{{ $product->sale_price }}"
                        @endif
                        @selected(old($field('product_id'), $values['product_id'] ?? null) == $product->id)>
                    {{ $product->name }}@unless ($isRental) (<x-money :amount="$product->sale_price" />)@endunless
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$error('product_id')" class="mt-2" />
        <p id="{{ $id('stock-hint') }}" data-stock-hint aria-live="polite" class="mt-2 text-xs text-gray-500">
            {{ $isRental ? 'Choose an item to check availability.' : 'Choose an item to check stock.' }}
        </p>
    </div>

    <div class="grid grid-cols-2 gap-3">
        @if ($isRental)
            <div>
                <x-input-label :for="$id('rate_type')" value="Rate" />
                <select id="{{ $id('rate_type') }}" name="{{ $field('rate_type') }}" data-rate-type
                        @required($required) aria-invalid="{{ $invalid('rate_type') }}"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                    @foreach (['hour', 'day', 'week', 'month'] as $rate)
                        <option value="{{ $rate }}"
                                @selected(old($field('rate_type'), $values['rate_type'] ?? 'day') === $rate)>
                            {{ ucfirst($rate) }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$error('rate_type')" class="mt-2" />
            </div>
            <div>
                <x-input-label :for="$id('duration')" value="Duration" />
                <input id="{{ $id('duration') }}" name="{{ $field('duration') }}" type="number" min="1"
                       data-duration @required($required)
                       data-duration-auto="{{ array_key_exists('duration', $values) || old($field('duration')) !== null ? '0' : '1' }}"
                       aria-invalid="{{ $invalid('duration') }}"
                       class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                       value="{{ old($field('duration'), $values['duration'] ?? $defaultDuration) }}" />
                <x-input-error :messages="$error('duration')" class="mt-2" />
            </div>
        @endif

        <div>
            <x-input-label :for="$id('quantity')" value="Quantity" />
            <input id="{{ $id('quantity') }}" name="{{ $field('quantity') }}" type="number" min="1"
                   data-quantity @required($required) aria-invalid="{{ $invalid('quantity') }}"
                   class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                   value="{{ old($field('quantity'), $values['quantity'] ?? 1) }}" />
            <x-input-error :messages="$error('quantity')" class="mt-2" />
        </div>
        <div>
            <x-input-label :for="$id('unit_price')" value="Price override ($)" />
            <input id="{{ $id('unit_price') }}" name="{{ $field('unit_price') }}" type="number" step="0.01" min="0"
                   data-unit-price="1" placeholder="{{ $isRental ? 'List rate' : 'Sale price' }}"
                   aria-invalid="{{ $invalid('unit_price') }}"
                   class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                   value="{{ old($field('unit_price'), $values['unit_price'] ?? '') }}" />
            <x-input-error :messages="$error('unit_price')" class="mt-2" />
        </div>
        <div class="col-span-2">
            <x-input-label :for="$id('serial_number')" value="Serial # (optional)" />
            <input id="{{ $id('serial_number') }}" name="{{ $field('serial_number') }}" type="text" maxlength="100"
                   placeholder="If serialized" aria-invalid="{{ $invalid('serial_number') }}"
                   class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                   value="{{ old($field('serial_number'), $values['serial_number'] ?? '') }}" />
            <x-input-error :messages="$error('serial_number')" class="mt-2" />
        </div>
    </div>

    <p id="{{ $id('rate-hint') }}" data-rate-hint class="text-xs text-gray-500">
        {{ $isRental ? 'Select an item to see its list rate.' : 'Select an item to see its sale price.' }}
    </p>
</div>
