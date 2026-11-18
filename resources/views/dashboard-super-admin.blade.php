<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Dashboard') }}
            </h2>
            <a href="{{ route('tenants.index') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Manage tenants
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <p class="text-sm text-gray-500">
                Platform-wide summary. Record details live inside each tenant — log in as a tenant admin from the
                <a href="{{ route('tenants.index') }}" class="text-indigo-600 hover:text-indigo-900">Tenants page</a>
                to manage them.
            </p>

            <!-- Totals -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Tenants</div>
                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $tenants_active }}<span class="text-base font-normal text-gray-400"> / {{ $tenants_total }}</span></div>
                    <div class="text-xs text-gray-400">active / total</div>
                </div>
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Products</div>
                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $products_total }}</div>
                    <div class="text-xs text-gray-400">active across tenants</div>
                </div>
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Customers</div>
                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $customers_total }}</div>
                    <div class="text-xs text-gray-400">across tenants</div>
                </div>
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Reservations</div>
                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $reservations_by_status->sum() }}</div>
                    <div class="text-xs text-gray-400">across tenants</div>
                </div>
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wider">Agreements</div>
                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $agreements_by_status->sum() }}</div>
                    <div class="text-xs text-gray-400">across tenants</div>
                </div>
            </div>

            <!-- Status breakdowns -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider mb-3">Reservations by status</h3>
                    @if ($reservations_by_status->isEmpty())
                        <p class="text-sm text-gray-400">No reservations yet.</p>
                    @else
                        <dl class="divide-y divide-gray-100 text-sm">
                            @foreach ($reservations_by_status as $status => $total)
                                <div class="flex items-center justify-between py-2">
                                    <dt class="text-gray-600 capitalize">{{ str_replace('_', ' ', $status) }}</dt>
                                    <dd class="font-semibold text-gray-900">{{ $total }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
                <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider mb-3">Agreements by status</h3>
                    @if ($agreements_by_status->isEmpty())
                        <p class="text-sm text-gray-400">No agreements yet.</p>
                    @else
                        <dl class="divide-y divide-gray-100 text-sm">
                            @foreach ($agreements_by_status as $status => $total)
                                <div class="flex items-center justify-between py-2">
                                    <dt class="text-gray-600 capitalize">{{ str_replace('_', ' ', $status) }}</dt>
                                    <dd class="font-semibold text-gray-900">{{ $total }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>

            <!-- Newest tenants -->
            <div class="bg-white overflow-hidden shadow sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider">Newest tenants</h3>
                    <a href="{{ route('tenants.index') }}" class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">View all</a>
                </div>
                @if ($recent_tenants->isEmpty())
                    <p class="text-sm text-gray-400">No tenants yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3 text-right">Products</th>
                                    <th class="px-4 py-3 text-right">Customers</th>
                                    <th class="px-4 py-3 text-right">Reservations</th>
                                    <th class="px-4 py-3 text-right">Agreements</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($recent_tenants as $tenant)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ $tenant->name }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->products_count }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->customers_count }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->reservations_count }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->rental_contracts_count }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
