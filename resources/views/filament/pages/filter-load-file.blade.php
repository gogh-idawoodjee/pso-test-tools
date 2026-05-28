<x-filament::page>
    @if ($this->shouldShowDropdowns())
        {{ $this->env_form }}
    @endif

    <form wire:submit.prevent="submit" class="relative">
        {{ $this->form }}

        {{-- Progress bar while job is running --}}
        @if ($jobId)
            <div wire:poll.1500ms="checkStatus" class="mt-6">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        @if ($progress >= 100)
                            <span class="text-success-600 dark:text-success-400">Complete</span>
                        @elseif ($status === 'processing')
                            Processing...
                        @else
                            Preparing...
                        @endif
                        <span class="{{ $progress >= 100 ? 'text-success-600 dark:text-success-400' : 'text-primary-600 dark:text-primary-400' }}">
                            {{ $progress }}%
                        </span>
                    </p>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($progress >= 100)
                            Done!
                        @elseif ($progress < 10)
                            Loading file data...
                        @elseif ($progress < 30)
                            Filtering resources...
                        @elseif ($progress < 60)
                            Processing shifts and activities...
                        @elseif ($progress < 90)
                            Finalizing results...
                        @else
                            Preparing output...
                        @endif
                    </span>
                </div>
                <div class="w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div
                        class="h-4 rounded-full transition-all duration-1000 ease-in-out relative overflow-hidden
                            {{ $progress >= 100 ? 'bg-success-500' : 'bg-primary-500' }}"
                        style="width: {{ $progress }}%"
                    >
                        @if ($progress < 100)
                            {{-- Animated shimmer stripe --}}
                            <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent animate-[shimmer_1.5s_infinite]"></div>
                        @endif
                    </div>
                </div>
            </div>

            <style>
                @keyframes shimmer {
                    0% { transform: translateX(-100%); }
                    100% { transform: translateX(100%); }
                }
            </style>
        @endif

        <div class="flex items-center gap-2 mt-6">
            <x-filament::button
                type="submit"
                :disabled="filled($jobId)"
                wire:loading.attr="disabled"
                wire:target="submit"
                :color="filled($jobId) ? 'gray' : 'primary'"
            >
                <div class="flex items-center gap-1">
                    @if (filled($jobId))
                        <x-filament::loading-indicator class="h-4 w-4"/>
                        <span>Processing...</span>
                    @else
                        <span>{{ $dryRun ? 'Get Filterable Data' : 'Filter File' }}</span>
                    @endif
                </div>
            </x-filament::button>

            @if (filled($jobId) && $progress < 100)
                <x-filament::button
                    color="danger"
                    size="sm"
                    wire:click="cancelJob"
                    wire:loading.attr="disabled"
                    wire:target="cancelJob"
                >
                    <div class="flex items-center gap-1">
                        <x-heroicon-s-x-mark class="h-4 w-4"/>
                        Cancel
                    </div>
                </x-filament::button>
            @endif
        </div>

        <!-- Loading overlay for initial submission -->
        <div
            wire:loading
            wire:target="submit"
            class="absolute inset-0 bg-gray-200/50 dark:bg-gray-800/50 rounded-lg flex items-center justify-center z-10"
        >
            <div class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-4 flex items-center gap-3">
                <x-filament::loading-indicator class="h-6 w-6"/>
                <span class="text-sm font-medium">Starting processing...</span>
            </div>
        </div>
    </form>
    {{-- Download button --}}
    @if ($downloadUrl)
        <div class="mt-6">
            <x-filament::button tag="a" href="{{ $downloadUrl }}" target="_blank" color="success">
                <span class="flex items-center gap-2">
                    <x-heroicon-s-arrow-down-tray class="w-5 h-5"/>
                    Download Filtered File
                </span>
            </x-filament::button>
        </div>
    @endif

    {{-- Preview results for dry-run or full run --}}
    @if ($preview)
        <div class="mt-6 overflow-x-auto">
            <h3 class="text-lg font-medium mb-4">Filtering Summary</h3>
            <table
                class="min-w-full text-sm text-left border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3 font-medium">Entity</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium text-right">Kept</th>
                    <th class="px-4 py-3 font-medium text-right">Skipped</th>
                    <th class="px-4 py-3 font-medium text-right">% Kept</th>
                </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($preview as $item)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                        <td class="px-4 py-3 flex items-center gap-2">
                            <x-icon :name="$item['icon']" class="w-5 h-5 text-primary-500"/>
                            {{ $item['entity'] }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($item['total']) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($item['kept']) }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ isset($item['skipped']) ? number_format($item['skipped']) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($item['total'] > 0)
                                <span
                                    class="{{ $item['kept'] / $item['total'] < 0.5 ? 'text-orange-500' : 'text-emerald-500' }}">
                                    {{ number_format(($item['kept'] / $item['total']) * 100, 1) }}%
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Empty state when no job is running and no results yet --}}
    @if (!$jobId && empty($preview) && !$downloadUrl)
        <div class="mt-8 text-center py-12 bg-gray-50 dark:bg-gray-800/50 rounded-xl">
            <div class="inline-flex items-center justify-center p-3 bg-gray-100 dark:bg-gray-700 rounded-full mb-4">
                <x-heroicon-o-funnel class="w-6 h-6 text-gray-500 dark:text-gray-400"/>
            </div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-200">No filter results yet</h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">
                Upload a file and set your filtering options, then click
                "{{ $dryRun ? 'Get Filterable Data' : 'Filter File' }}" to begin.
            </p>
        </div>
    @endif

    {{-- Optional: Add a "Run another filter" button after completion --}}
    @if ($downloadUrl || !empty($preview))
        <div class="mt-8 pt-4 border-t border-gray-200 dark:border-gray-700">
            <x-filament::button
                wire:click="resetFilterJob"
                wire:loading.attr="disabled"
                wire:target="resetFilterJob"
                color="secondary"
                size="sm"
            >
                <span class="flex items-center gap-1">
                    <x-heroicon-s-arrow-path class="w-4 h-4"/>
                    Run Another Filter
                </span>
            </x-filament::button>
        </div>
    @endif
</x-filament::page>
