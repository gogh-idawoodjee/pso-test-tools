<x-filament-panels::page>
    <x-filament::section
        heading="Upload"
        :description="'Any export kind. Files are kept for '.(int) config('exports.ttl_hours', 12).' hours, then the raw file is deleted. Results from runs that used it are kept.'"
    >
        @livewire(\App\Livewire\ExportDropZone::class, ['scope' => auth()->user()], key('my-files-drop-zone'))
    </x-filament::section>

    @livewire(\App\Filament\Widgets\MyExportFiles::class)

    @livewire(\App\Filament\Widgets\MyToolRuns::class)
</x-filament-panels::page>
