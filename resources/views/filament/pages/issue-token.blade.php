<x-filament::page>
    <form wire:submit.prevent="issue" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">
            Generate Token
        </x-filament::button>
    </form>

    @if ($token)
        <div class="mt-6 p-4 bg-gray-100 rounded-lg">
            <h3 class="font-semibold text-sm mb-1">Token:</h3>
            <code class="text-sm break-all">{{ $token }}</code>
        </div>
    @endif
</x-filament::page>
