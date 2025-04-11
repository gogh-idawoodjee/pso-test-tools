<x-filament::page>
    <form wire:submit.prevent="submit">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            Process File
        </x-filament::button>
    </form>

{{--    <p>Livewire Job ID: {{ $jobId }}</p>--}}
{{--    @isset($progress)--}}
{{--        <p>Progress: {{ $progress }}%</p>--}}
{{--    @endisset--}}
    @if ($jobId && !$downloadUrl)
        <div
            x-data="{ interval: null }"
            x-init="
                interval = setInterval(() => {
                    $wire.checkStatus();
                }, 1500);
            "
            {{--            x-effect="if (@entangle('downloadUrl')) clearInterval(interval)"--}}
            x-effect="
    if (@entangle('progress').defer >= 100 || @entangle('downloadUrl').defer) {
        clearInterval(interval);
    }
"

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
                Download Filtered JSON
            </x-filament::button>
        </div>
    @endif
</x-filament::page>
