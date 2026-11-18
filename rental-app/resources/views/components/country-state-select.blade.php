@props(['country' => 'US', 'state' => null])

@php
    // After a failed save the state list must match the country just picked,
    // not the one still stored on the tenant.
    $active = old('country', $country);
@endphp

<div>
    <x-input-label for="country" value="Country" />
    <select id="country" name="country" required
            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
        @foreach (\App\Support\Countries::options() as $code => $option)
            <option value="{{ $code }}" @selected($active === $code)>{{ $option['name'] }}</option>
        @endforeach
    </select>
    <x-input-error class="mt-2" :messages="$errors->get('country')" />
</div>

<x-state-select :country="$active" :selected="$state" />

{{-- Swap the state list when the country changes, without a round trip. --}}
<script>
    (() => {
        const countries = @json(\App\Support\Countries::options());
        const country = document.getElementById('country');
        const state = document.getElementById('state');

        if (!country || !state) {
            return;
        }

        country.addEventListener('change', () => {
            const names = countries[country.value]?.subdivisions ?? [];

            state.length = 0;
            state.add(new Option('— Select —', ''));
            names.forEach(name => state.add(new Option(name, name)));
        });
    })();
</script>
