<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Maintenance') }}</h2>
            <a href="{{ route('service-hold.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Service hold</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    Fleet units away for repair. They are out of availability while away but stay on the fleet register
                    and keep depreciating. Each stint keeps its reason, vendor, dates, cost and work notes, so downtime
                    and repair spend add up in the unit's record. Service hold is different: that is supplier stock
                    waiting to join the fleet.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Open jobs</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $open->count() }}</p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Downtime (completed)</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $downtime }} {{ \Illuminate\Support\Str::plural('day', $downtime) }}</p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Maintenance cost to date</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900"><x-money :amount="$cost" :currency="$currency" /></p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Completed jobs shown</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $completed->count() }}</p>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Away now</h3>

                @forelse ($open as $job)
                    <div class="bg-white shadow sm:rounded-lg p-4 sm:p-6 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <a href="{{ route('products.show', $job->product) }}" class="font-mono text-sm text-indigo-600 hover:text-indigo-900">
                                    {{ $job->product?->equipment_number }}
                                </a>
                                <p class="text-sm text-gray-900">{{ $job->product?->equipmentModel?->label() ?: ($job->product?->name ?? '—') }}</p>
                                <p class="mt-1 text-sm text-gray-700">{{ $job->reason }}</p>
                                <p class="text-xs text-gray-500">
                                    @if ($job->vendor) {{ $job->vendor }} · @endif
                                    left {{ $job->started_on->format('M j, Y') }} ·
                                    {{ $job->daysOut() }} {{ \Illuminate\Support\Str::plural('day', $job->daysOut()) }} out
                                    @if ($job->expected_return_on)
                                        · due back {{ $job->expected_return_on->format('M j, Y') }}
                                    @endif
                                    @if ($job->cost !== null)
                                        · est. <x-money :amount="$job->cost" :currency="$currency" />
                                    @endif
                                </p>
                            </div>
                            @if ($job->isOverdue())
                                <span class="inline-flex items-center rounded px-2 py-1 text-xs font-semibold bg-amber-100 text-amber-800">
                                    Overdue
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <form method="POST" action="{{ route('maintenance.notes', $job) }}" class="space-y-2">
                                @csrf
                                @method('PATCH')
                                <x-input-label for="notes-{{ $job->id }}" value="Work notes" />
                                <textarea id="notes-{{ $job->id }}" name="notes" rows="3"
                                          class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                          placeholder="What is being done while it is in service">{{ $job->notes }}</textarea>
                                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                                <x-secondary-button type="submit">Save notes</x-secondary-button>
                            </form>

                            <form method="POST" action="{{ route('maintenance.complete', $job) }}" class="space-y-2">
                                @csrf
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <x-input-label for="completed_on-{{ $job->id }}" value="Back on" />
                                        <x-text-input id="completed_on-{{ $job->id }}" name="completed_on" type="date"
                                                      class="mt-1 block w-full" max="{{ now()->toDateString() }}"
                                                      value="{{ now()->toDateString() }}" required />
                                    </div>
                                    <div>
                                        <x-input-label for="cost-{{ $job->id }}" value="Final cost" />
                                        <x-text-input id="cost-{{ $job->id }}" name="cost" type="number" step="0.01" min="0"
                                                      class="mt-1 block w-full" value="{{ $job->cost }}" />
                                    </div>
                                </div>
                                <input type="hidden" name="notes" value="{{ $job->notes }}" />
                                <x-input-error :messages="$errors->get('completed_on')" class="mt-1" />
                                <x-primary-button>Mark back in service</x-primary-button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="p-6 bg-white shadow sm:rounded-lg text-sm text-gray-600">
                        Nothing is away for maintenance.
                    </div>
                @endforelse
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200">
                    <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Completed jobs</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Unit</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Reason</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Vendor</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Left</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Back</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Days out</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($completed as $job)
                                <tr>
                                    <td class="px-4 py-2">
                                        <a href="{{ route('products.show', $job->product) }}" class="font-mono text-xs text-indigo-600 hover:text-indigo-900">
                                            {{ $job->product?->equipment_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">{{ $job->reason }}</td>
                                    <td class="px-4 py-2 text-gray-700">{{ $job->vendor ?: '—' }}</td>
                                    <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $job->started_on->format('Y-m-d') }}</td>
                                    <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $job->completed_on?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-2 text-right text-gray-700">{{ $job->daysOut() }}</td>
                                    <td class="px-4 py-2 text-right text-gray-900 whitespace-nowrap">
                                        @if ($job->cost !== null)
                                            <x-money :amount="$job->cost" :currency="$currency" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-sm text-gray-600">
                                        No maintenance job has been completed yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
