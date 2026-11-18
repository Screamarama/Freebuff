<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Branding') }}
            </h2>
            <form method="POST" action="{{ route('theme.reset') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    Reset to defaults
                </button>
            </form>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('success'))
                <div class="p-4 bg-green-100 border border-green-200 rounded-md text-sm text-green-800">
                    {{ session('success') }}
                </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <form method="POST" action="{{ route('theme.update') }}" class="space-y-5">
                        @csrf
                        @method('PUT')

                        @foreach ([
                            'primary_color' => 'Primary — navigation bar and main buttons',
                            'accent_color' => 'Accent — links, active states, highlights',
                            'secondary_color' => 'Secondary — page header band',
                            'background_color' => 'Background — page backdrop',
                            'text_color' => 'Text — body copy',
                        ] as $field => $label)
                            <div>
                                <x-input-label :for="$field" :value="__($label)" />
                                <div class="mt-1 flex items-center gap-3">
                                    <input id="{{ $field }}" name="{{ $field }}" type="color"
                                           value="{{ old($field, $theme->$field) }}"
                                           data-preview="{{ $field }}"
                                           class="h-10 w-14 cursor-pointer rounded border border-gray-300 bg-white p-1" />
                                    <code id="{{ $field }}-hex" class="text-sm text-gray-600">{{ old($field, $theme->$field) }}</code>
                                </div>
                                <x-input-error :messages="$errors->get($field)" class="mt-2" />
                            </div>
                        @endforeach

                        <x-primary-button>{{ __('Save brand colors') }}</x-primary-button>
                    </form>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-sm font-semibold text-gray-800 uppercase tracking-wider mb-4">Live preview</h3>
                    <div class="rounded-lg overflow-hidden border border-gray-200">
                        <div id="preview-nav" class="px-4 py-3 flex items-center justify-between">
                            <span class="font-semibold text-white">Your business name</span>
                            <span id="preview-link" class="text-sm font-medium">Products</span>
                        </div>
                        <div id="preview-header" class="px-4 py-4">
                            <span class="font-semibold text-lg text-gray-800">Page heading</span>
                        </div>
                        <div id="preview-body" class="px-4 py-4 space-y-3">
                            <p id="preview-text" class="text-sm">Body copy looks like this against your background.</p>
                            <div class="flex items-center gap-3">
                                <span id="preview-button" class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-xs text-white uppercase tracking-widest">Save changes</span>
                                <a id="preview-accent-link" class="text-sm font-medium">A text link</a>
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-gray-400">Preview updates as you pick colors. Saving applies them across the app.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        const paint = () => {
            const get = (name) => document.querySelector(`[data-preview="${name}"]`).value;
            document.getElementById('preview-nav').style.backgroundColor = get('primary_color');
            document.getElementById('preview-button').style.backgroundColor = get('primary_color');
            document.getElementById('preview-header').style.backgroundColor = get('secondary_color');
            document.getElementById('preview-body').style.backgroundColor = get('background_color');
            document.getElementById('preview-text').style.color = get('text_color');
            for (const id of ['preview-link', 'preview-accent-link']) {
                document.getElementById(id).style.color = get('accent_color');
            }
            document.querySelectorAll('[data-preview]').forEach((input) => {
                document.getElementById(`${input.dataset.preview}-hex`).textContent = input.value;
            });
        };
        document.querySelectorAll('[data-preview]').forEach((input) => input.addEventListener('input', paint));
        paint();
    </script>
</x-app-layout>
