<x-filament::page>
    @if ($this->shouldShowDropdowns())
        {{$this->env_form}}
    @endif

    <form wire:submit.prevent="submit" wire:poll.1500ms="checkStatus" class="relative">
        {{ $this->form }}

        <div class="flex items-center gap-2 mt-6">
            <div class="relative">
                <x-filament::button
                    type="submit"
                    :disabled="$jobId && $progress < 100"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    :color="$jobId && $progress < 100 ? 'gray' : 'primary'"
                >
                    <div class="flex items-center gap-1">
                        @if($jobId && $progress < 100)
                            <x-filament::loading-indicator class="h-4 w-4"/>
                            <span>Processing {{ $progress }}%</span>
                        @else
                            <span>{{ $dryRun ? 'Get Filterable Data' : 'Filter File' }}</span>
                        @endif
                    </div>
                </x-filament::button>

                @if($jobId && $progress < 100)
                    <div class="absolute left-0 bottom-0 h-1 bg-primary-500 transition-all duration-300 rounded-b-lg"
                         style="width: {{ $progress }}%"></div>
                @endif
            </div>

            @if($jobId && $progress < 100)
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

        <!-- Add a global loading overlay for the initial submission -->
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
{{--        <div class="mt-8 space-y-6 border-s-2 border-gray-200 dark:border-gray-700 ps-6">--}}
{{--            <div class="flex items-start gap-3">--}}
{{--                <div class="w-4 h-4 mt-1 rounded-full bg-success-500 flex items-center justify-center">--}}
{{--                    <x-heroicon-s-check class="w-3 h-3 text-white"/>--}}
{{--                </div>--}}
{{--                <div>--}}
{{--                    <h4 class="text-sm font-medium text-gray-900 dark:text-white leading-snug">Filtering Resources</h4>--}}
{{--                    <p class="text-xs text-gray-600 dark:text-gray-400">Complete</p>--}}
{{--                </div>--}}
{{--            </div>--}}

{{--            <div class="flex items-start gap-3">--}}
{{--                <div class="w-4 h-4 mt-1 rounded-full bg-success-500 flex items-center justify-center">--}}
{{--                    <x-heroicon-s-check class="w-3 h-3 text-white"/>--}}
{{--                </div>--}}
{{--                <div>--}}
{{--                    <h4 class="text-sm font-medium text-gray-900 dark:text-white leading-snug">Filtering Shifts</h4>--}}
{{--                    <p class="text-xs text-gray-600 dark:text-gray-400">Complete</p>--}}
{{--                </div>--}}
{{--            </div>--}}

{{--            <div class="flex items-start gap-3">--}}
{{--                <div class="w-4 h-4 mt-1 rounded-full bg-warning-400 animate-pulse ring-2 ring-warning-300"></div>--}}
{{--                <div>--}}
{{--                    <h4 class="text-sm font-medium text-gray-900 dark:text-white leading-snug">Filtering Activities</h4>--}}
{{--                    <p class="text-xs text-warning-600 dark:text-warning-400">In progress...</p>--}}
{{--                </div>--}}
{{--            </div>--}}

{{--            <div class="flex items-start gap-3">--}}
{{--                <div class="w-4 h-4 mt-1 rounded-full bg-neutral-500"></div>--}}
{{--                <div>--}}
{{--                    <h4 class="text-sm font-medium text-gray-900 dark:text-white leading-snug">Filtering SLAs</h4>--}}
{{--                    <p class="text-xs text-gray-600 dark:text-gray-400">Waiting</p>--}}
{{--                </div>--}}
{{--            </div>--}}
{{--        </div>--}}



        {{--    <div class="flex items-center gap-2 mt-2">--}}
    {{--        <p class="text-sm text-gray-500">Status: <span class="font-medium">{{ $status ?: 'Waiting' }}</span></p>--}}
    {{--        @if($progress > 0)--}}
    {{--            <span class="text-gray-300">|</span>--}}
    {{--            <p class="text-sm text-gray-500">Progress: <span class="font-medium">{{ $progress }}%</span></p>--}}
    {{--        @endif--}}
    {{--    </div>--}}

    {{--    --}}{{-- Progress bar while job is running --}}
    {{--    @if ($jobId && $progress < 100)--}}
    {{--        <div wire:poll.1500ms="checkStatus">--}}
    {{--            <div class="mt-6">--}}
    {{--                <div class="flex items-center justify-between mb-2">--}}
    {{--                    <p class="font-semibold">--}}
    {{--                        {{ $status === 'processing' ? 'Processing... ' : 'Preparing... ' }}--}}
    {{--                        <span class="text-primary-600">{{ $progress }}%</span>--}}
    {{--                    </p>--}}

    {{--                    --}}{{-- Phase indicator based on progress --}}
    {{--                    <span class="text-sm text-gray-500">--}}
    {{--                        @if($progress < 10)--}}
    {{--                            Loading file data...--}}
    {{--                        @elseif($progress < 30)--}}
    {{--                            Filtering resources...--}}
    {{--                        @elseif($progress < 60)--}}
    {{--                            Processing shifts and activities...--}}
    {{--                        @elseif($progress < 90)--}}
    {{--                            Finalizing results...--}}
    {{--                        @else--}}
    {{--                            Preparing download...--}}
    {{--                        @endif--}}
    {{--                    </span>--}}
    {{--                </div>--}}
    {{--                <div class="w-full h-4 bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden">--}}
    {{--                    <div class="h-4 bg-primary-500 rounded-lg transition-all duration-500"--}}
    {{--                         style="width: {{ $progress }}%"></div>--}}
    {{--                </div>--}}

    {{--                --}}{{-- Elapsed time indicator --}}
    {{--                <p class="text-xs text-gray-500 mt-1">--}}
    {{--                    Time elapsed: {{ now()->diffForHumans($jobCreatedAt, ['parts' => 1, 'short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}--}}
    {{--                </p>--}}
    {{--            </div>--}}
    {{--        </div>--}}
    {{--    @endif--}}

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
