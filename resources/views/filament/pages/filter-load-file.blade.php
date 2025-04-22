<x-filament::page>
    <form wire:submit.prevent="submit">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-6">
            {{ $dryRun ? 'Get Filterable Data' : 'Filter File' }}
        </x-filament::button>
    </form>

    {{--    <p class="text-sm text-gray-500">Livewire Progress: {{ $progress }}</p>--}}

    {{-- Progress bar while job is running --}}
    @if ($jobId && $progress < 100)
        <div
            x-data="{ interval: null }"
            x-init="interval = setInterval(() => { $wire.checkStatus(); }, 1500);"
            x-effect="if (@entangle('progress').defer >= 100 || @entangle('downloadUrl').defer || @entangle('preview').defer) clearInterval(interval)"
        >
            <div class="mt-6">
                <p class="mb-2 font-semibold">Processing... {{ $progress }}%</p>
                <div class="w-full h-4 bg-gray-200 rounded">
                    <div class="h-4 bg-primary-500 rounded transition-all duration-500"
                         style="width: {{ $progress }}%"></div>
                </div>
            </div>
        </div>
    @endif


    {{-- Download button --}}
    @if ($downloadUrl)
        <div class="mt-6">
            <x-filament::button tag="a" href="{{ $downloadUrl }}" target="_blank">
                Download Filtered File
            </x-filament::button>
        </div>
    @endif

    {{-- Preview results for dry-run or full run --}}
    @if ($preview)
        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full text-sm text-left border border-gray-200 dark:border-gray-700">
                <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 font-medium">Entity</th>
                    <th class="px-4 py-2 font-medium">Total</th>
                    <th class="px-4 py-2 font-medium">Kept</th>
                    <th class="px-4 py-2 font-medium">Skipped</th>
                </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($preview as $item)
                    <tr>
                        <td class="px-4 py-2 flex items-center gap-2">
                            <x-icon :name="$item['icon']" class="w-5 h-5 text-primary-500"/>
                            {{ $item['entity'] }}
                        </td>
                        <td class="px-4 py-2">{{ $item['total'] }}</td>
                        <td class="px-4 py-2">{{ $item['kept'] }}</td>
                        <td class="px-4 py-2">
                            {{ $item['skipped'] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif


</x-filament::page>
