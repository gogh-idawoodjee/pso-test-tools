<x-filament-panels::page>
    @php
        $loaded = $this->messageTextsLoaded();
        $record = $this->messageTextsRecord();
        $busy = $this->messageTextsBusy();
        $stale = $this->messageTextsStale();
    @endphp

    <div @if ($busy) wire:poll.5s @endif>
        <x-filament::section heading="Message texts" description="The texts behind PSO message ids, shown in the Load audit. Loaded from an IFS language export.">
            <div class="space-y-4 text-sm">
                @if ($loaded === [])
                    <p class="text-gray-500 dark:text-gray-400">No message texts loaded.</p>
                @else
                    <table class="text-left">
                        <thead>
                            <tr class="text-gray-500 dark:text-gray-400">
                                <th class="pe-8 font-medium">Language</th>
                                <th class="font-medium">Texts</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-950 dark:text-white">
                            @foreach ($loaded as $language => $count)
                                <tr>
                                    <td class="pe-8 font-mono">{{ $language }}</td>
                                    <td>{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('text', $count) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="text-gray-600 dark:text-gray-400">The Load audit uses <span class="font-mono">{{ \App\Models\PsoMessageText::preferredLanguage() }}</span>.</p>
                @endif

                @if ($record !== null)
                    @if ($busy)
                        <p class="text-gray-600 dark:text-gray-400">Importing {{ $record['file_name'] ?? 'the file' }}...</p>
                    @elseif ($stale)
                        <p class="text-gray-600 dark:text-gray-400">This import seems to have stopped.</p>
                    @else
                        <p class="text-gray-600 dark:text-gray-400">
                            Last upload: {{ $record['file_name'] ?? 'unknown file' }}
                            @if ($name = $this->messageTextsUploaderName())
                                by {{ $name }}
                            @endif
                            @if (! empty($record['uploaded_at']))
                                on {{ \Carbon\CarbonImmutable::parse($record['uploaded_at'])->format('Y-m-d H:i') }}
                            @endif
                        </p>
                    @endif

                    @if (($record['status'] ?? null) === 'failed')
                        <x-filament::badge color="danger">{{ $record['error'] ?? 'The import failed.' }}</x-filament::badge>
                    @endif
                @endif

                <div class="flex flex-wrap gap-3">
                    {{ $this->uploadMessageTextsAction }}
                    {{ $this->clearMessageTextsAction }}
                </div>
            </div>
        </x-filament::section>
    </div>

    <form wire:submit="saveAuditDefaults" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">Save</x-filament::button>
    </form>

    <form wire:submit="saveBroadcastDefaults" class="space-y-4">
        {{ $this->broadcastDefaultsForm }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit">Save</x-filament::button>
            {{ $this->resetBroadcastDefaultsAction }}
        </div>
    </form>

    <form wire:submit="saveConnectionDefaults" class="space-y-4">
        {{ $this->connectionDefaultsForm }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit">Save</x-filament::button>
            {{ $this->resetConnectionDefaultsAction }}
        </div>
    </form>

    @php
        $definitions = $this->paramDefinitionsRecord();
    @endphp

    <x-filament::section heading="Parameter definitions" description="Parameters are described using the official PSO descriptions. Upload a ParamDefinitions.csv (columns Parameter, Definition, Note, Basis) to add to them: a Definition replaces the official text, and a Note is added after it. Your rows replace the built-in row for the same parameter. A template listing the parameters that still have no definition is offered with the results.">
        <div class="space-y-4 text-sm">
            @if ($definitions === null)
                <p class="text-gray-500 dark:text-gray-400">PSO Sys File Compare uses the built-in definitions. Upload a ParamDefinitions.csv to add to them.</p>
            @else
                @php
                    $parameterCount = (int) ($definitions['parameter_count'] ?? 0);
                @endphp
                <p class="text-gray-950 dark:text-white">
                    <span class="font-mono">{{ $definitions['file_name'] ?? 'ParamDefinitions.csv' }}</span>:
                    {{ number_format($parameterCount) }} {{ \Illuminate\Support\Str::plural('parameter', $parameterCount) }}
                </p>
                <p class="text-gray-600 dark:text-gray-400">
                    Uploaded
                    @if ($name = $this->paramDefinitionsUploaderName())
                        by {{ $name }}
                    @endif
                    @if (! empty($definitions['uploaded_at']))
                        on {{ \Carbon\CarbonImmutable::parse($definitions['uploaded_at'])->format('Y-m-d H:i') }}
                    @endif
                </p>
            @endif

            <div class="flex flex-wrap gap-3">
                {{ $this->uploadParamDefinitionsAction }}
                {{ $this->removeParamDefinitionsAction }}
            </div>
        </div>
    </x-filament::section>

    @php
        $releaseNotes = $this->releaseNotesRecord();
        $paramReference = $this->paramReferenceRecord();
    @endphp

    <x-filament::section heading="Release notes" description="The Load audit's list of known fixes released after a system's PSO build. Upload the Public Release Notes PDF from IFS whenever a newer one comes out; the fixes in the areas the audit covers (appointments, breaks, regions and skills, statuses, rota, WISE, the Gateway, rules and commit) are read from it in IFS's own words.">
        <div class="space-y-4 text-sm">
            @if ($releaseNotes === null)
                <p class="text-gray-500 dark:text-gray-400">The Load audit uses the watchlist shipped with the tools (curated up to build 6.17.0.119, July 2026).</p>
            @else
                <p class="text-gray-950 dark:text-white">
                    <span class="font-mono">{{ $releaseNotes['file_name'] ?? 'Public Release Notes.pdf' }}</span>:
                    up to build {{ $releaseNotes['build'] ?? '?' }}, released {{ $releaseNotes['date'] ?? '?' }};
                    {{ number_format((int) ($releaseNotes['kept'] ?? 0)) }} of {{ number_format((int) ($releaseNotes['fixes'] ?? 0)) }} fixes in the watchlist
                </p>
                <p class="text-gray-600 dark:text-gray-400">
                    Uploaded
                    @if ($name = $this->kbUploaderName($releaseNotes))
                        by {{ $name }}
                    @endif
                    @if (! empty($releaseNotes['uploaded_at']))
                        on {{ \Carbon\CarbonImmutable::parse($releaseNotes['uploaded_at'])->format('Y-m-d H:i') }}
                    @endif
                </p>
            @endif

            <div class="flex flex-wrap gap-3">
                {{ $this->uploadReleaseNotesAction }}
                {{ $this->removeReleaseNotesAction }}
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Parameter catalog" description="Every PSO parameter with its default and description, from the IFS documentation. The Load audit uses it for the parameters changed from default, and Sys compare to fill in defaults. IFS publishes the latest release only, so upload the page again after each PSO release.">
        <div class="space-y-4 text-sm">
            @if ($paramReference === null)
                <p class="text-gray-500 dark:text-gray-400">The tools use the catalog shipped with them (PSO {{ \App\Support\SysCompare\ParameterCatalog::BUILT_IN_PSO_VERSION }} docs).</p>
            @else
                <p class="text-gray-950 dark:text-white">
                    <span class="font-mono">{{ $paramReference['file_name'] ?? 'Parameters.html' }}</span>:
                    {{ number_format((int) ($paramReference['rows'] ?? 0)) }} parameters for PSO {{ $paramReference['pso_version'] ?? '?' }}
                </p>
                <p class="text-gray-600 dark:text-gray-400">
                    Uploaded
                    @if ($name = $this->kbUploaderName($paramReference))
                        by {{ $name }}
                    @endif
                    @if (! empty($paramReference['uploaded_at']))
                        on {{ \Carbon\CarbonImmutable::parse($paramReference['uploaded_at'])->format('Y-m-d H:i') }}
                    @endif
                </p>
            @endif

            <div class="flex flex-wrap gap-3">
                {{ $this->uploadParamReferenceAction }}
                {{ $this->removeParamReferenceAction }}
            </div>
        </div>
    </x-filament::section>

    @livewire(\App\Filament\Widgets\GoogleRoutesCache::class)

</x-filament-panels::page>
