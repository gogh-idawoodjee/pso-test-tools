<x-filament::page>
    <form wire:submit.prevent="submit">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-6">
            Do the Thing
        </x-filament::button>
    </form>
    {{--    <p class="text-sm text-gray-500">Livewire Progress: {{ $progress }}</p>--}}

    {{-- Progress bar while job is running --}}
    @if ($jobId && $progress < 100)
        <div
            x-data="{ interval: null }"
            x-init="interval = setInterval(() => { $wire.checkStatus(); }, 1500);"
            x-effect="if (@entangle('progress').defer >= 100 || @entangle('downloadUrl').defer || @entangle('preview').defer) clearInterval(interval)"
        >
            <div class="mt-6">
                <p class="mb-2 font-semibold">Processing... {{ $progress }}%</p>
                <div class="w-full h-4 bg-gray-200 rounded">
                    <div class="h-4 bg-primary-500 rounded transition-all duration-500"
                         style="width: {{ $progress }}%"></div>
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


</x-filament::page>
