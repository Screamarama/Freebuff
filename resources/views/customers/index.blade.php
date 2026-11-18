<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Customers') }}
            </h2>
            <a href="{{ route('customers.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                New customer
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
                <form method="GET" action="{{ route('customers.index') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <x-input-label for="search" value="Search" />
                        <x-text-input id="search" name="search" type="search" class="mt-1 block w-full"
                                      placeholder="Name, code, phone or email" value="{{ request('search') }}" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Filter</x-primary-button>
                        @if (request('search'))
                            <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                                Reset
                            </a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($customers->isEmpty())
                    <p class="text-sm text-gray-500">
                        No customers found. <a href="{{ route('customers.create') }}" class="text-indigo-600 hover:text-indigo-900">Add your first customer</a>.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Code</th>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Phone</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Tax exemption</th>
                                    <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($customers as $customer)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $customer->customer_code }}</td>
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <a href="{{ route('customers.show', $customer) }}" class="hover:text-indigo-600">
                                                {{ $customer->first_name }} {{ $customer->last_name }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $customer->phone }}</td>
                                        <td class="px-4 py-3 text-gray-700">{{ $customer->email ?? '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if ($customer->hasTaxExemptionCertificate())
                                                <span class="text-gray-700">{{ $customer->tax_exemption_expires_on?->format('Y-m-d') ?? 'No expiry' }}</span>
                                                @if ($customer->taxExemptionHasExpired())
                                                    <span class="ml-2 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-700">Expired</span>
                                                @endif
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ route('customers.edit', $customer) }}"
                                               class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">
                                                Edit
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $customers->withQueryString()->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
