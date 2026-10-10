{{-- Fallback until a tool has its own dashboard view: each top-level key of the result. --}}
<x-filament::section heading="Result">
    <dl class="space-y-3 text-sm">
        @foreach ($run->result ?? [] as $key => $value)
            <div>
                <dt class="font-medium text-gray-900 dark:text-gray-100">{{ str_replace('_', ' ', $key) }}</dt>
                <dd class="text-gray-600 dark:text-gray-300">
                    @if (is_array($value))
                        {{ count($value) }} {{ \Illuminate\Support\Str::plural('item', count($value)) }}
                    @elseif (is_bool($value))
                        {{ $value ? 'Yes' : 'No' }}
                    @else
                        {{ $value }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</x-filament::section>
