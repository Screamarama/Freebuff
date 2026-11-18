<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $product->product_type === 'rental' ? __('Edit rental item') : __('Edit sales item') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" class="space-y-6">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="product_type" value="{{ $product->product_type }}" />

                    @include('products._fields', ['type' => $product->product_type])

                    <div class="flex items-center gap-4">
                        <x-primary-button>Update product</x-primary-button>
                        <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
