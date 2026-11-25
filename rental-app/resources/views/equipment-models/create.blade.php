<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">New equipment model</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg max-w-3xl">
                <form method="POST" action="{{ route('equipment-models.store') }}" class="space-y-6">
                    @csrf

                    @include('equipment-models._fields')

                    <div class="flex items-center gap-4">
                        <x-primary-button>Save model</x-primary-button>
                        <a href="{{ route('equipment-models.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
