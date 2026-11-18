@props(['name', 'label', 'value' => null])

{{-- A whole percentage: the stored value is the number before the % sign. --}}
<div {{ $attributes }}>
    <x-input-label :for="$name" :value="$label.' (%)'" />
    <div class="mt-1 flex items-center gap-2">
        <x-text-input :id="$name" :name="$name" type="number" min="0" max="100" step="0.001"
                      class="block w-full" :value="old($name, $value)" />
        <span class="text-sm text-gray-600">%</span>
    </div>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
