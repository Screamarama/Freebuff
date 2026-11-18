@props(['country' => 'US', 'selected' => null])

@php
    $subdivisions = \App\Support\Countries::subdivisions($country);
    $current = old('state', $selected);
    // A tenant saved before this list existed keeps its state visible and
    // selectable rather than having it silently dropped on the next save.
    $legacy = filled($current) && ! in_array($current, $subdivisions, true);
@endphp

{{-- State/province options come from the tenant's country, so the value saved
     always belongs to the country chosen above it. --}}
<div>
    <x-input-label for="state" value="State / province (governing law)" />
    <select id="state" name="state"
            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
        <option value="">— Select —</option>
        @if ($legacy)
            <option value="{{ $current }}" selected>{{ $current }} (not in this list)</option>
        @endif
        @foreach ($subdivisions as $name)
            <option value="{{ $name }}" @selected($current === $name)>{{ $name }}</option>
        @endforeach
    </select>
    <x-input-error class="mt-2" :messages="$errors->get('state')" />
</div>
