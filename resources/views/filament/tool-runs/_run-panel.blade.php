<x-filament::section>
    <dl class="grid gap-4 text-sm sm:grid-cols-4">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Tool</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $run->tool->getLabel() }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Status</dt>
            <dd><x-filament::badge :color="$run->status->getColor()">{{ $run->status->getLabel() }}</x-filament::badge></dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Run date</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $run->run_date?->format('j M Y') ?? 'Not known' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Started by</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $run->startedBy?->name }}</dd>
        </div>
    </dl>
    @if (($run->summary['meta']['time_zone_default'] ?? false) === true)
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Times are UTC because the dataset has no time zone set.</p>
    @endif
</x-filament::section>

@if ($isRunning && filled($run->progress['label'] ?? null))
    <x-filament::section>
        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $run->progress['label'] }}</p>
        @foreach ((array) ($run->progress['details'] ?? []) as $detail)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $detail }}</p>
        @endforeach
        @if (($run->progress['percent'] ?? null) !== null)
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $run->progress['percent'] }}">
                <div class="h-2 rounded-full bg-primary-600 transition-all" style="width: {{ (int) $run->progress['percent'] }}%"></div>
            </div>
        @endif
    </x-filament::section>
@endif

@if ($run->status === \App\Enums\ToolRunStatus::Failed)
    <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
        <x-slot name="heading">This run did not finish</x-slot>
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $run->error_message }}</p>
    </x-filament::section>
@endif

@if (filled($run->warnings))
    <div class="p-4 text-sm rounded-xl bg-warning-50 text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-200">
        <p class="mb-2 font-semibold">Warnings</p>
        <ul class="pl-5 space-y-1 list-disc">
            @foreach ($run->warnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

@php
    $sourceRuns = \App\Support\Tools\SourceRunList::for($run);
@endphp
<x-filament::section heading="Inputs">
    @if ($sourceRuns !== [])
        <ol class="space-y-1 text-sm list-decimal pl-5">
            @foreach ($sourceRuns as $source)
                <li>
                    <span class="font-medium">{{ $source['label'] }}</span>:
                    @if ($source['url'])
                        <x-filament::link :href="$source['url']">Plan travels run of {{ $source['made'] }}</x-filament::link>.
                    @else
                        <span class="text-gray-500">Plan travels run deleted.</span>
                    @endif
                    Polygons: {{ $source['polygons'] ?? 'None' }}
                </li>
            @endforeach
        </ol>
    @else
        <ul class="space-y-1 text-sm">
            @forelse ($inputFiles as $kindValue => $file)
                <li>
                    <span class="font-medium">{{ \App\Enums\ExportKind::tryFrom($kindValue)?->getLabel() ?? $kindValue }}</span>:
                    @if ($file)
                        {{ $file->original_filename }}
                        @if ($file->status === \App\Enums\ExportFileStatus::Purged)
                            <span class="text-gray-500">(raw file purged)</span>
                        @endif
                    @else
                        <span class="text-gray-500">export deleted</span>
                    @endif
                </li>
            @empty
                <li class="text-gray-500">No inputs recorded.</li>
            @endforelse
        </ul>
    @endif
</x-filament::section>

@if ($run->result !== null)
    @include($resultView, ['run' => $run])
@endif
