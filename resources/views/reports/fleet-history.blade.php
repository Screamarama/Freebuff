<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Fleet history') }}</h2>
            <a href="{{ route('reports.fleet-value') }}" class="text-sm text-gray-600 hover:text-gray-900">
                Fleet value
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    Units that have left the fleet, newest first. The book value is the one they carried on the day
                    they left, frozen at disposal, so later changes to rates or terms cannot rewrite the gain or loss.
                    Disposed units no longer appear in availability or the fleet value register.
                </p>

                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Units disposed</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">{{ $count }}</p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Proceeds</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900"><x-money :amount="$proceeds" :currency="$currency" /></p>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Book value at disposal</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900"><x-money :amount="$book" :currency="$currency" /></p>
                        @if ($unvalued > 0)
                            <p class="mt-1 text-xs text-amber-700">
                                Excludes {{ $unvalued }} {{ \Illuminate\Support\Str::plural('unit', $unvalued) }} without a book value.
                            </p>
                        @endif
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <p class="text-xs uppercase tracking-wider text-gray-500">Net gain / loss</p>
                        <p class="mt-1 text-xl font-semibold {{ $gain >= 0 ? 'text-green-700' : 'text-red-700' }}">
                            {{ $gain >= 0 ? '+' : '' }}<x-money :amount="$gain" :currency="$currency" />
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Date</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Unit</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Model</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Outcome</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Price</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Book value</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Gain / loss</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse ($disposals as $disposal)
                                <tr>
                                    <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $disposal->disposed_on?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-2">
                                        @if ($disposal->product)
                                            <a href="{{ route('products.show', $disposal->product) }}" class="font-mono text-xs text-indigo-600 hover:text-indigo-900">
                                                {{ $disposal->product->equipment_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">
                                        {{ $disposal->product?->equipmentModel?->label() ?: ($disposal->product?->name ?? '—') }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $disposal->type === \App\Models\FleetDisposal::SOLD ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                                            {{ $disposal->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-900 whitespace-nowrap">
                                        <x-money :amount="$disposal->price" :currency="$currency" />
                                    </td>
                                    <td class="px-4 py-2 text-right text-gray-700 whitespace-nowrap">
                                        @if ($disposal->book_value !== null)
                                            <x-money :amount="$disposal->book_value" :currency="$currency" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right whitespace-nowrap font-semibold {{ $disposal->gain() === null ? 'text-gray-400' : ($disposal->gain() >= 0 ? 'text-green-700' : 'text-red-700') }}">
                                        @if ($disposal->gain() === null)
                                            —
                                        @else
                                            {{ $disposal->gain() >= 0 ? '+' : '' }}<x-money :amount="$disposal->gain()" :currency="$currency" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-600 max-w-xs">
                                        {{ $disposal->notes ?: '—' }}
                                        @if ($disposal->recordedBy)
                                            <span class="block text-xs text-gray-400">by {{ $disposal->recordedBy->name }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-6 text-sm text-gray-600">
                                        No unit has been sold or scrapped yet.
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
