@php
    $run = $this->getToolRun();
@endphp

<x-filament-panels::page>
    <div @if ($this->isRunning()) wire:poll.2s @endif class="space-y-6">
        @include('filament.tool-runs._run-panel', ['run' => $run, 'inputFiles' => $this->inputFiles(), 'resultView' => $this->resultView(), 'isRunning' => $this->isRunning()])
    </div>
</x-filament-panels::page>
