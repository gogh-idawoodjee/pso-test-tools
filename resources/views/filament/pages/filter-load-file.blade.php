<x-filament::page>
    <form wire:submit.prevent="submit">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            {{ $dryRun ? 'Run Preview' : 'Process File' }}
        </x-filament::button>
    </form>

    {{-- Progress bar while job is running --}}
    @if ($jobId && !$downloadUrl && !$preview)
        <div
            x-data="{ interval: null }"
            x-init="interval = setInterval(() => { $wire.checkStatus(); }, 1500);"
            x-effect="if (@entangle('downloadUrl').defer || @entangle('preview').defer) clearInterval(interval)"
        >
            <div class="mt-6">
                <p class="mb-2 font-semibold">Processing... {{ $progress }}%</p>
                <div class="w-full h-4 bg-gray-200 rounded">
                    <div class="h-4 bg-primary-500 rounded transition-all duration-500" style="width: {{ $progress }}%"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- Download button --}}
    @if ($downloadUrl)
        <div class="mt-6">
            <x-filament::button tag="a" href="{{ $downloadUrl }}" target="_blank">
                Download Filtered File
            </x-filament::button>
        </div>
    @endif

    {{-- Preview results for dry-run or full run --}}
    @if ($preview)
        <div class="mt-6">
            <x-filament::section>
                <x-slot name="title">Filter Summary</x-slot>

                <ul class="list-disc pl-6 space-y-1">
                    @foreach ($preview as $key => $info)
                        <li>
                            <strong>{{ Str::headline($key) }}:</strong> {{ $info }}
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        </div>
    @endif
</x-filament::page>
