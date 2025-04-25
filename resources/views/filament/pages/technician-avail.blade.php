<x-filament-panels::page>
    <div class="flex flex-col lg:flex-row gap-6 items-start">
        {{-- Form --}}
        <div class="w-full lg:w-2/3">
            {{ $this->form }}

            <div
                @if($this->jobId && $progress < 100 && $status !== 'complete')
                    wire:poll.500ms="checkStatus"
                @endif
            >
                {{-- Polling logic here --}}
            </div>
        </div>

        {{-- Status Panel --}}
        <div
            class="w-full lg:w-1/3 max-w-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg shadow-sm p-4 space-y-4">
            <div>
                <h2 class="text-sm text-gray-500 dark:text-gray-400 font-semibold uppercase">Job ID</h2>
                <p class="text-gray-800 dark:text-gray-100 text-sm">{{ $jobId }}</p>
            </div>
            <div>
                <h2 class="text-sm text-gray-500 dark:text-gray-400 font-semibold uppercase">Status</h2>
                <p class="text-sm text-blue-600 dark:text-blue-400 font-medium">{{ $status }}</p>
            </div>
            <div class="w-full max-w-sm">
                <h2 class="text-sm text-gray-500 dark:text-gray-400 font-semibold uppercase">Progress</h2>

                <div
                    style="width: 100%; height: 12px; background-color: #e5e7eb; border-radius: 4px; overflow: hidden;">
                    <div style="width: {{ $progress }}%; height: 100%; background-color: #10b981;"></div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $progress }}%</p>
            </div>

            <div>
                <h2 class="text-sm text-gray-500 dark:text-gray-400 font-semibold uppercase">Selected Technician</h2>
                <p class="text-gray-800 dark:text-gray-100 text-sm">{{ $formData['selectedTechnician'] ?? 'None' }}</p>
            </div>
        </div>

    </div>

    {{-- Gantt Chart --}}
    @if($technicianShifts)
        <x-filament::section heading="Technician Availability">

            <x-technician-gantt :shifts="$technicianShifts"/>
        </x-filament::section>
    @endif
    @if($enableDebug)
        <pre class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 p-2 rounded overflow-x-auto">

            {{ json_encode($technicianShifts[0] ?? 'none', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}
            {{ json_encode($technicianShifts[1] ?? 'none', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}
            {{ json_encode($technicianShifts[2] ?? 'none', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}

        </pre>
    @endif
    {{--    --}}{{-- Raw JSON (debug) --}}
    {{--    <pre class="text-sm bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-100 p-2 rounded overflow-x-auto">--}}
    {{--    {{ json_encode($technicianShifts, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}--}}
    {{--    </pre>--}}
</x-filament-panels::page>
