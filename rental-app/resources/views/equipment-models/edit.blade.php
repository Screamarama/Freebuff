<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit {{ $model->name }}</h2>
            <a href="{{ route('equipment-models.index') }}" class="text-sm text-gray-600 hover:text-gray-900">All models</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="max-w-3xl p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('equipment-models.update', $model) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    @include('equipment-models._fields')

                    <div class="flex items-center gap-4">
                        <x-primary-button>Update model</x-primary-button>
                        <a href="{{ route('equipment-models.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Units of this model</h3>

                @if ($model->products()->doesntExist())
                    <p class="mt-2 text-sm text-gray-500">No units yet. Receiving a rental purchase order for this model adds them.</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($model->products()->orderBy('equipment_number')->get() as $unit)
                            <li class="flex items-center justify-between py-2">
                                <a href="{{ route('products.show', $unit) }}" class="font-mono text-xs text-indigo-600 hover:text-indigo-900">
                                    {{ $unit->equipment_number }}
                                </a>
                                <span class="text-gray-600">{{ $unit->is_active ? 'In service' : 'Retired' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
