<x-filament-panels::page>
    @if ($this->runId === null)
        <form wire:submit="start">
            {{ $this->form }}
        </form>
    @else
        @include('filament.pages.tools._run-step')
    @endif
</x-filament-panels::page>
