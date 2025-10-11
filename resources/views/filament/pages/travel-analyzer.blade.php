<x-filament-panels::page>
    <div
        x-data="{
        polling: false,
        pollInterval: null,
        pollCount: 0,
        maxPolls: 60,

        startPolling(url) {
            this.polling = true;
            this.pollCount = 0;

            this.pollInterval = setInterval(() => {
                this.pollCount++;

                if (this.pollCount >= this.maxPolls) {
                    this.stopPolling();
                    $wire.set('pollingStatus', 'timeout');
                    return;
                }

                $wire.checkResults();
            }, 5000);
        },

        stopPolling() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
            this.polling = false;
        }
    }"
        x-on:start-polling.window="startPolling($event.detail.url)"
        x-on:stop-polling.window="stopPolling()"
    >

        {{$this->env_form}}
        {{$this->travel_form}}

        @if($isPolling)
            <div class="mt-6 rounded-lg bg-primary-50 p-4 dark:bg-primary-400/10">
                <div class="flex items-center space-x-3">
                    <svg class="h-5 w-5 animate-spin text-primary-600 dark:text-primary-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-medium text-primary-700 dark:text-primary-300">
                        Processing travel analysis... This may take up to 2 minutes.
                    </span>
                </div>
            </div>
        @endif

        @if($travelResults)
            <div class="mt-6 rounded-lg bg-success-50 p-6 dark:bg-success-400/10">
                <h3 class="text-lg font-semibold text-success-900 dark:text-success-100 mb-4">
                    Travel Analysis Results
                </h3>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-md bg-white dark:bg-gray-800 p-4 shadow-sm">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-2">From</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ data_get($travelResults, 'start_address') }}</p>
                        </div>
                        <div class="rounded-md bg-white dark:bg-gray-800 p-4 shadow-sm">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-2">To</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ data_get($travelResults, 'end_address') }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="rounded-md bg-white dark:bg-gray-800 p-4 shadow-sm">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">PSO Results</h4>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Time:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ data_get($travelResults, 'pso.time') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Distance:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ data_get($travelResults, 'pso.distance') }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-md bg-white dark:bg-gray-800 p-4 shadow-sm">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">Google Results</h4>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Time:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ data_get($travelResults, 'google.time') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Distance:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ data_get($travelResults, 'google.distance') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($pollingStatus === 'timeout')
            <div class="mt-6 rounded-lg bg-warning-50 p-4 dark:bg-warning-400/10">
                <p class="text-sm text-warning-700 dark:text-warning-300">
                    Request timed out. Please check the results manually at:
                    <a href="{{ $resultsUrl }}" target="_blank" class="underline font-medium hover:text-warning-800 dark:hover:text-warning-200">
                        View Results
                    </a>
                </p>
            </div>
        @endif

        @if($pollingStatus === 'error')
            <div class="mt-6 rounded-lg bg-danger-50 p-4 dark:bg-danger-400/10">
                <p class="text-sm text-danger-700 dark:text-danger-300">
                    An error occurred while checking results. Please try again or check the results manually at:
                    <a href="{{ $resultsUrl }}" target="_blank" class="underline font-medium hover:text-danger-800 dark:hover:text-danger-200">
                        View Results
                    </a>
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
