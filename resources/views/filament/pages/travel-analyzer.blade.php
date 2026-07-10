<x-filament-panels::page>

    {{ $this->env_form }}
    {{ $this->travel_form }}

    {{-- Waiting / Loading State --}}
    @if($this->isWaiting)
        <div wire:poll.3s="checkTravelResults"
             class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center gap-4">
                <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Waiting for travel analysis results...
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        The analysis is being processed. Results will appear automatically when ready.
                    </p>
                </div>
            </div>
            <div class="mt-4">
                <x-filament::button color="gray" size="sm" wire:click="cancelWaiting">
                    Cancel
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- Travel Results Display --}}
    @if($this->travelResults)
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    <x-filament::icon icon="heroicon-o-chart-bar" class="inline-block h-5 w-5 mr-1" />
                    Travel Analysis Results
                </h3>
                <x-filament::button color="gray" size="sm" wire:click="$set('travelResults', null)">
                    Dismiss
                </x-filament::button>
            </div>

            @php
                $pso = data_get($this->travelResults, 'results.pso', []);
                $google = data_get($this->travelResults, 'results.google', []);
                $hasBoth = !empty($pso) && !empty($google);
            @endphp

            @if($hasBoth)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400">Metric</th>
                                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400">PSO</th>
                                <th class="px-4 py-3 font-medium text-gray-500 dark:text-gray-400">Google</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @php
                                $allKeys = collect(array_keys($pso))->merge(array_keys($google))->unique();
                            @endphp
                            @foreach($allKeys as $key)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300">
                                        {{ \Illuminate\Support\Str::headline($key) }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-white">
                                        {{ data_get($pso, $key, '-') }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-white">
                                        {{ data_get($google, $key, '-') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                {{-- Fallback: render whatever came back as key-value pairs --}}
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(\Illuminate\Support\Arr::dot($this->travelResults) as $key => $value)
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Str::headline($key) }}
                            </dt>
                            <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">
                                {{ is_array($value) ? json_encode($value) : $value }}
                            </dd>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

</x-filament-panels::page>
