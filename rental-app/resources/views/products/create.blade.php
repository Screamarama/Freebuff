<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $type === 'rental' ? __('New rental item') : __('New sales item') }}
            </h2>
            <a href="{{ route('products.create') }}" class="text-sm text-gray-600 hover:text-gray-900">
                Change type
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="space-y-6">
                    @csrf
                    <input type="hidden" name="product_type" value="{{ $type }}" />

                    @include('products._fields', ['type' => $type])

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ $type === 'rental' ? 'Save rental item' : 'Save sales item' }}</x-primary-button>
                        <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
