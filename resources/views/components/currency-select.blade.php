@props(['selected' => null, 'hint' => null])

{{-- One select for every form that sets a tenant's currency, fed by the codes
     Money can format, so the three screens can't offer different lists. --}}
<div>
    <x-input-label for="currency" value="Currency" />
    <select id="currency" name="currency" required
            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
        @foreach (\App\Support\Money::codes() as $code)
            <option value="{{ $code }}" @selected(old('currency', $selected ?? 'USD') === $code)>
                {{ $code }} ({{ \App\Support\Money::symbol($code) }})
            </option>
        @endforeach
    </select>
    <x-input-error class="mt-2" :messages="$errors->get('currency')" />
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
