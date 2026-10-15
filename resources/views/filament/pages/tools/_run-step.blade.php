@php
    $run = $this->currentRun();
@endphp

<div @if ($this->isRunning()) wire:poll.2s @endif class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button tag="a" :href="\App\Filament\Pages\ToolRunPage::urlFor($run->scope, $run)" icon="heroicon-o-arrow-top-right-on-square">Open the full result</x-filament::button>
        <x-filament::button color="gray" wire:click="runAgain" icon="heroicon-o-arrow-path">Run again</x-filament::button>
        <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\Dashboard::getUrl()" icon="heroicon-o-home">Back to home</x-filament::button>
    </div>

    @include('filament.tool-runs._run-panel', ['run' => $run, 'inputFiles' => $this->inputFiles(), 'resultView' => $this->resultView(), 'isRunning' => $this->isRunning()])
</div>
