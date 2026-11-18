@props(['name', 'id', 'label' => 'Method', 'selected' => 'cash'])

{{-- The three counter methods live in Payment so forms and validation agree. --}}
<div>
    <x-input-label :for="$id" :value="$label" />
    <select id="{{ $id }}" name="{{ $name }}"
            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
        @foreach (\App\Models\Payment::COUNTER_METHODS as $value)
            <option value="{{ $value }}" @selected(old($name, $selected) === $value)>
                {{ \App\Models\Payment::METHODS[$value] }}
            </option>
        @endforeach
    </select>
    <x-input-error class="mt-2" :messages="$errors->get($name)" />
</div>
