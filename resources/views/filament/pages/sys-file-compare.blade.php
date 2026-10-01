<x-filament-panels::page>
    @if ($failureMessage)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">The comparison could not be completed</x-slot>

            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $failureMessage }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Uploaded files are removed when a comparison ends, so please add the files again.
            </p>
        </x-filament::section>
    @endif

    @if ($jobId)
        <div wire:poll.1500ms="checkStatus">
            <x-filament::section>
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Comparing the files…
                        <span class="text-primary-600 dark:text-primary-400">{{ $progress }}%</span>
                    </p>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($progress < 50)
                            Reading the files
                        @elseif ($progress < 60)
                            Comparing
                        @else
                            Building the report, CSVs and workbook
                        @endif
                    </span>
                </div>

                <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div
                        class="h-3 rounded-full bg-primary-500 transition-all duration-700 ease-in-out"
                        style="width: {{ $progress }}%"
                    ></div>
                </div>
            </x-filament::section>
        </div>
    @endif

    @if ($summary)
        @include('filament.pages.sys-file-compare.results')
    @endif

    <form wire:submit.prevent="run" class="relative">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
