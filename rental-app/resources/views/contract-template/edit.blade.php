<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $isGlobal ? __('Edit global boilerplate') : __('Customize your contract') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-600">
                @if ($isGlobal)
                    You are editing the <strong>shared boilerplate</strong>. Changes apply to all tenants that have not saved their own version.
                @else
                    Your version is saved <strong>privately to your tenant</strong> and starts pre-filled from the boilerplate.
                    Use placeholders like <code class="font-mono text-xs bg-gray-100 px-1 rounded">@{{business_name}}</code> — they auto-fill with your business details on preview and print.
                @endif
                <div class="mt-2 text-xs">
                    Available placeholders:
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{business_name}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{business_address}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{business_phone}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{business_email}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{company_state}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{customer_name}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{customer_address}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{rented_items}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{contract_number}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{contract_date}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{start_date}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{end_date}}</code>
                    <code class="font-mono bg-gray-100 px-1 rounded">@{{total_amount}}</code>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form method="POST" action="{{ route('contract-template.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="title" value="Title" />
                        <x-text-input id="title" name="title" type="text" class="mt-1 block w-full"
                                      value="{{ old('title', $template->title) }}" required />
                        <x-input-error :messages="$errors->get('title')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="body" value="Contract body" />
                        <textarea id="body" name="body" rows="22"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm">{{ old('body', $template->body) }}</textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="footer" value="Footer (fees, deposit policy)" />
                        <textarea id="footer" name="footer" rows="3"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('footer', $template->footer) }}</textarea>
                        <x-input-error :messages="$errors->get('footer')" class="mt-2" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ $isGlobal ? 'Save boilerplate' : 'Save my version' }}</x-primary-button>
                        <a href="{{ route('contract-template.show') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
