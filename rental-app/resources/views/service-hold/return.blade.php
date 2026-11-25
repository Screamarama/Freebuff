<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $heading }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $purchaseOrder ?: 'Purchase order' }}
                    · {{ $units->count() }} {{ \Illuminate\Support\Str::plural('unit', $units->count()) }} leaving service hold
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
                        These units leave service hold and never join the fleet. The return, its reason and
                        any credit note are kept in the audit log.
                    </p>

                    <div class="overflow-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-3 py-2">Temporary tag</th>
                                    <th class="px-3 py-2">Equipment</th>
                                    <th class="px-3 py-2">Received</th>
                                    <th class="px-3 py-2 text-right">Unit cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($units as $unit)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $unit->equipment_number ?: '—' }}</td>
                                        <td class="px-3 py-2 font-medium text-gray-900">{{ $unit->name ?: 'Unnamed equipment' }}</td>
                                        <td class="px-3 py-2 text-gray-700 whitespace-nowrap">
                                            {{ $unit->purchase_date?->format('M j, Y') ?: '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-right text-gray-700 whitespace-nowrap">
                                            @if ($unit->cost_price !== null)
                                                <x-money :amount="$unit->cost_price" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <x-input-label for="reason" value="Why is it going back?" />
                        <textarea id="reason" name="reason" rows="3" maxlength="500" required
                                  placeholder="e.g. Damaged on arrival — hydraulic leak on the left track"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('reason') }}</textarea>
                        <x-input-error :messages="$errors->get('reason')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="credit_note" value="Supplier credit note (optional)" />
                        <x-text-input id="credit_note" name="credit_note" type="text" maxlength="100"
                                      class="mt-1 block w-full" value="{{ old('credit_note') }}"
                                      placeholder="e.g. CN-1042" />
                        <x-input-error :messages="$errors->get('credit_note')" class="mt-2" />
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                        <p class="text-sm font-medium text-gray-700">What happens to the order?</p>

                        <label class="mt-3 flex items-start gap-2 text-sm text-gray-700">
                            <input type="radio" name="resolution" value="replacement" class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                   @checked(old('resolution', 'replacement') === 'replacement') />
                            <span>
                                The supplier will replace {{ $units->count() === 1 ? 'it' : 'them' }}
                                <span class="block text-xs text-gray-500">
                                    The quantity goes back to outstanding on
                                    {{ $purchaseOrder ?: 'the purchase order' }}, ready to receive again.
                                </span>
                            </span>
                        </label>

                        <label class="mt-3 flex items-start gap-2 text-sm text-gray-700">
                            <input type="radio" name="resolution" value="credit" class="mt-0.5 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                   @checked(old('resolution') === 'credit') />
                            <span>
                                No replacement — a credit note settles it
                                <span class="block text-xs text-gray-500">
                                    The line stays received; nothing goes back to outstanding.
                                </span>
                            </span>
                        </label>

                        <x-input-error :messages="$errors->get('resolution')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>
                            Return {{ $units->count() }} {{ \Illuminate\Support\Str::plural('unit', $units->count()) }}
                        </x-primary-button>
                        <a href="{{ route('service-hold.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
