@php
    $count = is_callable($value) ? $value($getRecord()) : $value;
@endphp

<div class="bg-gray-50 dark:bg-gray-800 shadow-md border border-gray-200 dark:border-gray-700 rounded-xl h-full transition-all hover:bg-gray-100 dark:hover:bg-gray-700 hover:shadow-lg group">
    <div class="px-4 py-4">
        <div class="flex justify-between items-start">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 group-hover:text-gray-700 dark:group-hover:text-gray-300">{{ $label }}</div>
            <div class="bg-primary-100 dark:bg-gray-700 p-1.5 rounded-lg group-hover:bg-primary-200 dark:group-hover:bg-gray-600">
                <x-dynamic-component
                    :component="$icon"
                    class="w-6 h-6 text-primary-600 dark:text-primary-400"
                />
            </div>
        </div>
        <div class="text-3xl font-bold text-gray-900 dark:text-white mt-2">{{ $count }}</div>
    </div>
</div>
