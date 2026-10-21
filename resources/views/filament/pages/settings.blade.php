<x-filament-panels::page>
    @php
        $loaded = $this->messageTextsLoaded();
        $record = $this->messageTextsRecord();
        $busy = $this->messageTextsBusy();
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
                @endif

                @if ($record !== null)
                    @if ($busy)
                        <p class="text-gray-600 dark:text-gray-400">Importing {{ $record['file_name'] ?? 'the file' }}...</p>
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

    @livewire(\App\Filament\Widgets\GoogleRoutesCache::class)

</x-filament-panels::page>
