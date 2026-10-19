<x-filament-panels::page>
    {{ $this->env_form }}

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            {{ $this->infolist }}
        </div>
        <div>
            {{ $this->taskForm }}
        </div>
    </div>

    @if (filled($this->pageError))
        <div
            role="alert"
            class="rounded-md px-4 py-3 text-sm"
            style="background: var(--pso-danger-bg); color: var(--pso-danger); border: 1px solid var(--pso-danger);"
        >
            {{ $this->pageError }}
        </div>
    @endif

    <div class="relative">
        <div
            wire:loading.flex
            wire:target="loadSurvey,selectDay,backToMonth"
            class="absolute inset-0 z-10 items-center justify-center rounded-xl text-sm font-medium"
            style="background: color-mix(in srgb, var(--pso-surface) 80%, transparent); color: var(--pso-text);"
        >
            Asking PSO for slots...
        </div>

        @if ($this->view === 'month')
            @php
                $tz = $this->record->dataset->timeZone();
                $days = array_keys($this->dayStates);
                $first = \Carbon\CarbonImmutable::parse($days[0] ?? now($tz)->toDateString(), $tz);
                $last = \Carbon\CarbonImmutable::parse(end($days) ?: $first->toDateString(), $tz);
                $gridStart = $first->startOfWeek(\Carbon\CarbonInterface::MONDAY);
                $gridEnd = $last->endOfWeek(\Carbon\CarbonInterface::SUNDAY)->startOfDay();
                $cells = [];
                for ($d = $gridStart; $d->lte($gridEnd); $d = $d->addDay()) {
                    $cells[] = $d;
                }
                $today = $first->toDateString();
            @endphp

            <x-filament::section
                icon="heroicon-o-calendar-days"
                heading="Next three weeks"
                description="Pick a day to see its offers for the chosen template. A dot means PSO has at least one valid slot that day; a grey day has none. Days are in {{ $tz }}."
            >
                <x-slot name="afterHeader">
                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                        wire:click="loadSurvey"
                        wire:loading.attr="disabled"
                    >
                        Check again
                    </x-filament::button>
                </x-slot>

                <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold uppercase" style="color: var(--pso-text-muted);">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="py-1">{{ $weekday }}</div>
                    @endforeach
                </div>

                <div class="mt-1 grid grid-cols-7 gap-1">
                    @foreach ($cells as $cell)
                        @php
                            $key = $cell->toDateString();
                            $state = $this->dayStates[$key] ?? null;
                            $label = $cell->day === 1 || $key === $today ? $cell->format('M j') : $cell->format('j');
                        @endphp

                        @if ($state === null)
                            <div class="min-h-16 rounded-md" aria-hidden="true"></div>
                        @else
                            @php
                                $style = match ($state) {
                                    'none' => 'background: var(--pso-surface-2); color: var(--pso-text-muted); border: 1px solid var(--pso-border);',
                                    default => 'background: var(--pso-surface); color: var(--pso-text); border: 1px solid var(--pso-border-strong);',
                                };
                                if ($key === $this->selectedDay) {
                                    $style .= ' box-shadow: var(--pso-focus-ring);';
                                }
                                $title = match ($state) {
                                    'slots' => 'valid slots',
                                    'none' => 'no valid slots',
                                    default => 'not known',
                                };
                            @endphp

                            <button
                                type="button"
                                wire:key="day-{{ $key }}"
                                wire:click="selectDay('{{ $key }}')"
                                wire:loading.attr="disabled"
                                class="flex min-h-16 flex-col items-center justify-between rounded-md px-1 py-2 text-sm transition hover:opacity-80"
                                style="{{ $style }}"
                                aria-label="{{ $cell->format('l F j') }}: {{ $title }}"
                            >
                                <span @class(['font-semibold' => $key === $today])>{{ $label }}</span>
                                @if ($state === 'slots')
                                    <span class="h-2.5 w-2.5 rounded-full" style="background: var(--pso-accent);"></span>
                                @else
                                    <span class="h-2.5 w-2.5"></span>
                                @endif
                            </button>
                        @endif
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap gap-4 text-xs" style="color: var(--pso-text-muted);">
                    <span class="inline-flex items-center gap-1">
                        <span class="h-2.5 w-2.5 rounded-full" style="background: var(--pso-accent);"></span> Valid slots
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <span class="h-3 w-3 rounded-sm" style="background: var(--pso-surface-2); border: 1px solid var(--pso-border);"></span> No valid slots
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <span class="h-3 w-3 rounded-sm" style="background: var(--pso-surface); border: 1px solid var(--pso-border-strong);"></span> Not known
                    </span>
                </div>
            </x-filament::section>
        @elseif ($this->view === 'agenda')
            {{-- Task 7 builds the agenda (call centre and detail views, book, release) from $this->offers. --}}
            <x-filament::section
                icon="heroicon-o-clock"
                :heading="\Carbon\CarbonImmutable::parse($this->selectedDay)->format('l F j')"
                :description="data_get($this->offers, 'summary')"
            >
                <x-slot name="afterHeader">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-left" wire:click="backToMonth">
                        Back to month
                    </x-filament::button>
                </x-slot>

                <p class="text-sm" style="color: var(--pso-text-muted);">
                    {{ count(data_get($this->offers, 'validOffers', [])) }} valid offers for template {{ data_get($this->task_data, 'appointmentTemplateId') }}.
                </p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
