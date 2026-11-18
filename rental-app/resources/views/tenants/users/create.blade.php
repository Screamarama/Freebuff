<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Add a login') }}
            </h2>
            <a href="{{ route('tenants.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                Back to tenants
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <p class="text-sm text-gray-500">
                    Creating a login for <span class="font-medium text-gray-800">{{ $tenant->name }}</span>.
                    They can sign in as soon as you save, and will only see this tenant's records.
                </p>

                <form method="POST" action="{{ route('tenants.users.store', $tenant) }}" class="mt-6 space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                          value="{{ old('name') }}" required autofocus autocomplete="off" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full"
                                          value="{{ old('email') }}" required autocomplete="off" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="role" value="Role" />
                            <select id="role" name="role" required
                                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                @foreach (['admin' => 'Admin — all tenant records and settings', 'manager' => 'Manager — records, no branding', 'clerk' => 'Clerk — front desk'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('role', 'clerk') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('role')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password" value="Password" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full"
                                          required autocomplete="new-password" />
                            <p class="mt-1 text-xs text-gray-500">At least 8 characters. Share it with them directly.</p>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>Create login</x-primary-button>
                        <a href="{{ route('tenants.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
