<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Agreement {{ $contract->contract_number }}
            </h2>
            <div class="flex items-center gap-3">
                @if (! in_array($contract->status, ['returned', 'cancelled'], true))
                    <a href="{{ route('rental-agreements.edit', $contract) }}"
                       class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Edit items
                    </a>
                @endif
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                    {{ ucfirst($contract->status) }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            @if ($contract->status === 'cancelled' && $contract->cancellation_reason)
                <div class="p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-900">
                    <span class="font-semibold uppercase tracking-widest text-xs">Cancelled</span>
                    <p class="mt-1">{{ $contract->cancellation_reason }}</p>
                </div>
            @endif

            @if ($contract->customer?->taxExemptionHasExpired() && (float) $contract->tax_amount > 0)
                <div class="p-4 bg-amber-100 border border-amber-200 rounded-md text-sm text-amber-900">
                    This customer's tax exemption certificate expired on
                    {{ $contract->customer->tax_exemption_expires_on->format('Y-m-d') }}, so tax was charged
                    on this agreement. Record a current certificate before their next rental.
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Customer</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $contract->customer?->full_name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Handled by</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $contract->clerk?->name ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Start date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $contract->start_date->format('Y-m-d') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">End date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $contract->end_date->format('Y-m-d') }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Contract total</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900">
                            <x-money :amount="$contract->total_amount" />
                        </dd>
                        <p class="mt-1 text-xs text-gray-500">Rental and sale lines, tax, discount and late fee — before damage.</p>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">How the total is made up</dt>
                        <dd class="mt-1 text-sm text-gray-700 space-y-1">
                            <div class="flex justify-between gap-6">
                                <span>Rental and sale lines</span>
                                <x-money :amount="$contract->subtotal" />
                            </div>
                            @if ((float) $contract->discount_amount > 0)
                                <div class="flex justify-between gap-6">
                                    <span>Discount</span>
                                    <span>-<x-money :amount="$contract->discount_amount" /></span>
                                </div>
                            @endif
                            @if ((float) $contract->tax_amount > 0)
                                <div class="flex justify-between gap-6">
                                    <span>Tax ({{ rtrim(rtrim($contract->tax_rate, '0'), '.') }}%)</span>
                                    <x-money :amount="$contract->tax_amount" />
                                </div>
                            @endif
                            @if ((float) $contract->late_fee > 0)
                                <div class="flex justify-between gap-6">
                                    <span>Late fee</span>
                                    <x-money :amount="$contract->late_fee" />
                                </div>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Source reservation</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if ($contract->reservation)
                                <a href="{{ route('reservations.show', $contract->reservation) }}" class="text-indigo-600 hover:text-indigo-900">
                                    Reservation #{{ $contract->reservation->id }}
                                </a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    @if ($contract->notes)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Notes</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $contract->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Rented items</h3>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                <th class="px-4 py-3">Item</th>
                                <th class="px-4 py-3">Serial #</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3">Rate</th>
                                <th class="px-4 py-3 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($contract->items as $item)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $item->product?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $item->serial_number ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ $item->quantity }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        @if ($item->rate_type === 'sale')
                                            Sale @ <x-money :amount="$item->unit_price" />
                                        @else
                                            {{ $item->duration }} {{ $item->rate_type }}{{ $item->duration > 1 ? 's' : '' }}
                                            @ <x-money :amount="$item->unit_price" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-900">
                                        <x-money :amount="$item->subtotal" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Charge account: bill this agreement's balance to the customer -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Charge account</h3>
                    <span class="text-xs text-gray-400">Rental and sale lines can go on the customer's account instead of the counter</span>
                </div>

                @if (! $contract->customer?->isChargeAccount())
                    <p class="mt-4 text-sm text-gray-500">This customer is a cash account — they pay at the counter.</p>
                @else
                    <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Credit limit</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                @if ($contract->customer->credit_limit !== null)
                                    <x-money :amount="$contract->customer->credit_limit" />
                                @else
                                    No limit set
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">On account</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900"><x-money :amount="$accountBalance" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Available credit</dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900">
                                @if ($accountAvailable !== null)
                                    <x-money :amount="$accountAvailable" />
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </dl>

                    {{-- Charge and reversal refusals both land here. --}}
                    <x-input-error class="mt-4" :messages="$errors->get('account')" />

                    @if ($contract->isOnAccount())
                        <p class="mt-4 text-sm text-gray-700">
                            Charged to the account on {{ $contract->charged_to_account_at?->format('Y-m-d') }}
                            @if ($contract->due_on) — due {{ $contract->due_on->format('Y-m-d') }} @endif
                        </p>

                        @can('reverseCharge', $contract)
                            <form method="POST" action="{{ route('rental-agreements.reverse-charge', $contract) }}"
                                  class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-200 pt-4"
                                  onsubmit="return confirm('Reverse this charge? The agreement goes back to balance due.');">
                                @csrf
                                <div>
                                    <x-input-label for="reverse_reason" value="Reason" />
                                    <x-text-input id="reverse_reason" name="reason" type="text"
                                                  class="mt-1 block w-64" maxlength="255" required
                                                  :value="old('reason')"
                                                  placeholder="Why the charge was wrong" />
                                    <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                                </div>
                                <x-secondary-button class="text-red-600">Reverse charge</x-secondary-button>
                            </form>
                        @endcan
                    @else
                        @if ($contract->charge_reversed_at)
                            <p class="mt-4 text-sm text-gray-700">
                                Charge of {{ $contract->charged_to_account_at?->format('Y-m-d') }} reversed on
                                {{ $contract->charge_reversed_at->format('Y-m-d') }} — {{ $contract->charge_reversed_reason }}
                            </p>
                        @endif

                        @if ($contract->status !== 'cancelled')
                            <form method="POST" action="{{ route('rental-agreements.charge-account', $contract) }}" class="mt-4">
                                @csrf
                                <x-primary-button>Charge to account</x-primary-button>
                            </form>
                        @endif
                    @endif
                @endif
            </div>

            <!-- Money: what the agreement bills, what came in, what is still due -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Payments</h3>
                    <span class="text-xs text-gray-400">Voiding (admin or manager, with a reason) posts a reversing entry; nothing is deleted</span>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Charges to date</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900"><x-money :amount="$charges" /></dd>
                        @if ($damageCharges > 0)
                            <div class="text-xs text-gray-500">includes <x-money :amount="$damageCharges" /> damage</div>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Paid</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900"><x-money :amount="$paid" /></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Balance due</dt>
                        <dd class="mt-1 text-lg font-semibold {{ $balance > 0 ? 'text-red-700' : 'text-green-700' }}">
                            <x-money :amount="$balance" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Deposit held</dt>
                        <dd class="mt-1 text-lg font-semibold text-gray-900"><x-money :amount="$deposit['remaining']" /></dd>
                        @if ($deposit['applied'] > 0 || $deposit['refunded'] > 0)
                            <div class="text-xs text-gray-500">
                                <x-money :amount="$deposit['applied']" /> applied
                                · <x-money :amount="$deposit['refunded']" /> refunded
                            </div>
                        @endif
                    </div>
                </dl>

                @if ($balance < 0)
                    @can('refundCredit', $contract)
                        <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 p-4">
                            <p class="text-sm text-amber-900">
                                This agreement owes the customer <x-money :amount="abs($balance)" />.
                            </p>

                            <form method="POST" action="{{ route('rental-agreements.payments.refund-credit', $contract) }}"
                                  class="mt-3 grid gap-4 sm:grid-cols-3">
                                @csrf
                                <x-method-select name="payment_method" id="credit_method" label="Refund method" />

                                <div>
                                    <x-input-label for="credit_reason" value="Reason" />
                                    <x-text-input id="credit_reason" name="reason" type="text" maxlength="255"
                                                  class="mt-1 block w-full" :value="old('reason')"
                                                  placeholder="Why the credit is being paid out" />
                                    <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                                </div>

                                <div class="flex items-end">
                                    <x-primary-button>Refund credit</x-primary-button>
                                </div>
                            </form>
                            <x-input-error class="mt-2" :messages="$errors->get('credit')" />
                        </div>
                    @endcan
                @endif

                @if (! in_array($contract->status, ['returned', 'cancelled'], true))
                    <!-- Deposit taken at pickup: held against damage and late fees -->
                    <form method="POST" action="{{ route('rental-agreements.payments.deposit', $contract) }}"
                          class="mt-6 grid gap-4 border-t border-gray-200 pt-6 sm:grid-cols-4">
                        @csrf
                        <div>
                            <x-input-label for="deposit_amount" value="Deposit held" />
                            <x-text-input id="deposit_amount" name="deposit_amount" type="number" step="0.01" min="0.01"
                                          class="mt-1 block w-full"
                                          :value="old('deposit_amount', $suggestedDeposit > 0 ? number_format($suggestedDeposit, 2, '.', '') : '')" />
                            <x-input-error class="mt-2" :messages="$errors->get('deposit_amount')" />
                        </div>
                        <x-method-select name="payment_method" id="deposit_method" />
                        <div>
                            <x-input-label for="deposit_reference" value="Check no. / reference" />
                            <x-text-input id="deposit_reference" name="transaction_id" type="text"
                                          class="mt-1 block w-full" :value="old('transaction_id')" />
                        </div>
                        <div class="flex items-end">
                            <x-primary-button>Record deposit</x-primary-button>
                        </div>
                    </form>
                @endif

                @if ($contract->status !== 'cancelled')
                    <form method="POST" action="{{ route('rental-agreements.payments.store', $contract) }}"
                          class="mt-6 grid gap-4 sm:grid-cols-2">
                        @csrf
                        <div>
                            <x-input-label for="amount" value="Amount" />
                            <x-text-input id="amount" name="amount" type="number" step="0.01" min="0.01"
                                          class="mt-1 block w-full" :value="old('amount')" />
                            <x-input-error class="mt-2" :messages="$errors->get('amount')" />
                        </div>

                        <x-method-select name="payment_method" id="payment_method" />

                        <div>
                            <x-input-label for="transaction_id" value="Check no. / card reference" />
                            <x-text-input id="transaction_id" name="transaction_id" type="text"
                                          class="mt-1 block w-full" :value="old('transaction_id')" />
                        </div>

                        <div>
                            <x-input-label for="notes" value="Notes" />
                            <x-text-input id="notes" name="notes" type="text"
                                          class="mt-1 block w-full" :value="old('notes')" />
                        </div>

                        <div class="sm:col-span-2">
                            <x-primary-button>Record payment</x-primary-button>
                            <x-input-error class="mt-2" :messages="$errors->get('payment')" />
                        </div>
                    </form>
                @else
                    <p class="mt-6 text-sm text-gray-500">Cancelled agreements take no payments.</p>
                @endif

                @if ($payments->isEmpty())
                    <p class="mt-6 text-sm text-gray-500">No payments recorded yet.</p>
                @else
                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Receipt</th>
                                    <th class="px-4 py-3">When</th>
                                    <th class="px-4 py-3">Method</th>
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-4 py-3">Taken by</th>
                                    <th class="px-4 py-3 text-right">Amount</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($payments as $payment)
                                    <tr class="{{ $payment->isVoided() ? 'text-gray-400' : '' }}">
                                        <td class="px-4 py-3 font-medium whitespace-nowrap">
                                            {{ $payment->receipt_number ?? $payment->accountPayment?->receipt_number }}
                                            @if ($payment->account_payment_id)
                                                <span class="ml-1 text-xs text-indigo-700">account</span>
                                            @endif
                                            @if ($payment->isReversal())
                                                <span class="ml-1 text-xs text-amber-700">reversal</span>
                                            @elseif ($payment->isVoided())
                                                <span class="ml-1 text-xs text-red-700">voided</span>
                                            @endif
                                            @if ($payment->isDeposit())
                                                <span class="ml-1 text-xs {{ $payment->kind === 'deposit_refund' ? 'text-amber-700' : 'text-blue-700' }}">
                                                    {{ $payment->kindLabel() }}
                                                </span>
                                            @endif
                                            @if ($payment->isVoided() && $payment->void_reason)
                                                <div class="mt-1 text-xs font-normal text-gray-500">{{ $payment->void_reason }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $payment->payment_date?->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3">{{ $payment->methodLabel() }}</td>
                                        <td class="px-4 py-3">{{ $payment->transaction_id ?? '—' }}</td>
                                        <td class="px-4 py-3">{{ $payment->clerk?->name ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right font-medium whitespace-nowrap">
                                            <x-money :amount="$payment->amount" />
                                        </td>
                                        <td class="px-4 py-3">
                                            @can('void', $payment)
                                                @if (! $payment->isReversal() && ! $payment->isVoided() && ! $payment->isDeposit())
                                                    @php($retrying = (string) old('payment_id') === (string) $payment->id)
                                                    <form method="POST"
                                                          action="{{ route('rental-agreements.payments.void', [$contract, $payment]) }}"
                                                          onsubmit="return confirm('Void receipt {{ $payment->receipt_number }}? A reversing entry keeps both lines in the ledger.');"
                                                          class="flex flex-wrap items-center justify-end gap-2">
                                                        @csrf
                                                        @method('PATCH')
                                                        {{-- Tells the page which row was refused, so only it shows the error --}}
                                                        <input type="hidden" name="payment_id" value="{{ $payment->id }}">
                                                        <x-text-input id="reason-{{ $payment->id }}" name="reason" type="text"
                                                                      class="w-40 text-xs" maxlength="255" required
                                                                      placeholder="Reason"
                                                                      :value="$retrying ? old('reason') : ''" />
                                                        <button type="submit"
                                                                class="text-xs font-semibold text-red-600 hover:text-red-800 uppercase tracking-widest">
                                                            Void
                                                        </button>
                                                        @if ($retrying)
                                                            <x-input-error class="basis-full text-right" :messages="$errors->get('reason')" />
                                                        @endif
                                                    </form>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($contract->status !== 'cancelled' && ($contract->status !== 'returned' || $deposit['remaining'] > 0))
                <!-- Return: fees come out of the deposit, the rest goes back -->
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">
                            {{ $contract->status === 'returned' ? 'Settle deposit' : 'Return & settle deposit' }}
                        </h3>
                        <span class="text-xs text-gray-400">Late and damage fees come out of the hold; anything left is refunded</span>
                    </div>

                    <form method="POST" action="{{ route('rental-agreements.payments.settle', $contract) }}" class="mt-4 space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <x-input-label for="returned_on" value="Returned on" />
                                <x-text-input id="returned_on" name="returned_on" type="date" class="mt-1 block w-full"
                                              :value="old('returned_on', $contract->actual_return_date?->toDateString() ?? now()->toDateString())" />
                                <x-input-error class="mt-2" :messages="$errors->get('returned_on')" />
                            </div>
                            <div>
                                <x-input-label for="late_fee" value="Late fee" />
                                <x-text-input id="late_fee" name="late_fee" type="number" step="0.01" min="0"
                                              class="mt-1 block w-full" :value="old('late_fee', $contract->late_fee)" />
                                <x-input-error class="mt-2" :messages="$errors->get('late_fee')" />
                            </div>
                            <x-method-select name="refund_method" id="refund_method" label="Refund method" />
                        </div>

                        @if ($contract->items->isNotEmpty())
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead>
                                        <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <th class="px-4 py-3">Item</th>
                                            <th class="px-4 py-3">Damage fee</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        @foreach ($contract->items as $item)
                                            <tr>
                                                <td class="px-4 py-3 text-gray-900">
                                                    {{ $item->product?->name ?? '—' }}
                                                    @if ($item->serial_number)
                                                        <span class="text-xs text-gray-500">(SN: {{ $item->serial_number }})</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3">
                                                    <x-text-input id="damage-{{ $item->id }}" name="damage[{{ $item->id }}]"
                                                                  type="number" step="0.01" min="0" class="block w-32"
                                                                  :value="old('damage.'.$item->id, $item->damage_fee)" />
                                                    <x-input-error class="mt-1" :messages="$errors->get('damage.'.$item->id)" />
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <x-primary-button>{{ $contract->status === 'returned' ? 'Settle deposit' : 'Return &amp; settle deposit' }}</x-primary-button>
                    </form>
                </div>
            @endif

            @can('cancelAgreement', $contract)
            @if ($contract->status !== 'returned' && ($deposit['remaining'] > 0 || $rentHeld > 0))
                {{-- Cancelling with money held pays it all back; no fees apply --}}
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">
                        {{ $contract->status === 'cancelled' ? 'Refund held money' : 'Cancel & refund' }}
                    </h3>
                    <p class="mt-1 text-xs text-gray-500">
                        @if ($contract->status === 'cancelled')
                            This cancelled agreement still holds money. Refunding it closes the books.
                        @else
                            Ends the agreement without a return: rent and any deposit come back in full and the charges are waived.
                        @endif
                    </p>

                    <form method="POST" action="{{ route('rental-agreements.cancel', $contract) }}"
                          onsubmit="return confirm('{{ $contract->status === 'cancelled' ? 'Refund the money still held on this cancelled agreement?' : 'Cancel this agreement and refund all money held?' }}');"
                          class="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end">
                        @csrf
                        @method('PATCH')

                        <x-method-select name="refund_method" id="cancel_refund_method" label="Refund method" />

                        <div>
                            <x-input-label for="cancel_reason" value="Reason" />
                            <x-text-input id="cancel_reason" name="cancellation_reason" type="text" maxlength="255" required
                                          class="mt-1 block w-full" :value="old('cancellation_reason')"
                                          placeholder="Why this agreement is being cancelled" />
                            <x-input-error class="mt-2" :messages="$errors->get('cancellation_reason')" />
                        </div>

                        <div>
                            <x-secondary-button class="text-red-600">
                                {{ $contract->status === 'cancelled' ? 'Refund money' : 'Cancel & refund' }}
                            </x-secondary-button>
                        </div>
                    </form>
                </div>
            @endcan
            @endif

            @if (! in_array($contract->status, ['returned', 'cancelled'], true) && $deposit['remaining'] <= 0 && $rentHeld <= 0)
                {{-- No deposit to settle: just close the agreement --}}
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Close without deposit</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Releases the units straight away. A held deposit is settled with the return form above.
                    </p>

                    <div class="mt-4 flex flex-wrap items-end gap-6">
                        <form method="POST" action="{{ route('rental-agreements.return', $contract) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            @method('PATCH')
                            <div>
                                <x-input-label for="returned_on" value="Returned on" />
                                <x-text-input id="returned_on" name="returned_on" type="date" class="mt-1 block"
                                              :value="old('returned_on', now()->toDateString())" />
                                <x-input-error :messages="$errors->get('returned_on')" class="mt-2" />
                            </div>
                            <x-primary-button>Mark returned</x-primary-button>
                        </form>

                        @can('cancelAgreement', $contract)
                            <form method="POST" action="{{ route('rental-agreements.cancel', $contract) }}"
                                  onsubmit="return confirm('Cancel this agreement?');" class="flex flex-wrap items-end gap-3">
                                @csrf
                                @method('PATCH')

                                <div class="w-full sm:w-auto">
                                    <x-input-label for="close_cancel_reason" value="Reason" />
                                    <x-text-input id="close_cancel_reason" name="cancellation_reason" type="text" maxlength="255" required
                                                  class="mt-1 block w-full sm:w-56" :value="old('cancellation_reason')"
                                                  placeholder="Why this agreement is being cancelled" />
                                    <x-input-error class="mt-2" :messages="$errors->get('cancellation_reason')" />
                                </div>

                                <x-secondary-button class="text-red-600">Cancel agreement</x-secondary-button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endif

            @if ($contract->status === 'returned' && $deposit['refunded'] > $deposit['applied'])
                @can('topUpDeposit', $contract)
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Deposit top-up</h3>
                            <span class="text-xs text-gray-400">Puts back deposit that was refunded, against the fees the return kept</span>
                        </div>

                        <x-input-error class="mt-4" :messages="$errors->get('top_up')" />

                        <form method="POST" action="{{ route('rental-agreements.payments.top-up', $contract) }}"
                              class="mt-4 grid gap-4 sm:grid-cols-4">
                            @csrf
                            <div>
                                <x-input-label for="top_up_amount" value="Amount" />
                                <x-text-input id="top_up_amount" name="amount" type="number" step="0.01" min="0.01"
                                              class="mt-1 block w-full" :value="old('amount')" />
                                <p class="mt-1 text-xs text-gray-500">
                                    At most <x-money :amount="$deposit['refunded'] - $deposit['applied']" /> — refunded and not yet kept.
                                </p>
                                <x-input-error class="mt-2" :messages="$errors->get('amount')" />
                            </div>

                            <x-method-select name="payment_method" id="top_up_method" />

                            <div>
                                <x-input-label for="top_up_reason" value="Reason" />
                                <x-text-input id="top_up_reason" name="reason" type="text" maxlength="255"
                                              class="mt-1 block w-full" :value="old('reason')"
                                              placeholder="Why the deposit is coming back" />
                                <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                            </div>

                            <div class="flex items-end">
                                <x-primary-button>Take top-up</x-primary-button>
                            </div>
                        </form>
                    </div>
                @endcan
            @endif

            @if ($contract->status === 'returned' && $deposit['remaining'] <= 0)
                @can('adjustSettlement', $contract)
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Adjust settled return</h3>
                            <span class="text-xs text-gray-400">Amends the fees and the deposit kept; receipts already issued stay in the ledger</span>
                        </div>

                        <x-input-error class="mt-4" :messages="$errors->get('adjust')" />

                        <form method="POST" action="{{ route('rental-agreements.payments.adjust', $contract) }}" class="mt-4 space-y-4">
                            @csrf
                            @method('PATCH')

                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <x-input-label for="adjust_late_fee" value="Late fee" />
                                    <x-text-input id="adjust_late_fee" name="late_fee" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" :value="old('late_fee', $contract->late_fee)" />
                                    <x-input-error class="mt-2" :messages="$errors->get('late_fee')" />
                                </div>

                                <div>
                                    <x-input-label for="adjust_applied" value="Deposit applied" />
                                    <x-text-input id="adjust_applied" name="deposit_applied" type="number" step="0.01" min="0"
                                                  class="mt-1 block w-full" :value="old('deposit_applied', $contract->deposit_applied)" />
                                    <p class="mt-1 text-xs text-gray-500">
                                        <x-money :amount="$deposit['received'] - $deposit['refunded']" /> held and not yet refunded
                                    </p>
                                    <x-input-error class="mt-2" :messages="$errors->get('deposit_applied')" />
                                </div>

                                <x-method-select name="refund_method" id="adjust_refund_method" label="Refund method if less is kept" />
                            </div>

                            @if ($contract->items->isNotEmpty())
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead>
                                            <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                <th class="px-4 py-3">Item</th>
                                                <th class="px-4 py-3">Damage fee</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            @foreach ($contract->items as $item)
                                                <tr>
                                                    <td class="px-4 py-3 text-gray-900">
                                                        {{ $item->product?->name ?? '—' }}
                                                        @if ($item->serial_number)
                                                            <span class="text-xs text-gray-500">(SN: {{ $item->serial_number }})</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <x-text-input id="adjust-damage-{{ $item->id }}" name="damage[{{ $item->id }}]"
                                                                      type="number" step="0.01" min="0" class="block w-32"
                                                                      :value="old('damage.'.$item->id, $item->damage_fee)" />
                                                        <x-input-error class="mt-1" :messages="$errors->get('damage.'.$item->id)" />
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            <div>
                                <x-input-label for="adjust_reason" value="Reason" />
                                <x-text-input id="adjust_reason" name="reason" type="text" maxlength="255"
                                              class="mt-1 block w-full" :value="old('reason')"
                                              placeholder="Why the return figures were wrong" />
                                <x-input-error class="mt-2" :messages="$errors->get('reason')" />
                            </div>

                            <x-primary-button>Save adjustment</x-primary-button>
                        </form>
                    </div>
                @endcan
            @endif

            @if ($contract->terms_conditions)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Agreement terms</h3>
                    <pre class="mt-4 whitespace-pre-wrap font-sans text-sm text-gray-800 leading-relaxed">{{ $contract->terms_conditions }}</pre>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <a href="{{ route('rental-agreements.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to agreements
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
