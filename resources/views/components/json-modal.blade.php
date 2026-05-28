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
        <div class="flex items-center justify-between px-4 py-2.5 bg-gray-100 dark:bg-gray-800/80 border-b border-gray-200 dark:border-white/10">
            <div class="flex items-center gap-2">
                <div class="flex gap-1.5">
                    <span class="w-3 h-3 rounded-full bg-red-400/80"></span>
                    <span class="w-3 h-3 rounded-full bg-amber-400/80"></span>
                    <span class="w-3 h-3 rounded-full bg-green-400/80"></span>
                </div>
                <span class="ml-2 text-xs font-medium text-gray-500 dark:text-gray-400">JSON Response</span>
            </div>

            {{-- Copy Button --}}
            <button
                @click="copyJson()"
                class="
                    flex items-center gap-1.5 px-3 py-1.5
                    text-xs font-medium rounded-lg
                    transition-all duration-200
                    focus:outline-none focus:ring-2 focus:ring-primary-500/50
                "
                :class="copied
                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-1 ring-emerald-500/20'
                    : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 hover:ring-gray-400 dark:hover:ring-gray-500'"
                title="Copy JSON"
            >
                <template x-if="!copied">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                </template>
                <template x-if="copied">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                    </svg>
                </template>
                <span x-text="copied ? 'Copied!' : 'Copy'"></span>
            </button>
        </div>

        {{-- JSON Output --}}
        <div class="overflow-x-auto">
            <pre class="p-5 m-0 font-mono text-[13px] leading-6"><code
                x-ref="jsonCode"
                id="{{ $jsonElementId }}"
                class="language-json"
            >{{ $prettyJson }}</code></pre>
        </div>
    </div>
</x-filament::modal>
