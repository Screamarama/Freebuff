<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Tenants') }}
            </h2>
            <div class="flex items-center gap-3">
                <a href="{{ route('tenants.audit-logs') }}"
                   class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Audit log
                </a>
                <a href="{{ route('tenants.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    New tenant
                </a>
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

            @if (session('error'))
                <div class="p-4 bg-red-100 border border-red-200 rounded-md text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-100 border border-red-200 rounded-md text-sm text-red-800">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="mb-4 text-sm text-gray-500">
                    Summary across tenants — log in as a tenant admin to manage their products, customers, reservations, and agreements.
                    Click a tenant's user count to see its logins. Suspended tenants cannot sign in, so adding logins is
                    disabled until they are reactivated.
                </p>
                @if ($tenants->isEmpty())
                    <p class="text-sm text-gray-500">
                        No tenants yet. <a href="{{ route('tenants.create') }}" class="text-indigo-600 hover:text-indigo-900">Create your first tenant</a>.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Contact</th>
                                    <th class="px-4 py-3">Currency</th>
                                    <th class="px-4 py-3 text-right">Products</th>
                                    <th class="px-4 py-3 text-right">Customers</th>
                                    <th class="px-4 py-3 text-right">Reservations</th>
                                    <th class="px-4 py-3 text-right">Agreements</th>
                                    <th class="px-4 py-3 text-right">Users</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            @foreach ($tenants as $tenant)
                                <tbody class="divide-y divide-gray-200" x-data="{ open: false }">
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ $tenant->name }}</td>
                                        <td class="px-4 py-3 text-gray-700">
                                            @if ($tenant->email)
                                                <div>{{ $tenant->email }}</div>
                                            @endif
                                            @if ($tenant->phone)
                                                <div class="text-gray-500">{{ $tenant->phone }}</div>
                                            @endif
                                            @if (! $tenant->email && ! $tenant->phone)
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ $tenant->currency }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->products_count }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->customers_count }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->reservations_count }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700">{{ $tenant->rental_contracts_count }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <button type="button"
                                                    x-on:click="open = ! open"
                                                    :aria-expanded="open.toString()"
                                                    class="text-gray-700 underline decoration-dotted underline-offset-2 hover:text-indigo-600"
                                                    title="Show this tenant's logins">
                                                {{ $tenant->users->count() }}
                                                <span class="sr-only">{{ $tenant->users->count() === 1 ? 'login' : 'logins' }} — show list</span>
                                            </button>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if ($tenant->is_active)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Active</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            <a href="{{ route('tenants.edit', $tenant) }}"
                                               class="text-xs font-semibold uppercase tracking-widest text-gray-700 hover:text-gray-900 me-3">
                                                Edit
                                            </a>

                                            @if ($tenant->is_active)
                                                <a href="{{ route('tenants.users.create', $tenant) }}"
                                                   class="text-xs font-semibold uppercase tracking-widest text-gray-700 hover:text-gray-900 me-3">
                                                    Add user
                                                </a>
                                            @else
                                                <span class="text-xs font-semibold uppercase tracking-widest text-gray-400 me-3 cursor-not-allowed"
                                                      title="Activate this tenant to add logins — its staff cannot sign in while it is suspended.">
                                                    Add user
                                                </span>
                                            @endif

                                            @if ($tenant->is_active)
                                                <form method="POST" action="{{ route('tenants.impersonate', $tenant) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">
                                                        Log in as admin
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-xs text-gray-400 uppercase tracking-widest">—</span>
                                            @endif

                                            <form method="POST" action="{{ route('tenants.toggle', $tenant) }}" class="inline ms-3">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="text-xs font-semibold uppercase tracking-widest {{ $tenant->is_active ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900' }}">
                                                    {{ $tenant->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        </td>
                                    </tr>

                                    <tr x-show="open" style="display: none" class="bg-gray-50">
                                        <td colspan="10" class="px-4 py-4">
                                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-widest text-gray-500">
                                                    Logins for {{ $tenant->name }}
                                                </h3>
                                                <span class="text-xs text-gray-400">Deactivated accounts cannot sign in</span>
                                            </div>

                                            @if ($tenant->users->isEmpty())
                                                <p class="mt-3 text-sm text-gray-500">
                                                    No logins yet.
                                                    @if ($tenant->is_active)
                                                        <a href="{{ route('tenants.users.create', $tenant) }}" class="text-indigo-600 hover:text-indigo-900">Add one</a>.
                                                    @else
                                                        New logins are blocked until this tenant is reactivated.
                                                    @endif
                                                </p>
                                            @else
                                                <table class="mt-3 min-w-full text-sm">
                                                    <thead>
                                                        <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                            <th class="py-2 pe-4">Name</th>
                                                            <th class="py-2 pe-4">Email</th>
                                                            <th class="py-2 pe-4">Role</th>
                                                            <th class="py-2 pe-4">Status</th>
                                                            <th class="py-2 pe-4">Set password</th>
                                                            <th class="py-2 text-right">Account</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200">
                                                        @foreach ($tenant->users as $user)
                                                            <tr>
                                                                <td class="py-2 pe-4 text-gray-900">{{ $user->name }}</td>
                                                                <td class="py-2 pe-4 text-gray-700">{{ $user->email }}</td>
                                                                <td class="py-2 pe-4 text-gray-700 whitespace-nowrap">
                                                                    {{ ucfirst(str_replace('_', ' ', $user->role)) }}
                                                                </td>
                                                                <td class="py-2 pe-4 whitespace-nowrap">
                                                                    @if ($user->is_active)
                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Active</span>
                                                                    @else
                                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Inactive</span>
                                                                    @endif
                                                                </td>
                                                                <td class="py-2 pe-4">
                                                                    <form method="POST"
                                                                          action="{{ route('tenants.users.password', [$tenant, $user]) }}"
                                                                          class="flex items-center gap-2">
                                                                        @csrf
                                                                        @method('PATCH')
                                                                        <input type="password" name="password" required minlength="8"
                                                                               autocomplete="new-password" placeholder="New password"
                                                                               aria-label="New password for {{ $user->email }}"
                                                                               class="w-40 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                                        <button type="submit"
                                                                                class="text-xs font-semibold uppercase tracking-widest text-indigo-600 hover:text-indigo-900">
                                                                            Set
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                                <td class="py-2 text-right whitespace-nowrap">
                                                                    <form method="POST"
                                                                          action="{{ route('tenants.users.toggle', [$tenant, $user]) }}"
                                                                          class="inline">
                                                                        @csrf
                                                                        @method('PATCH')
                                                                        <button type="submit"
                                                                                class="text-xs font-semibold uppercase tracking-widest {{ $user->is_active ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900' }}">
                                                                            {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            @endforeach
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $tenants->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
