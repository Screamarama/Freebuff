<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $heading }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $purchaseOrder ?: 'Purchase order' }}
                    · {{ $units->count() }} {{ \Illuminate\Support\Str::plural('unit', $units->count()) }} leaving the fleet
                </p>
            </div>
            <a href="{{ route('service-hold.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Back to service hold</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ $action }}" class="space-y-6">
                @csrf

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-6">
                    @if ($errors->any())
                        <div class="p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-800">
                            <ul class="list-disc ps-5 space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p class="text-sm text-gray-600">
                        These units leave the bookable fleet and go back to service hold, where their asset tag and
                        catalogue entry can be corrected and committed again. Nothing is deleted: numbers, serials and
                        rates stay as they are until then.
                    </p>

                    <div class="overflow-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-3 py-2">Equipment number</th>
                                    <th class="px-3 py-2">Equipment</th>
                                    <th class="px-3 py-2">Catalogue model</th>
                                    <th class="px-3 py-2">Serial</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($units as $unit)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $unit->equipment_number ?: '—' }}</td>
                                        <td class="px-3 py-2 font-medium text-gray-900">{{ $unit->name ?: 'Unnamed equipment' }}</td>
                                        <td class="px-3 py-2 text-gray-700">{{ $unit->equipmentModel?->name ?: '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $unit->serial_number ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <x-input-label for="reason" value="Why is it going back to service hold?" />
                        <textarea id="reason" name="reason" rows="3" maxlength="500" required
                                  placeholder="e.g. Wrong asset tag — this unit should be FL-204"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>

                    <p class="text-xs text-gray-500">
                        A unit that is out on hire or held by a reservation cannot go back to service hold.
                    </p>

                    <div class="flex items-center gap-4">
                        <x-primary-button>
                            Return {{ $units->count() }} {{ \Illuminate\Support\Str::plural('unit', $units->count()) }} to service hold
                        </x-primary-button>
                        <a href="{{ route('service-hold.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
