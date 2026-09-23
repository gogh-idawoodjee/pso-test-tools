<div x-data="{ copied: false }" class="space-y-3">
    <div class="flex justify-end">
        <x-filament::button
            size="sm"
            color="gray"
            icon="heroicon-o-clipboard-document"
            x-on:click="navigator.clipboard.writeText($refs.json.innerText); copied = true; setTimeout(() => copied = false, 2000)"
        >
            <span x-show="! copied">Copy</span>
            <span x-show="copied" x-cloak>Copied!</span>
        </x-filament::button>
    </div>

    <pre x-ref="json" class="overflow-x-auto rounded-lg bg-gray-50 p-4 font-mono text-xs text-gray-900 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-100 dark:ring-white/10">{{ $json }}</pre>
</div>
