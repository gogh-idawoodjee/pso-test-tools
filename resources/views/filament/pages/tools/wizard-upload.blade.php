<div class="space-y-2" @if ($pending) wire:poll.5s="refreshInputs" @endif>
    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Upload a new export</p>
    @if ($scope === null)
        <p class="text-sm text-gray-500 dark:text-gray-400">Choose where first.</p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">Files are kept for {{ $ttlHours }} hours, then the raw file is deleted. Results from runs that used it are kept.</p>
        @livewire(\App\Livewire\ExportDropZone::class, ['scope' => $scope], key('wizard-drop-zone-'.$scope->getMorphClass().'-'.$scope->getKey()))
        @if ($pending)
            <p class="text-sm text-gray-500 dark:text-gray-400">Reading the file you just uploaded. It will appear above when ready.</p>
        @endif
    @endif
</div>
