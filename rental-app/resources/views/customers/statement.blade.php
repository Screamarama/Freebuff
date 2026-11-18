<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Statement — {{ $customer->first_name }} {{ $customer->last_name }}
            </h2>

            <div class="flex gap-2">
                <a href="{{ route('customers.show', $customer) }}"
                   class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                    Back to customer
                </a>
                <button type="button" onclick="window.print()"
                        class="rounded-md bg-gray-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700">
                    Print
                </button>
            </div>
        </div>
    </x-slot>

    <style>
        /* Extra bottom margin leaves room for the page counter. */
        @page {
            margin: 12mm 12mm 16mm;

            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 9px;
                color: #9ca3af;
            }
        }

        @media print {
            nav,
            header {
                display: none !important;
            }

            /* The app shell paints the theme background with a two-class
               !important rule, so match its specificity to print white. */
            body .min-h-screen.bg-gray-100 {
                background: #fff !important;
                min-height: 0 !important;
            }

            .statement-page,
            .statement-frame,
            .statement-sheet {
                padding: 0 !important;
                max-width: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            tr,
            .statement-aging {
                break-inside: avoid;
            }
        }
    </style>

    <div class="statement-page py-8">
        <div class="statement-frame mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="statement-sheet bg-white p-8 shadow sm:rounded-lg">
                <div class="flex items-start justify-between gap-6 border-b border-gray-200 pb-6">
                    <div>
                        <p class="text-lg font-semibold text-gray-900">{{ $customer->business?->name ?? config('app.name') }}</p>
                        <p class="mt-1 text-sm text-gray-500">Customer statement</p>
                    </div>
                    <div class="text-right text-sm text-gray-500">
                        <p>Issued {{ now()->format('j M Y') }}</p>
                        <p class="mt-1">{{ $statement['lines'] ? 'Activity to '.collect($statement['lines'])->max('date')->format('j M Y') : 'No activity' }}</p>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-widest text-gray-500">Customer</p>
                        <p class="mt-1 break-words font-medium text-gray-900">{{ $customer->first_name }} {{ $customer->last_name }}</p>
                        <p class="font-mono text-xs text-gray-500">{{ $customer->customer_code }}</p>
                        @if ($customer->phone)
                            <p class="break-words text-gray-600">{{ $customer->phone }}</p>
                        @endif
                        @if ($customer->email)
                            <p class="break-words text-gray-600">{{ $customer->email }}</p>
                        @endif
                    </div>
                    <div class="sm:text-right">
                        <p class="text-xs font-medium uppercase tracking-widest text-gray-500">Balance</p>
                        <p class="mt-1 text-2xl font-semibold {{ $position['net'] > 0 ? 'text-gray-900' : 'text-green-700' }}">
                            <x-money :amount="$position['net']" :currency="$currency" />
                        </p>
                        <p class="text-gray-600">
                            @if ($position['owed'] > 0 && $position['credited'] > 0)
                                <x-money :amount="$position['owed']" :currency="$currency" /> owed,
                                <x-money :amount="$position['credited']" :currency="$currency" /> credit
                            @elseif ($position['credited'] > 0)
                                Credit held for the customer
                            @elseif ($position['owed'] > 0)
                                Amount due
                            @else
                                Nothing outstanding
                            @endif
                        </p>
                    </div>
                </div>

                <table class="mt-8 w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-300 text-left text-xs font-medium uppercase tracking-widest text-gray-500">
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3">Description</th>
                            <th class="py-2 pr-3 text-right">Amount</th>
                            <th class="py-2 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($statement['lines'] as $line)
                            @php($struck = $line['excluded'] ? 'text-gray-500 line-through' : '')
                            <tr>
                                <td class="py-2 pr-3 whitespace-nowrap text-gray-600">{{ $line['date']->format('j M Y') }}</td>
                                <td class="py-2 pr-3 break-words text-gray-900">
                                    {{-- A voided or reversed line stays on the page, struck through. --}}
                                    <span class="{{ $struck }}">{{ $line['description'] }}</span>
                                    @if ($line['note'])
                                        <span class="block text-xs text-gray-500">{{ $line['note'] }}</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap {{ $line['amount'] < 0 ? 'text-green-700' : 'text-gray-900' }}">
                                    <span class="{{ $struck }}">
                                        <x-money :amount="$line['amount']" :currency="$line['currency'] ?? $currency" />
                                    </span>
                                </td>
                                <td class="py-2 text-right whitespace-nowrap text-gray-900">
                                    @if ($line['balance'] === null)
                                        —
                                    @else
                                        <x-money :amount="$line['balance']" :currency="$line['currency'] ?? $currency" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-gray-500">No charges or payments on this account.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                @if ($statement['lines'])
                    {{-- After the table, not a tfoot: browsers repeat tfoot on every page. --}}
                    <div class="mt-3 flex items-center justify-between border-t-2 border-gray-300 pt-3 text-sm font-semibold text-gray-900">
                        <span>Closing balance</span>
                        <span class="whitespace-nowrap">
                            <x-money :amount="$statement['closing']" :currency="$currency" />
                        </span>
                    </div>
                @endif

                <div class="statement-aging mt-10 border-t border-gray-200 pt-6">
                    <h3 class="text-xs font-medium uppercase tracking-widest text-gray-500">Aging of outstanding charges</h3>

                    @if ($agingRow)
                        <table class="mt-3 w-full text-sm">
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($buckets as $key => $label)
                                    <tr>
                                        <td class="py-1.5 text-gray-700">{{ $label }}</td>
                                        <td class="py-1.5 text-right text-gray-900">
                                            <x-money :amount="$agingRow['buckets'][$key]" :currency="$currency" />
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($agingRow['credited'] > 0)
                                    <tr>
                                        <td class="py-1.5 text-gray-700">Credit (not netted into the above)</td>
                                        <td class="py-1.5 text-right text-amber-700">
                                            <x-money :amount="$agingRow['credited']" :currency="$currency" />
                                        </td>
                                    </tr>
                                @endif
                                <tr class="font-semibold text-gray-900">
                                    <td class="py-2">Owed</td>
                                    <td class="py-2 text-right">
                                        <x-money :amount="$agingRow['open']" :currency="$currency" />
                                    </td>
                                </tr>
                                <tr class="text-gray-700">
                                    <td class="py-1.5">Of which overdue</td>
                                    <td class="py-1.5 text-right">
                                        <x-money :amount="$agingRow['overdue']" :currency="$currency" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        @if ($agingRow['oldest_due_on'])
                            <p class="mt-3 text-xs text-gray-500">Oldest outstanding charge due {{ $agingRow['oldest_due_on']->format('j M Y') }}.</p>
                        @endif
                    @else
                        <p class="mt-3 text-sm text-gray-600">No charges are outstanding.</p>
                    @endif
                </div>

                <p class="mt-10 border-t border-gray-200 pt-4 text-xs text-gray-500">
                    Voided receipts and reversed charges are struck through and do not count toward the balance. Late fees and damage added after an agreement was billed appear as their own line, dated when they were assessed.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
