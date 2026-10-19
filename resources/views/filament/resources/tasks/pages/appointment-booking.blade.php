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

    {{-- Leaving the page (another page, tab closed, reload) releases the open request's offers so PSO does not hold them. --}}
    <div
        class="relative"
        wire:init="loadSurvey"
        x-data="{
            released: null,
            release() {
                const id = $wire.appointmentRequestId;
                if (! id || $wire.expired || this.released === id) return;
                this.released = id;
                const body = new FormData();
                body.append('_token', @js(csrf_token()));
                body.append('appointment_request_id', id);
                navigator.sendBeacon(@js(route('tasks.release-offers', $this->record)), body);
            },
        }"
        x-on:pagehide.window="release()"
        x-on:livewire:navigating.document="release()"
    >
        <div
            wire:loading.flex
            wire:target="loadSurvey,selectDay,backToMonth,releaseOffers,book"
            class="absolute inset-0 z-10 items-center justify-center rounded-xl text-sm font-medium"
            style="background: color-mix(in srgb, var(--pso-surface) 80%, transparent); color: var(--pso-text);"
        >
            Asking PSO for slots...
        </div>

        @if ($this->screen === 'month')
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
                $canRequest = $this->slaTypes()->complete();
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
                        :disabled="! $canRequest"
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
                                @disabled(! $canRequest)
                                class="flex min-h-16 disabled:cursor-not-allowed disabled:opacity-60 flex-col items-center justify-between rounded-md px-1 py-2 text-sm transition hover:opacity-80"
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
        @elseif ($this->screen === 'agenda')
            @php
                $groups = $this->agendaGroups();
                $detail = $this->mode === 'detail';
                $slotLength = $this->slotLength();
                $templateId = data_get($this->task_data, 'appointmentTemplateId');
                $description = collect([
                    'Template '.$templateId,
                    $slotLength ? $slotLength.' windows' : null,
                    ($this->requestBlocking ?? true) ? 'blocking' : 'not blocking',
                    data_get($this->offers, 'summary'),
                ])->filter()->implode(' · ');
            @endphp

            <x-filament::section
                icon="heroicon-o-clock"
                :heading="\Carbon\CarbonImmutable::parse($this->selectedDay)->format('l F j')"
                :description="$description"
            >
                <x-slot name="afterHeader">
                    <div class="flex flex-wrap items-center gap-2">
                        @if (filled($this->countdownExpiresAt))
                            <span
                                wire:key="countdown-{{ $this->countdownExpiresAt }}"
                                x-data="{
                                    expiresAt: Date.parse(@js($this->countdownExpiresAt)),
                                    left: '',
                                    fired: @js($this->expired),
                                    timer: null,
                                    tick() {
                                        const ms = Math.max(0, this.expiresAt - Date.now());
                                        const s = Math.ceil(ms / 1000);
                                        this.left = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
                                        if (ms === 0) {
                                            clearInterval(this.timer);
                                            if (! this.fired) {
                                                this.fired = true;
                                                $wire.markExpired();
                                            }
                                        }
                                    },
                                    init() {
                                        this.tick();
                                        if (! this.fired) this.timer = setInterval(() => this.tick(), 1000);
                                    },
                                    destroy() { clearInterval(this.timer); },
                                }"
                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold tabular-nums"
                                style="background: var(--pso-surface-2); color: var(--pso-text); border: 1px solid var(--pso-border);"
                                title="Time left before PSO releases these offers"
                            >
                                <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                                <span>Offers held for</span>
                                <span x-text="left"></span>
                            </span>
                        @endif

                        <x-filament::tabs>
                            <x-filament::tabs.item :active="! $detail" wire:click="$set('mode', 'callcentre')">
                                Call centre
                            </x-filament::tabs.item>
                            <x-filament::tabs.item :active="$detail" wire:click="$set('mode', 'detail')">
                                Detail
                            </x-filament::tabs.item>
                        </x-filament::tabs>

                        <x-filament::button size="sm" color="gray" icon="heroicon-o-x-mark" wire:click="releaseOffers" wire:loading.attr="disabled">
                            Release offers
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-left" wire:click="backToMonth" wire:loading.attr="disabled">
                            Back to month
                        </x-filament::button>
                    </div>
                </x-slot>

                @if ($this->expired)
                    <div
                        role="alert"
                        class="mb-3 rounded-md px-4 py-3 text-sm"
                        style="background: var(--pso-warning-bg); color: var(--pso-warning); border: 1px solid var(--pso-warning);"
                    >
                        Offers have expired, search again
                    </div>
                @endif

                @if (filled($this->slotMessage))
                    <div
                        role="alert"
                        class="mb-3 rounded-md px-4 py-3 text-sm"
                        style="background: var(--pso-warning-bg); color: var(--pso-warning); border: 1px solid var(--pso-warning);"
                    >
                        {{ $this->slotMessage }}
                    </div>
                @endif

                @forelse ($groups as $dayLabel => $periods)
                    <div wire:key="agenda-day-{{ md5($dayLabel) }}" class="mb-4 last:mb-0">
                        <h3 class="mb-2 text-sm font-semibold" style="color: var(--pso-text);">{{ $dayLabel }}</h3>

                        @foreach ($periods as $period => $rows)
                            <div class="mb-3 last:mb-0">
                                <div class="mb-1 text-xs font-semibold uppercase" style="color: var(--pso-text-muted);">{{ $period }}</div>

                                <div class="flex flex-col gap-1">
                                    @foreach ($rows as $row)
                                        @php
                                            $offerId = (int) data_get($row, 'id');
                                            $rowStyle = $row['valid']
                                                ? 'background: var(--pso-surface); color: var(--pso-text); border: 1px solid var(--pso-border-strong);'
                                                : 'background: var(--pso-surface-2); color: var(--pso-text-muted); border: 1px solid var(--pso-border); opacity: 0.7;';
                                            if ($row['best']) {
                                                $rowStyle .= ' box-shadow: inset 3px 0 0 var(--pso-accent);';
                                            }
                                        @endphp

                                        <div
                                            wire:key="offer-{{ $offerId }}"
                                            data-offer="{{ $offerId }}" data-valid="{{ $row['valid'] ? 'true' : 'false' }}"
                                            class="flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2 text-sm"
                                            style="{{ $rowStyle }}"
                                        >
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                                <span class="font-semibold">{{ data_get($row, 'windowStartTime') }} to {{ data_get($row, 'windowEndTime') }}</span>
                                                @if ($row['best'])
                                                    <x-filament::badge color="success" size="sm">Best offer</x-filament::badge>
                                                @endif
                                                @if ($detail)
                                                    <span>Value {{ data_get($row, 'offerValue') }}</span>
                                                    <span>Resource {{ data_get($row, 'prospectiveResourceId') ?? 'none' }}</span>
                                                    <span>Allocation start {{ data_get($row, 'prospectiveAllocationStart') ?? 'none' }}</span>
                                                    <span class="text-xs" style="color: var(--pso-text-muted);">
                                                        Window {{ data_get($row, 'windowStartDatetime') }} to {{ data_get($row, 'windowEndDatetime') }}
                                                    </span>
                                                    @unless ($row['valid'])
                                                        <x-filament::badge color="gray" size="sm">Not available</x-filament::badge>
                                                    @endunless
                                                @endif
                                            </div>

                                            @if ($row['valid'])
                                                <x-filament::button
                                                    size="sm"
                                                    icon="heroicon-o-check"
                                                    wire:click="book({{ $offerId }})"
                                                    x-on:click="released = $wire.appointmentRequestId"
                                                    wire:loading.attr="disabled"
                                                    wire:target="book,releaseOffers,backToMonth,markExpired"
                                                    :disabled="$this->expired"
                                                >
                                                    Book
                                                </x-filament::button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @empty
                    <p class="text-sm" style="color: var(--pso-text-muted);">
                        PSO returned no valid offers for template {{ data_get($this->task_data, 'appointmentTemplateId') }} on this day.
                    </p>
                @endforelse
            </x-filament::section>
        @elseif ($this->screen === 'booked')
            @php
                $summary = $this->booking ?? [];
                $window = $this->formatAppointmentWindow($this->record->appt_window_start, $this->record->appt_window_finish);
                $rows = array_filter([
                    'Window' => $window ?? data_get($summary, 'selectedWindow'),
                    'Activity' => data_get($summary, 'activityId'),
                    'Resource' => data_get($summary, 'resourceId'),
                    'Assignment' => $this->formatAssignment(data_get($summary, 'assignmentStart'), data_get($summary, 'assignmentFinish')),
                    'Booking SLA type' => data_get($summary, 'bookingSlaTypeId'),
                    'Previous SLA type' => data_get($summary, 'previousSlaTypeId'),
                    'Appointment request' => data_get($summary, 'appointmentRequestId'),
                    'Temporary activity removed' => array_key_exists('temporaryActivityDeleted', $summary) ? ($summary['temporaryActivityDeleted'] ? 'Yes' : 'No') : null,
                ], static fn ($value) => filled($value));
            @endphp

            <x-filament::section icon="heroicon-o-check-circle" icon-color="success" heading="Booked">
                <x-slot name="afterHeader">
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-left" wire:click="backToMonth">
                        Back to month
                    </x-filament::button>
                </x-slot>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @foreach ($rows as $label => $value)
                        <div>
                            <dt class="text-xs font-semibold uppercase" style="color: var(--pso-text-muted);">{{ $label }}</dt>
                            <dd style="color: var(--pso-text);">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
