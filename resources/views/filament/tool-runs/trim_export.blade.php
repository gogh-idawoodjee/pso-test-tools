@php
    $result = $run->result ?? [];
    $tables = is_array($result['tables'] ?? null) ? $result['tables'] : [];
    $removed = is_array($result['removed_tables'] ?? null) ? $result['removed_tables'] : [];
    $dangling = is_array($result['dangling'] ?? null) ? $result['dangling'] : [];
    $countOnly = (bool) ($result['count_only'] ?? false);
    $exportId = $result['export_file_id'] ?? null;
    $export = filled($exportId) ? \App\Models\ExportFile::query()->find($exportId) : null;
    $downloadable = $export?->isDownloadable() ?? false;
    $isDataset = $run->scope instanceof \App\Models\Dataset;
    $placeUrl = $isDataset
        ? \App\Filament\Resources\DatasetResource::getUrl('view', ['record' => $run->scope])
        : \App\Filament\Pages\MyFiles::getUrl();
@endphp

<x-filament::section>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="space-y-1">
            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $run->summary['line'] ?? '' }}</p>
            @if ($countOnly)
                <p class="text-sm text-gray-500 dark:text-gray-400">Count only: no file was written.</p>
            @elseif ($export)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Saved as a new {{ $export->kind->getLabel() }} export, {{ $export->original_filename }}, {{ $isDataset ? 'on the dataset' : 'in My files' }}.
                    @if ($export->status === \App\Enums\ExportFileStatus::Summarising)
                        It is being read; other tools can use it once it is ready.
                    @endif
                </p>
            @elseif (filled($exportId))
                <p class="text-sm text-gray-500 dark:text-gray-400">The trimmed export has been deleted.</p>
            @endif
        </div>
        @if ($export && ! $countOnly)
            <div class="flex flex-wrap gap-2">
                @if ($downloadable)
                    <x-filament::button tag="a" href="{{ route('exports.download', ['exportFile' => $export->id]) }}" icon="heroicon-o-arrow-down-tray" size="sm">Download the trimmed export</x-filament::button>
                @elseif ($export->isExpired() && $export->status !== \App\Enums\ExportFileStatus::Purged)
                    <p class="self-center text-sm text-gray-500 dark:text-gray-400">This export has expired, so it cannot be downloaded.</p>
                @endif
                <x-filament::button tag="a" href="{{ $placeUrl }}" icon="heroicon-o-folder-open" color="gray" size="sm">{{ $isDataset ? 'Open the dataset' : 'Open My files' }}</x-filament::button>
            </div>
        @endif
    </div>
</x-filament::section>

@if ($dangling === [])
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">No broken references were found.</p>
    </x-filament::section>
@else
    <x-filament::section heading="References left pointing at nothing">
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">These kept rows point at rows that are not in the trimmed file. This list cannot tell references that were already broken in the original export from ones the trim caused.</p>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-1 pe-4 font-medium">Table</th>
                    <th class="py-1 pe-4 font-medium">Field</th>
                    <th class="py-1 pe-4 text-end font-medium">Rows</th>
                    <th class="py-1 font-medium">First ids</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dangling as $finding)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="py-1 pe-4">{{ $finding['table'] ?? '' }}</td>
                        <td class="py-1 pe-4">{{ $finding['field'] ?? '' }}</td>
                        <td class="py-1 pe-4 text-end">{{ number_format((int) ($finding['count'] ?? 0)) }}</td>
                        <td class="py-1">{{ implode(', ', $finding['examples'] ?? []) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
@endif

<x-filament::section heading="Tables">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400">
                <th class="py-1 pe-4 font-medium">Table</th>
                <th class="py-1 pe-4 text-end font-medium">Before</th>
                <th class="py-1 pe-4 text-end font-medium">Kept</th>
                <th class="py-1 text-end font-medium">Removed</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tables as $name => $counts)
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <td class="py-1 pe-4">
                        {{ $name }}
                        @if (in_array($name, $removed, true))
                            <x-filament::badge color="gray" size="sm" class="ms-2 inline-flex">Table removed</x-filament::badge>
                        @endif
                    </td>
                    <td class="py-1 pe-4 text-end">{{ number_format((int) ($counts['before'] ?? 0)) }}</td>
                    <td class="py-1 pe-4 text-end">{{ number_format((int) ($counts['kept'] ?? 0)) }}</td>
                    <td class="py-1 text-end">{{ number_format((int) ($counts['before'] ?? 0) - (int) ($counts['kept'] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-filament::section>
