<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Equipment models</h2>
            <a href="{{ route('equipment-models.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                New model
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-600">
                    A model is the make and spec you hire out. Its rental rates, useful life and salvage value are copied
                    onto each unit; receive a rental purchase order to add units in bulk.
                </p>

                <form method="GET" action="{{ route('equipment-models.index') }}" class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="search" value="Search" />
                        <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                                      placeholder="Name, manufacturer, model number or category" value="{{ request('search') }}" />
                    </div>
                    <div class="flex items-center gap-4">
                        <x-primary-button>Search</x-primary-button>
                        @if (request('search'))
                            <a href="{{ route('equipment-models.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Reset</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($models->isEmpty())
                    <p class="text-sm text-gray-500">
                        No equipment models yet.
                        <a href="{{ route('equipment-models.create') }}" class="text-indigo-600 hover:text-indigo-900">Add one</a>
                        before creating a rental unit.
                    </p>
                @else
                    <div class="overflow-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Model</th>
                                    <th class="px-4 py-3">Manufacturer</th>
                                    <th class="px-4 py-3">Category</th>
                                    <th class="px-4 py-3 text-right">Daily rate</th>
                                    <th class="px-4 py-3 text-right">Units</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($models as $model)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <a href="{{ route('equipment-models.edit', $model) }}" class="hover:text-indigo-600">
                                                {{ $model->name }}
                                            </a>
                                            @if ($model->model_number)
                                                <div class="text-xs text-gray-500">{{ $model->model_number }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ $model->manufacturer ?: '—' }}</td>
                                        <td class="px-4 py-3 text-gray-700">{{ $model->category ?: '—' }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">
                                            @if ($model->rental_price_day !== null)
                                                <x-money :amount="$model->rental_price_day" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $model->products_count }}</td>
                                        <td class="px-4 py-3">
                                            @if ($model->is_active)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Active</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">Retired</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-3">
                                                <a href="{{ route('equipment-models.edit', $model) }}" class="text-sm text-gray-600 hover:text-gray-900">Edit</a>
                                                @if ($model->is_active)
                                                    <form method="POST" action="{{ route('equipment-models.destroy', $model) }}"
                                                          onsubmit="return confirm('Retire this model? Existing units are not changed.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-sm text-red-600 hover:text-red-800">Retire</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">{{ $models->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
