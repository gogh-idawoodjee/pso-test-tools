<x-filament::page>
    <form wire:submit.prevent="submit">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            {{ $dryRun ? 'Run Preview' : 'Process File' }}
        </x-filament::button>
    </form>

    @if ($jobId && !$downloadUrl && !$preview)
        <div
            x-data="{ interval: null }"
            x-init="interval = setInterval(() => { $wire.checkStatus(); }, 1500);"
            x-effect="if (@entangle('downloadUrl').defer || @entangle('preview').defer) clearInterval(interval)"
        >
            <div class="mt-6">
                <p class="mb-2 font-semibold">Processing... {{ $progress }}%</p>
                <div class="w-full h-4 bg-gray-200 rounded">
                    <div class="h-4 bg-primary-500 rounded" style="width: {{ $progress }}%"></div>
                </div>
            </div>
        </div>
    @endif

    @if ($downloadUrl)
        <div class="mt-6">
            <x-filament::button tag="a" href="{{ $downloadUrl }}" target="_blank">
                Download Filtered File
            </x-filament::button>
        </div>
    @endif

    @if ($preview)
        <div class="mt-6">
            <x-filament::section>
                <x-slot name="title">Dry Run Results</x-slot>

                <ul class="list-disc pl-6">
                    @foreach ($preview as $key => $value)
                        <li><strong>{{ Str::headline($key) }}:</strong> {{ $value }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        </div>
    @endif
</x-filament::page>
