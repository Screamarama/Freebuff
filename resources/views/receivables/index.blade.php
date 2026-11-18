<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Receivables') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 bg-white shadow sm:rounded-lg">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">On account</h3>
                    <span class="text-xs text-gray-400">Charges age from their own due date — 30 days after they went on the account</span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Total open</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            <x-money :amount="$aging['open']" :currency="$currency" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Overdue</dt>
                        <dd class="mt-1 text-lg font-semibold {{ $aging['overdue'] > 0 ? 'text-red-700' : 'text-green-700' }}">
                            <x-money :amount="$aging['overdue']" :currency="$currency" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Credited</dt>
                        <dd class="mt-1 text-lg font-semibold {{ $aging['credited'] > 0 ? 'text-amber-700' : 'text-gray-900' }}">
                            <x-money :amount="$aging['credited']" :currency="$currency" />
                        </dd>
                    </div>
                    @foreach ($buckets as $key => $label)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">{{ $label }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                <x-money :amount="$aging['totals'][$key]" :currency="$currency" />
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if (empty($aging['rows']))
                    <p class="text-sm text-gray-500">Nothing is owed on a charge account right now.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Customer</th>
                                    <th class="px-4 py-3">Oldest due</th>
                                    @foreach ($buckets as $label)
                                        <th class="px-4 py-3 text-right">{{ $label }}</th>
                                    @endforeach
                                    <th class="px-4 py-3 text-right">Credited</th>
                                    <th class="px-4 py-3 text-right">Owed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($aging['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <a href="{{ route('customers.show', $row['customer']) }}" class="text-indigo-600 hover:text-indigo-900">
                                                {{ $row['customer']->full_name }}
                                            </a>
                                            <a href="{{ route('customers.statement', $row['customer']) }}"
                                               class="ml-2 whitespace-nowrap text-xs font-normal text-gray-500 hover:text-gray-900">
                                                Statement
                                            </a>
                                        </td>
                                        {{-- Red only when a bucket says it is late: due today is not overdue. --}}
                                        {{-- Red only when a bucket says it is late: due today is not overdue. --}}
                                        <td class="px-4 py-3 whitespace-nowrap {{ $row['overdue'] > 0 ? 'text-red-700' : 'text-gray-700' }}">
                                            {{ $row['oldest_due_on']?->format('Y-m-d') ?? '—' }}
                                        </td>
                                        @foreach ($buckets as $key => $label)
                                            <td class="px-4 py-3 text-right text-gray-700">
                                                @if ($row['buckets'][$key] > 0)
                                                    <x-money :amount="$row['buckets'][$key]" :currency="$currency" />
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="px-4 py-3 text-right text-amber-700">
                                            @if ($row['credited'] > 0)
                                                <x-money :amount="$row['credited']" :currency="$currency" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-gray-900">
                                            <x-money :amount="$row['open']" :currency="$currency" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div>
                <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to customers
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
