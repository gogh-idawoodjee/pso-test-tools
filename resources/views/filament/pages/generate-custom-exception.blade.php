<x-filament-panels::page>


    {{$this->env_form}}
    {{$this->exception_form}}


    <x-filament::modal id="show-json" slide-over width="5xl">
        @php
            $prettyJson = $this->json_form_data['json_response_pretty'];
            $jsonElementId = 'jsonContent-' . uniqid('', true);
        @endphp

        <div
            x-data="{ copied: false }"
            class="group relative rounded-lg ring-1 ring-gray-300 dark:ring-gray-700 overflow-hidden"
        >
            {{-- Copy Button --}}
            <button
                @click="
                    navigator.clipboard.writeText(document.getElementById('{{ $jsonElementId }}').innerText);
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                "
                style="position: absolute; top: 1rem; right: 1rem; z-index: 50;"
                class="
                    bg-white dark:bg-gray-800
                    text-gray-700 dark:text-gray-200
                    hover:bg-gray-50 dark:hover:bg-gray-700
                    border border-gray-300 dark:border-gray-600
                    rounded-md px-3 py-2 text-xs font-medium
                    shadow-md hover:shadow-lg
                    transition-all duration-200
                    focus:outline-none focus:ring-2 focus:ring-blue-500
                "
                title="Copy JSON"
            >
                <span x-show="!copied" class="flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Copy
                </span>
                <span x-show="copied" x-transition.opacity.150ms class="flex items-center gap-1 text-green-100">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Copied!
                </span>
            </button>

            {{-- JSON Output --}}
            <pre
                class="overflow-x-auto p-4 pr-24 m-0 font-mono text-sm rounded-lg"
            ><code id="{{ $jsonElementId }}" class="language-json">{{ $prettyJson }}</code></pre>
        </div>
    </x-filament::modal>


</x-filament-panels::page>
