@props([
    'kind',
    'index',
    'products',
    'values' => [],
    'defaultDuration' => 1,
    'contractId' => null,
    'startDate' => null,
    'endDate' => null,
])

@php($labelId = $kind . '-row-' . $index . '-label')

<div data-item-row-wrapper role="group" aria-labelledby="{{ $labelId }}"
     class="rounded-md border border-gray-200 p-3">
    <div class="flex items-center justify-between gap-2">
        <span id="{{ $labelId }}" class="text-xs font-semibold uppercase tracking-widest text-gray-500">
            {{ $kind === 'rental' ? 'Rental item' : 'Sale item' }}
        </span>
        <button type="button" data-remove-row
                aria-label="Remove this {{ $kind }} item"
                class="text-xs font-medium text-red-600 hover:text-red-800">
            Remove
        </button>
    </div>

    <div class="mt-3">
        <x-agreement-item-fields :kind="$kind" :products="$products" :values="$values"
                                 :name-prefix="$kind . '[' . $index . ']'"
                                 :error-prefix="$kind . '.' . $index . '.'"
                                 :id-prefix="$kind . '-' . $index"
                                 :default-duration="$defaultDuration"
                                 :contract-id="$contractId"
                                 :start-date="$startDate"
                                 :end-date="$endDate" />
    </div>
</div>
