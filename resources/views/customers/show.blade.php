<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $customer->first_name }} {{ $customer->last_name }}
            </h2>
            <div class="flex items-center gap-3">
                @if ($customer->isChargeAccount())
                    <a href="{{ route('customers.statement', $customer) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Statement
                    </a>
                @endif
                <a href="{{ route('customers.edit', $customer) }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Edit
                </a>
                @if ($deleteBlockedReason)
                    <p class="max-w-xs text-xs text-gray-500">{{ $deleteBlockedReason }}</p>
                @else
                    <form method="POST" action="{{ route('customers.destroy', $customer) }}"
                          onsubmit="return confirm('Delete this customer?');">
                        @csrf
                        @method('DELETE')
                        <x-secondary-button type="submit" class="text-red-600">Delete</x-secondary-button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('error'))
                <div class="p-4 bg-red-100 border border-red-200 rounded-md text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Customer code</dt>
                        <dd class="mt-1 font-mono text-sm text-gray-900">{{ $customer->customer_code }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Phone</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->phone }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Email</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->email ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">ID number</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->id_number ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Date of birth</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->date_of_birth?->format('Y-m-d') ?? '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Status</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->is_active ? 'Active' : 'Inactive' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Account</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ $customer->isChargeAccount() ? 'Charge account' : 'Cash — pays at the counter' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Credit limit</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if ($customer->credit_limit !== null)
                                <x-money :amount="$customer->credit_limit" />
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Tax exemption</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if ($customer->hasTaxExemptionCertificate())
                                <span class="font-mono text-xs">{{ $customer->tax_exemption_number }}</span>
                                · expires {{ $customer->tax_exemption_expires_on?->format('Y-m-d') ?? 'never' }}
                                @if ($customer->tax_exemption_reason)
                                    · {{ $customer->tax_exemption_reason }}
                                @endif
                                @if ($customer->taxExemptionHasExpired())
                                    <span class="ml-1 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-700">Expired</span>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    @if ($customer->isChargeAccount())
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">On account</dt>
                            <dd class="mt-1 text-sm text-gray-900"><x-money :amount="$accountBalance" /></dd>
                        </div>

                        @if ($accountCredit > 0)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Credit</dt>
                                <dd class="mt-1 text-sm font-semibold text-amber-700">
                                    <x-money :amount="$accountCredit" /> owed to the customer
                                </dd>
                            </div>
                        @endif

                        <div>
                            <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Available credit</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                @if ($accountAvailable !== null)
                                    <x-money :amount="$accountAvailable" />
                                @else
                                    No limit set
                                @endif
                            </dd>
                        </div>
                    @endif

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Address</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->address ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">City</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->city ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">State</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->state ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Postal code</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->postal_code ?: '—' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Country</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->country ?: '—' }}</dd>
                    </div>

                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-widest text-gray-500">Notes</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $customer->notes ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            @if ($customer->isChargeAccount())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Account payment</h3>
                        <span class="text-xs text-gray-400">One receipt, applied to the oldest open charges first</span>
                    </div>

                    @if ($accountBalance > 0)
                        <form method="POST" action="{{ route('customers.account-payment', $customer) }}"
                              class="mt-4 grid gap-4 sm:grid-cols-4">
                            @csrf
                            <div>
                                <x-input-label for="account_amount" value="Amount" />
                                <x-text-input id="account_amount" name="amount" type="number" step="0.01" min="0.01"
                                              class="mt-1 block w-full" :value="old('amount')" />
                                <x-input-error class="mt-2" :messages="$errors->get('amount')" />
                            </div>

                            <x-method-select name="payment_method" id="account_method" />

                            <div>
                                <x-input-label for="account_reference" value="Check no. / reference" />
                                <x-text-input id="account_reference" name="transaction_id" type="text"
                                              class="mt-1 block w-full" :value="old('transaction_id')" />
                            </div>

                            <div class="flex items-end">
                                <x-primary-button>Pay account</x-primary-button>
                            </div>
                        </form>
                        <x-input-error class="mt-2" :messages="$errors->get('account')" />
                    @else
                        <p class="mt-4 text-sm text-gray-500">
                            Nothing outstanding — <x-money :amount="$accountBalance" /> on the account.
                        </p>
                        <x-input-error class="mt-2" :messages="$errors->get('account')" />
                    @endif
                </div>
            @endif

            @if ($accountPayments->isNotEmpty())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Account receipts</h3>
                        <span class="text-xs text-gray-400">Each receipt shows the charges it paid</span>
                    </div>

                    <div class="mt-4 space-y-4">
                        @foreach ($accountPayments as $receipt)
                            <div class="rounded-md border border-gray-200 p-4 {{ $receipt->isVoided() ? 'text-gray-500' : '' }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <div>
                                        <span class="font-medium text-gray-900">{{ $receipt->receipt_number }}</span>
                                        @if ($receipt->isVoided())
                                            <span class="ml-1 text-xs text-red-700">voided</span>
                                        @endif
                                        <div class="text-xs text-gray-500">
                                            {{ $receipt->payment_date?->format('Y-m-d H:i') }}
                                            · {{ $receipt->methodLabel() }}
                                            · {{ $receipt->clerk?->name ?? '—' }}
                                        </div>
                                        @if ($receipt->isVoided() && $receipt->void_reason)
                                            <div class="text-xs text-gray-500">{{ $receipt->void_reason }}</div>
                                        @endif
                                    </div>
                                    <div class="font-semibold text-gray-900">
                                        <x-money :amount="$receipt->amount" />
                                    </div>
                                </div>

                                <table class="mt-3 min-w-full text-sm">
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($receipt->allocations as $allocation)
                                            <tr>
                                                <td class="py-1">
                                                    @if ($allocation->rentalContract)
                                                        <a href="{{ route('rental-agreements.show', $allocation->rentalContract) }}"
                                                           class="text-indigo-600 hover:text-indigo-900">
                                                            {{ $allocation->rentalContract->contract_number }}
                                                        </a>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="py-1 text-right text-gray-700">
                                                    <x-money :amount="$allocation->amount" />
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                @can('void', $receipt)
                                    @if (! $receipt->isVoided())
                                        @php($retrying = (string) old('payment_id') === (string) $receipt->id)
                                        <form method="POST"
                                              action="{{ route('customers.account-payments.void', [$customer, $receipt]) }}"
                                              onsubmit="return confirm('Void receipt {{ $receipt->receipt_number }}? Every charge it paid goes back to unpaid.');"
                                              class="mt-3 flex flex-wrap items-center gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="payment_id" value="{{ $receipt->id }}">
                                            <x-text-input id="reason-{{ $receipt->id }}" name="reason" type="text"
                                                          class="w-56 text-xs" maxlength="255" required
                                                          placeholder="Reason"
                                                          :value="$retrying ? old('reason') : ''" />
                                            <button type="submit"
                                                    class="text-xs font-semibold text-red-600 hover:text-red-800 uppercase tracking-widest">
                                                Void
                                            </button>
                                            @if ($retrying)
                                                <x-input-error class="basis-full" :messages="$errors->get('reason')" />
                                            @endif
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                    &larr; Back to customers
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
