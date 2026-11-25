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
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Units in service</h3>
                    @if ($units->isNotEmpty())
                        <span class="text-xs {{ $differing->isEmpty() ? 'text-gray-500' : 'text-amber-700' }}">
                            @if ($differing->isEmpty())
                                All {{ $units->count() }} use these terms
                            @else
                                {{ $differing->count() }} of {{ $units->count() }} use different terms
                            @endif
                        </span>
                    @endif
                </div>

                @if ($units->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">No units in service yet. Receiving and committing a rental purchase order adds them.</p>
                @else
                    <ul class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($units as $unit)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('products.show', $unit) }}" class="font-mono text-xs text-indigo-600 hover:text-indigo-900">
                                    {{ $unit->equipment_number }}
                                </a>
                                @if ($differing->contains('id', $unit->id))
                                    <span class="text-xs text-amber-700">Terms differ</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($differing->isNotEmpty() && in_array(auth()->user()?->role, ['super_admin', 'admin', 'manager'], true))
                        <form method="POST" action="{{ route('equipment-models.apply-defaults', $model) }}" class="mt-4">
                            @csrf
                            <x-primary-button>
                                Apply these terms to {{ $differing->count() }} {{ \Illuminate\Support\Str::plural('unit', $differing->count()) }}
                            </x-primary-button>
                            <p class="mt-2 text-xs text-gray-500">
                                Rates, useful life and salvage are copied. Equipment numbers, serials, purchase prices and
                                used sale prices stay as they are.
                            </p>
                        </form>
                    @elseif ($differing->isNotEmpty())
                        <p class="mt-3 text-xs text-gray-400">Admins and managers can apply these terms to the units.</p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
