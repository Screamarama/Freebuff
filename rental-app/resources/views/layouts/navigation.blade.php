@if (session('impersonator_id'))
    <div class="bg-indigo-600 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex flex-wrap items-center justify-between gap-4 text-sm">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-white text-indigo-700 uppercase tracking-widest">Impersonating</span>
                <span class="font-semibold">{{ Auth::user()->name }}</span>
                <span class="opacity-75">({{ Auth::user()->email }})</span>
            </div>
            <form method="POST" action="{{ route('stop-impersonating') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center px-3 py-1 bg-white border border-white rounded-md font-semibold text-xs text-indigo-900 uppercase tracking-widest hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-indigo-600 transition ease-in-out duration-150">
                    Back to super admin
                </button>
            </form>
        </div>
    </div>
@endif

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100 themed-nav">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex themed-nav-links">
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    @if (in_array(Auth::user()->role, ['admin', 'manager', 'clerk']))
                        <x-nav-link :href="route('products.index')" :active="request()->routeIs('products.*')">
                            {{ __('Products') }}
                        </x-nav-link>
                        <x-nav-link :href="route('customers.index')" :active="request()->routeIs('customers.*')">
                            {{ __('Customers') }}
                        </x-nav-link>
                        <x-nav-link :href="route('reservations.index')" :active="request()->routeIs('reservations.*')">
                            {{ __('Reservations') }}
                        </x-nav-link>
                        <x-nav-link :href="route('rental-agreements.index')" :active="request()->routeIs('rental-agreements.*')">
                            {{ __('Agreements') }}
                        </x-nav-link>
                        <x-nav-link :href="route('receivables.index')" :active="request()->routeIs('receivables.*')">
                            {{ __('Receivables') }}
                        </x-nav-link>

                        <x-nav-link :href="route('sales-tax.index')" :active="request()->routeIs('sales-tax.*')">
                            {{ __('Sales tax') }}
                        </x-nav-link>
                    @endif
                    @if (in_array(Auth::user()->role, ['super_admin', 'admin', 'manager']))
                        <x-nav-link :href="route('contract-template.show')" :active="request()->routeIs('contract-template.*')">
                            {{ __('Contract') }}
                        </x-nav-link>
                    @endif
                    @if (in_array(Auth::user()->role, ['admin', 'manager']))
                        <x-nav-link :href="route('purchase-orders.index')" :active="request()->routeIs('purchase-orders.*')">
                            {{ __('Purchasing') }}
                        </x-nav-link>
                        <x-nav-link :href="route('theme.edit')" :active="request()->routeIs('theme.*')">
                            {{ __('Branding') }}
                        </x-nav-link>
                    @endif
                    @if (Auth::user()->role === 'super_admin')
                        <x-nav-link :href="route('tenants.index')" :active="request()->routeIs('tenants.*')">
                            {{ __('Tenants') }}
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150 themed-nav-trigger">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @if (in_array(Auth::user()->role, ['admin', 'manager']))
                            <x-dropdown-link :href="route('business.edit')">
                                {{ __('Business profile') }}
                            </x-dropdown-link>
                        @endif

                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out themed-nav-trigger">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            @if (in_array(Auth::user()->role, ['admin', 'manager', 'clerk']))
                <x-responsive-nav-link :href="route('products.index')" :active="request()->routeIs('products.*')">
                    {{ __('Products') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('customers.index')" :active="request()->routeIs('customers.*')">
                    {{ __('Customers') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('reservations.index')" :active="request()->routeIs('reservations.*')">
                    {{ __('Reservations') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('rental-agreements.index')" :active="request()->routeIs('rental-agreements.*')">
                    {{ __('Agreements') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('receivables.index')" :active="request()->routeIs('receivables.*')">
                    {{ __('Receivables') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('sales-tax.index')" :active="request()->routeIs('sales-tax.*')">
                    {{ __('Sales tax') }}
                </x-responsive-nav-link>
            @endif
            @if (in_array(Auth::user()->role, ['super_admin', 'admin', 'manager']))
                <x-responsive-nav-link :href="route('contract-template.show')" :active="request()->routeIs('contract-template.*')">
                    {{ __('Contract') }}
                </x-responsive-nav-link>
            @endif
            @if (in_array(Auth::user()->role, ['admin', 'manager']))
                <x-responsive-nav-link :href="route('purchase-orders.index')" :active="request()->routeIs('purchase-orders.*')">
                    {{ __('Purchasing') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('theme.edit')" :active="request()->routeIs('theme.*')">
                    {{ __('Branding') }}
                </x-responsive-nav-link>
            @endif
            @if (Auth::user()->role === 'super_admin')
                <x-responsive-nav-link :href="route('tenants.index')" :active="request()->routeIs('tenants.*')">
                    {{ __('Tenants') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                @if (in_array(Auth::user()->role, ['admin', 'manager']))
                    <x-responsive-nav-link :href="route('business.edit')">
                        {{ __('Business profile') }}
                    </x-responsive-nav-link>
                @endif

                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>