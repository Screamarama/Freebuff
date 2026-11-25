<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Audit Log') }}
            </h2>
            <a href="{{ route('tenants.index') }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                Back to tenants
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Filters: tenant and dates narrow both tables, action only the activity table -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form method="GET" action="{{ route('tenants.audit-logs') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <x-input-label for="tenant" value="Tenant" />
                        <select id="tenant" name="tenant"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All tenants</option>
                            @foreach ($tenants as $tenantOption)
                                <option value="{{ $tenantOption->id }}" @selected((string) $tenantId === (string) $tenantOption->id)>
                                    {{ $tenantOption->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="action" value="Action type" />
                        <select id="action" name="action"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">All actions</option>
                            @foreach ($actions as $value => $label)
                                <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="from" value="From" />
                        <x-text-input id="from" name="from" type="date" class="mt-1 block w-full"
                                      :value="$from?->format('Y-m-d')" />
                    </div>

                    <div>
                        <x-input-label for="to" value="To" />
                        <x-text-input id="to" name="to" type="date" class="mt-1 block w-full"
                                      :value="$to?->format('Y-m-d')" />
                    </div>

                    <div class="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-4">
                        <x-primary-button>Filter</x-primary-button>
                        <!-- Same query string as the form, but to the export route -->
                        <button type="submit" formaction="{{ route('tenants.audit-logs.export') }}"
                                class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Download CSV
                        </button>
                        @if ($filtered)
                            <a href="{{ route('tenants.audit-logs') }}" class="text-sm text-gray-600 hover:text-gray-900">Reset</a>
                        @endif
                        <span class="text-xs text-gray-400">Tenant and dates apply to both tables; action type narrows the activity below. The CSV holds every matching row, not just this page.</span>
                    </div>
                </form>
            </div>

            <!-- Impersonation history -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Impersonation</h3>
                    @if ($action)
                        <span class="text-xs text-amber-700">Action type filter applies to the activity table only</span>
                    @endif
                </div>

                @if ($logs->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">
                        {{ $filtered ? 'No impersonation activity matches these filters.' : 'No impersonation activity recorded yet.' }}
                    </p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">Super admin (who)</th>
                                    <th class="px-4 py-3">Impersonated (whom)</th>
                                    <th class="px-4 py-3">Tenant</th>
                                    <th class="px-4 py-3">Started at</th>
                                    <th class="px-4 py-3">Stopped at</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($logs as $log)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900">{{ $log->impersonator?->name ?? '—' }}</div>
                                            <div class="text-gray-500 text-xs">{{ $log->impersonator?->email }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900">{{ $log->impersonated?->name ?? '—' }}</div>
                                            <div class="text-gray-500 text-xs">{{ $log->impersonated?->email }}</div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ $log->business?->name ?? '—' }}</td>
                                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $log->started_at?->format('Y-m-d H:i:s') }}</td>
                                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $log->stopped_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if ($log->isActive())
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">Active</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Ended</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>

            <!-- Access and account activity: sign-ins, password changes, suspensions -->
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold uppercase tracking-widest text-gray-500">Access &amp; account activity</h3>
                    <span class="text-xs text-gray-400">Sign-ins, password changes and tenant access</span>
                </div>

                @if ($accessLogs->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">
                        {{ $filtered ? 'No account activity matches these filters.' : 'No sign-ins or access changes recorded yet.' }}
                    </p>
                @else
                    @php
                        $tones = [
                            'danger' => 'bg-red-100 text-red-800',
                            'success' => 'bg-green-100 text-green-800',
                            'neutral' => 'bg-gray-100 text-gray-600',
                        ];
                    @endphp
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-3">When</th>
                                    <th class="px-4 py-3">Changed by</th>
                                    <th class="px-4 py-3">Tenant</th>
                                    <th class="px-4 py-3">Change</th>
                                    <th class="px-4 py-3">From IP</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($accessLogs as $log)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-700 whitespace-nowrap">
                                            {{ $log->created_at?->format('Y-m-d H:i:s') ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-900">{{ $log->actorName() ?? '—' }}</div>
                                            @if ($log->actorEmail())
                                                <div class="text-gray-500 text-xs">{{ $log->actorEmail() }}</div>
                                            @endif
                                            @if ($log->user === null)
                                                <div class="text-gray-400 text-xs">account deleted</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">
                                            {{ $log->business?->name ?? ($log->details['name'] ?? '—') }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap {{ $tones[$log->actionTone()] }}">
                                                {{ $log->actionLabel() }}
                                            </span>
                                            @if ($log->changeSummary())
                                                <div class="mt-1 text-gray-500 text-xs">{{ $log->changeSummary() }}</div>
                                            @endif
                                            @if ($log->details['reason'] ?? null)
                                                <div class="mt-1 text-gray-500 text-xs">Reason: {{ $log->details['reason'] }}</div>
                                            @endif
                                            @if ($log->details['purchase_order'] ?? null)
                                                <div class="mt-1 text-gray-500 text-xs">
                                                    {{ $log->details['purchase_order'] }}
                                                    @if (is_array($log->details['units'] ?? null))
                                                        · {{ count($log->details['units']) }} {{ \Illuminate\Support\Str::plural('unit', count($log->details['units'])) }}
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($log->subjectLabel())
                                                <div class="mt-1 text-gray-500 text-xs">{{ $log->subjectLabel() }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $log->ip_address ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $accessLogs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
