<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Sales tax') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-500">
                    Tax billed on agreements, grouped by the month it was billed in. The amount is the one
                    recorded when the agreement was billed, so it matches the customer's statement; tax added
                    or credited after that shows as a separate adjustment, and a reversed charge is credited in
                    the month it was reversed rather than removed from the month it was billed. Gross, taxable and
                    exempt cover sales that were taxed or exempt; a sale with no tax rate configured is not listed.
                </p>

                <form method="GET" action="{{ route('sales-tax.index') }}" class="mt-4 flex flex-wrap items-end gap-4">
                    <div>
                        <x-input-label for="from" value="From" />
                        <x-text-input id="from" name="from" type="date" class="mt-1" :value="$from" />
                    </div>
                    <div>
                        <x-input-label for="to" value="To" />
                        <x-text-input id="to" name="to" type="date" class="mt-1" :value="$to" />
                    </div>
                    <x-primary-button>{{ __('Filter') }}</x-primary-button>
                    @if ($from || $to)
                        <a href="{{ route('sales-tax.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Clear</a>
                    @endif
                    <a href="{{ route('sales-tax.export', array_filter(['from' => $from, 'to' => $to])) }}" class="text-sm text-gray-600 hover:text-gray-900">
                        Download CSV
                    </a>
                </form>
            </div>

            @forelse ($periods as $period)
                <div class="bg-white shadow sm:rounded-lg overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">{{ $period['month'] }}</h3>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 max-[460px]:w-full max-[460px]:justify-between">
                            <span>{{ $period['agreements'] }} {{ \Illuminate\Support\Str::plural('agreement', $period['agreements']) }}</span>
                            @if ($period['filing'])
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-gray-100 text-gray-700">
                                    Filed {{ $period['filing']->filed_at->format('Y-m-d') }}
                                </span>
                                <a href="{{ route('sales-tax.filings.export', $period['filing']) }}" title="Download the figures exactly as filed"
                                   class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">
                                    Filed CSV
                                </a>
                            @endif
                            @if ($period['closed'])
                                <form method="POST" action="{{ route('sales-tax.filings.store') }}">
                                    @csrf
                                    <input type="hidden" name="period" value="{{ $period['period'] }}" />
                                    <button type="submit"
                                            class="text-xs font-semibold text-indigo-600 hover:text-indigo-900"
                                            @if ($period['filing']) onclick="return confirm('Replace the filed figures with the current ones?')" @endif>
                                        {{ $period['filing'] ? 'Update filing' : 'Mark filed' }}
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-gray-500" title="Charges can still arrive until the month ends">
                                    Files after {{ $period['closes'] }}
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($period['history']->isNotEmpty())
                        <div class="px-4 py-2 border-b border-gray-200 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600">
                            <span>Earlier filings:</span>
                            @foreach ($period['history'] as $earlier)
                                <a href="{{ route('sales-tax.filings.export', $earlier) }}"
                                   title="Download these earlier filed figures"
                                   class="font-semibold text-indigo-600 hover:text-indigo-900">
                                    {{ $earlier->filed_at->format('Y-m-d H:i') }} CSV
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @if ($period['filing'] && $period['changes']['changed'])
                        <div class="px-4 py-3 border-b border-amber-200 bg-amber-100 text-sm text-amber-900">
                            <p class="font-semibold">Changed since this month was filed</p>
                            <p class="text-xs text-amber-800">
                                Filed {{ $period['filing']->filed_at->format('Y-m-d') }}
                                @if ($period['filing']->filedBy)
                                    by {{ $period['filing']->filedBy->name }}
                                @endif
                            </p>
                            <ul class="mt-1 space-y-1 max-w-2xl">
                                @foreach ($period['changes']['lines'] as $entry)
                                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                        <span>{{ $entry['label'] }}</span>
                                        <span class="shrink-0">
                                            <x-money :amount="$entry['amount']" :currency="$entry['currency']" />
                                            @if ($entry['removed'])
                                                <span class="text-amber-800">(no longer reported)</span>
                                            @elseif ($entry['was'] !== null)
                                                <span class="text-amber-800">(was <x-money :amount="$entry['was']" :currency="$entry['currency']" />)</span>
                                            @else
                                                <span class="text-amber-800">(new)</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($period['changes']['summary'])
                                <p class="mt-2">
                                    @foreach (['gross' => 'Gross', 'taxable' => 'Taxable', 'exempt' => 'Exempt', 'tax' => 'Tax'] as $key => $label)
                                        @foreach ($period['changes']['summary'][$key] ?? [] as $currency => $delta)
                                            {{ $label }} {{ $delta > 0 ? '+' : '' }}<x-money :amount="$delta" :currency="$currency" />
                                            {{ $loop->parent->last ? '' : ' · ' }}
                                        @endforeach
                                    @endforeach
                                </p>
                            @endif
                            <p class="mt-2">
                                <a href="{{ route('sales-tax.filings.amendment', $period['filing']) }}"
                                   class="text-xs font-semibold text-indigo-600 hover:text-indigo-900">
                                    Download amendment CSV
                                </a>
                            </p>
                        </div>
                    @elseif ($period['filing'])
                        <div class="px-4 py-2 border-b border-green-200 bg-green-50 text-sm text-green-800">
                            No changes since it was filed
                            @if ($period['filing']->filedBy)
                                by {{ $period['filing']->filedBy->name }}
                            @endif
                            on {{ $period['filing']->filed_at->format('Y-m-d') }}.
                        </div>
                    @endif

                    <div class="px-4 py-2 bg-gray-50 border-b border-gray-200 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        @foreach (['gross' => 'Gross sales', 'taxable' => 'Taxable sales', 'exempt' => 'Exempt sales', 'tax' => 'Tax charged'] as $key => $label)
                            <div>
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</p>
                                <p class="font-semibold text-gray-900">
                                    @forelse ($period['summary'][$key] as $currency => $amount)
                                        <x-money :amount="$amount" :currency="$currency" />{{ $loop->last ? '' : ' · ' }}
                                    @empty
                                        —
                                    @endforelse
                                </p>
                            </div>
                        @endforeach
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-gray-500 hidden sm:table-cell">Date</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-500">Agreement</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-500">Billed tax</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-500">Adjustment</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($period['rows'] as $row)
                                    <tr class="align-top">
                                        <td class="px-4 py-2 text-gray-700 whitespace-nowrap hidden sm:table-cell">{{ $row['date']->format('Y-m-d') }}</td>
                                        <td class="px-4 py-2">
                                            <div class="text-xs text-gray-500 sm:hidden">{{ $row['date']->format('Y-m-d') }}</div>
                                            <a href="{{ route('rental-agreements.show', $row['contract']) }}" class="text-indigo-600 hover:text-indigo-900">
                                                {{ $row['contract']->contract_number }}
                                            </a>
                                            <div class="text-xs text-gray-600">{{ $row['contract']->customer?->full_name ?? '—' }}</div>
                                            <div class="text-xs text-gray-500">{{ $row['label'] }} ({{ $row['rate'] }}%)</div>
                                        </td>
                                        <td class="px-4 py-2 text-right whitespace-nowrap {{ $row['billed'] == 0 ? 'text-gray-400' : 'text-gray-900' }}">
                                            @if ($row['billed'] == 0)
                                                —
                                            @else
                                                <x-money :amount="$row['billed']" :currency="$row['currency']" />
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-right whitespace-nowrap {{ $row['adjustment'] == 0 ? 'text-gray-400' : 'text-gray-900' }}">
                                            @if ($row['adjustment'] == 0)
                                                —
                                            @else
                                                {{ $row['adjustment'] > 0 ? '+' : '' }}<x-money :amount="$row['adjustment']" :currency="$row['currency']" />
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($period['exemptRows']->isNotEmpty())
                        <div class="border-t border-gray-200">
                            <h4 class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Exempt sales — no tax charged
                            </h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-xs text-gray-500">
                                            <th class="px-4 py-2 font-medium hidden sm:table-cell">Date</th>
                                            <th class="px-4 py-2 font-medium">Agreement</th>
                                            <th class="px-4 py-2 text-right font-medium">Sales</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        @foreach ($period['exemptRows'] as $row)
                                            <tr class="align-top">
                                                <td class="px-4 py-2 text-gray-700 whitespace-nowrap hidden sm:table-cell">{{ $row['date']->format('Y-m-d') }}</td>
                                                <td class="px-4 py-2">
                                                    <div class="text-xs text-gray-500 sm:hidden">{{ $row['date']->format('Y-m-d') }}</div>
                                                    <a href="{{ route('rental-agreements.show', $row['contract']) }}" class="text-indigo-600 hover:text-indigo-900">
                                                        {{ $row['contract']->contract_number }}
                                                    </a>
                                                    <div class="text-xs text-gray-600">
                                                        {{ $row['contract']->customer?->full_name ?? '—' }}
                                                        @if ($row['credit'])
                                                            <span class="text-amber-700">· reversed</span>
                                                        @endif
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        {{ $row['evidence']['number'] ?? 'No certificate number' }}
                                                        @if ($row['evidence']['expires_on'])
                                                            · expires {{ $row['evidence']['expires_on']->format('Y-m-d') }}
                                                        @endif
                                                        @if ($row['evidence']['reason'])
                                                            · {{ $row['evidence']['reason'] }}
                                                        @endif
                                                    </div>
                                                </td>
                                                <td class="px-4 py-2 text-right text-gray-900 whitespace-nowrap">
                                                    <x-money :amount="$row['sales']" :currency="$row['currency']" />
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="p-6 bg-white shadow sm:rounded-lg text-sm text-gray-600">
                    No tax has been billed in this period.
                </div>
            @endforelse

            @if ($periods->isNotEmpty())
                <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg space-y-2">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="text-sm font-semibold text-gray-900 uppercase tracking-wide">
                            Tax billed on {{ $count }} {{ \Illuminate\Support\Str::plural('agreement', $count) }}
                        </p>
                        <p class="text-lg font-semibold text-gray-900">
                            @foreach ($billedTotals as $currency => $amount)
                                <x-money :amount="$amount" :currency="$currency" />{{ $loop->last ? '' : ' · ' }}
                            @endforeach
                        </p>
                    </div>
                    @if (collect($adjustmentTotals)->contains(fn ($amount) => $amount != 0))
                        <div class="flex items-baseline justify-between gap-4 text-sm">
                            <p class="text-gray-600">Post-billing adjustments</p>
                            <p class="font-semibold text-gray-900">
                                @foreach ($adjustmentTotals as $currency => $amount)
                                    {{ $amount > 0 ? '+' : '' }}<x-money :amount="$amount" :currency="$currency" />{{ $loop->last ? '' : ' · ' }}
                                @endforeach
                            </p>
                        </div>
                    @endif
                    @foreach (['gross' => 'Gross sales', 'taxable' => 'Taxable sales', 'exempt' => 'Exempt sales'] as $key => $label)
                        @if (collect($summary[$key])->isNotEmpty())
                            <div class="flex items-baseline justify-between gap-4 text-sm">
                                <p class="text-gray-600">{{ $label }}</p>
                                <p class="font-semibold text-gray-900">
                                    @foreach ($summary[$key] as $currency => $amount)
                                        <x-money :amount="$amount" :currency="$currency" />{{ $loop->last ? '' : ' · ' }}
                                    @endforeach
                                </p>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
