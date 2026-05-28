<x-filament::modal id="show-json" slide-over width="5xl">
    @php
        $prettyJson = $this->json_form_data['json_response_pretty'] ?? null;
        $jsonElementId = 'jsonContent-' . uniqid('', true);
    @endphp

    <div
        x-data="{
            copied: false,
            copyJson() {
                navigator.clipboard.writeText(this.$refs.jsonCode.textContent);
                this.copied = true;
                setTimeout(() => this.copied = false, 2000);
            }
        }"
        x-init="$nextTick(() => {
            const block = $refs.jsonCode;
            if (block && block.textContent.trim().length > 4) {
                delete block.dataset.highlighted;
                hljs.highlightElement(block);
            }
        })"
        x-on:json-updated.window="$nextTick(() => {
            const block = $refs.jsonCode;
            if (block && block.textContent.trim().length > 4) {
                delete block.dataset.highlighted;
                hljs.highlightElement(block);
            }
        })"
        class="relative rounded-xl overflow-hidden ring-1 ring-gray-200 dark:ring-white/10"
    >
        {{-- Header Bar --}}
        <div class="flex items-center justify-between px-4 py-4 bg-gray-100 dark:bg-gray-800/80 border-b border-gray-200 dark:border-white/10">
            <div class="flex items-center gap-2.5">
                <div class="flex gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-red-400/80"></span>
                    <span class="w-3 h-3 rounded-full bg-amber-400/80"></span>
                    <span class="w-3 h-3 rounded-full bg-green-400/80"></span>
                </div>
                <span class="ml-1 text-sm font-medium text-gray-500 dark:text-gray-400">JSON Response</span>
            </div>

            {{-- Copy Button --}}
            <button
                @click="copyJson()"
                class="
                    flex items-center gap-2 px-4 py-2
                    text-sm font-medium rounded-lg
                    transition-all duration-200
                    focus:outline-none focus:ring-2 focus:ring-primary-500/50
                "
                :class="copied
                    ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 ring-1 ring-emerald-500/30'
                    : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 hover:ring-gray-400 dark:hover:ring-gray-500'"
            >
                {{-- Clipboard icon (default) --}}
                <svg
                    x-show="!copied"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-75"
                    class="w-4 h-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9.75a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                </svg>

                {{-- Checkmark icon (copied) --}}
                <svg
                    x-show="copied"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-75"
                    x-transition:enter-end="opacity-100 scale-100"
                    class="w-4 h-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="2.5"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>

                <span x-text="copied ? 'Copied!' : 'Copy to clipboard'"></span>
            </button>
        </div>

        {{-- JSON Output --}}
        <div class="overflow-x-auto bg-[#0d1117]">
            <pre class="m-0 !bg-transparent"><code
                x-ref="jsonCode"
                id="{{ $jsonElementId }}"
                class="language-json !text-[13px] !leading-6 !p-5"
            >{{ $prettyJson }}</code></pre>
        </div>
    </div>
</x-filament::modal>
