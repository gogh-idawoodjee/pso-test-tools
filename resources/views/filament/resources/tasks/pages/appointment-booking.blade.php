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
            wire:target="loadSurvey,selectDay,backToMonth,releaseOffers,book,nextAgenda,previousAgenda,nextMonth,previousMonth,backToToday,rebook"
            class="absolute inset-0 z-10 items-center justify-center rounded-xl text-sm font-medium"
            style="background: color-mix(in srgb, var(--pso-surface) 80%, transparent); color: var(--pso-text);"
        >
            Asking PSO for slots...
        </div>

        @if ($this->screen === 'month')
            @php
                $tz = $this->bookingTimeZone();
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
                $current = $this->currentBooking();
            @endphp

            <x-filament::section
                icon="heroicon-o-calendar-days"
                :heading="filled($this->windowStart) ? 'Three weeks from '.$first->format('D M j') : 'Next three weeks'"
                description="Pick a day to see its offers for the chosen template. A dot means PSO has at least one valid slot that day; a grey day has none. Days are in {{ $tz }}."
            >
                <x-slot name="afterHeader">
                    <div class="flex flex-wrap items-center gap-2">
                        @if (filled($this->windowStart))
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-uturn-left" wire:click="backToToday" wire:loading.attr="disabled" :disabled="! $canRequest">
                                Back to today
                            </x-filament::button>
                        @endif
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
                        <x-filament::button size="sm" icon="heroicon-o-arrow-left" wire:click="previousMonth" wire:loading.attr="disabled" :disabled="! $canRequest || ! $this->canGoPreviousMonth()" title="Three weeks back, never before today">
                            Previous
                        </x-filament::button>
                        <x-filament::button size="sm" icon="heroicon-o-arrow-right" iconPosition="after" wire:click="nextMonth" wire:loading.attr="disabled" :disabled="! $canRequest" title="Three more weeks, starting where the last offer ended">
                            Next
                        </x-filament::button>
                    </div>
                </x-slot>

                @if ($current !== null)
                    <div
                        data-current-booking
                        class="mb-3 flex items-center gap-2 rounded-md px-4 py-3 text-sm"
                        style="background: var(--pso-surface-2); color: var(--pso-text); border: 1px solid var(--pso-border-strong);"
                    >
                        <x-filament::icon icon="heroicon-o-calendar" class="h-4 w-4" />
                        <span>{{ 'Booked: '.$current['label'].(filled($this->record->booking_sla_type_id) ? ', SLA '.$this->record->booking_sla_type_id : '') }}</span>
                    </div>
                @endif

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
                                $isBookedDay = $key === ($current['day'] ?? null);
                                if ($key === $this->selectedDay || $isBookedDay) {
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
                                aria-label="{{ $cell->format('l F j') }}: {{ $title }}{{ $isBookedDay ? ', booked' : '' }}"
                                @if ($isBookedDay) data-booked-day="{{ $key }}" @endif
                            >
                                <span @class(['font-semibold' => $key === $today])>{{ $label }}</span>
                                @if ($isBookedDay)
                                    <span class="rounded px-1 text-[10px] font-semibold uppercase leading-4" style="background: var(--pso-accent); color: var(--pso-surface);">Booked</span>
                                @endif
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
                $grid = $this->agendaGrid();
                $detail = $this->mode === 'detail';
                $slotLength = $this->slotLength();
                $templateId = data_get($this->task_data, 'appointmentTemplateId');
                $description = collect([
                    'Template '.$templateId,
                    $slotLength ? $slotLength.' windows' : null,
                    ($this->requestBlocking ?? true) ? 'blocking' : 'not blocking',
                    data_get($this->offers, 'summary'),
                ])->filter()->implode(' · ');
                // Fixed height per hour of the axis; detail mode shows three more lines per block, so it gets more room.
                $hourRem = $detail ? 6 : 3;
                $startHour = (int) data_get($grid, 'axis.startHour', 0);
                $hours = max(1, (int) data_get($grid, 'axis.endHour', $startHour + 1) - $startHour);
                $bodyHeight = $hours * $hourRem;
                $columnLabels = array_column($grid['columns'] ?? [], 'label');
                $heading = match (count($columnLabels)) {
                    0 => \Carbon\CarbonImmutable::parse($this->selectedDay)->format('l F j'),
                    1 => $columnLabels[0],
                    default => $columnLabels[0].' to '.end($columnLabels),
                };
            @endphp

            <x-filament::section
                icon="heroicon-o-clock"
                :heading="$heading"
                :description="$description"
            >
                {{-- Toolbar on its own row: the header slot squeezed the title into a narrow column. --}}
                <div class="mb-4 flex flex-wrap items-center gap-2 border-b pb-3" style="border-color: var(--pso-border);">
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

                        <span
                            data-time-zone
                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold"
                            style="background: var(--pso-surface-2); color: var(--pso-text); border: 1px solid var(--pso-border);"
                        >
                            <x-filament::icon icon="heroicon-o-globe-americas" class="h-4 w-4" />
                            <span>{{ $this->timeZoneLabel() }}</span>
                        </span>

                        <label class="inline-flex cursor-pointer items-center gap-2 text-xs font-medium" style="color: var(--pso-text);">
                            <input type="checkbox" role="switch" wire:model.live="showUtc" class="peer sr-only" @checked($this->showUtc)>
                            <span
                                aria-hidden="true"
                                class="pso-switch-track peer-focus-visible:ring-2 peer-focus-visible:ring-offset-1"
                                style="position: relative; display: inline-block; width: 2rem; height: 1.125rem; border-radius: 9999px; transition: background 0.15s; background: {{ $this->showUtc ? 'var(--pso-accent)' : 'var(--pso-border-strong)' }};"
                            >
                                <span style="position: absolute; top: 0.125rem; left: {{ $this->showUtc ? '1rem' : '0.125rem' }}; width: 0.875rem; height: 0.875rem; border-radius: 9999px; background: var(--pso-surface); transition: left 0.15s;"></span>
                            </span>
                            Show in UTC
                        </label>

                        <x-filament::tabs>
                            <x-filament::tabs.item :active="! $detail" wire:click="$set('mode', 'callcentre')">
                                Call centre
                            </x-filament::tabs.item>
                            <x-filament::tabs.item :active="$detail" wire:click="$set('mode', 'detail')">
                                Detail
                            </x-filament::tabs.item>
                        </x-filament::tabs>

                        <x-filament::button size="sm" icon="heroicon-o-arrow-left" wire:click="previousAgenda" wire:loading.attr="disabled" :disabled="! $this->canGoPreviousAgenda()" title="Ask again from earlier, by the span of these offers, never before now">
                            Previous
                        </x-filament::button>
                        <x-filament::button size="sm" icon="heroicon-o-arrow-right" iconPosition="after" wire:click="nextAgenda" wire:loading.attr="disabled" :disabled="blank($this->cursor)" title="Ask again from where the last offer ended, available or not">
                            Next
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-x-mark" wire:click="releaseOffers" wire:loading.attr="disabled">
                            Release offers
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-left" wire:click="backToMonth" wire:loading.attr="disabled">
                            Back to month
                        </x-filament::button>
                </div>

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

                @if (empty($grid['columns']))
                    <p class="text-sm" style="color: var(--pso-text-muted);">
                        PSO returned no valid offers for template {{ data_get($this->task_data, 'appointmentTemplateId') }} on this day.
                    </p>
                @else
                    {{-- Outlook style week: an hour axis, then one column per day; each offer a block at its window. --}}
                    <div class="overflow-x-auto">
                        <div class="flex" style="min-width: 100%; padding-bottom: 0.75rem;">
                            <div style="flex: 0 0 3.5rem;">
                                <div style="height: 2.25rem;"></div>
                                <div style="position: relative; height: {{ $bodyHeight }}rem;">
                                    @foreach ($grid['axis']['labels'] as $i => $hourLabel)
                                        <div
                                            class="text-xs tabular-nums"
                                            style="position: absolute; right: 0.5rem; top: {{ round($i / $hours * 100, 3) }}%; transform: translateY(-50%); white-space: nowrap; color: var(--pso-text-muted);"
                                        >{{ $hourLabel }}</div>
                                    @endforeach
                                </div>
                            </div>

                            @foreach ($grid['columns'] as $day => $column)
                                @php
                                    $maxLanes = max(1, ...array_map(static fn (array $b): int => (int) $b['lanes'], $column['blocks']));
                                @endphp

                                <div wire:key="agenda-column-{{ $day }}" data-day="{{ $day }}" class="pso-agenda-column" style="flex: 1 1 0; min-width: {{ max(10, $maxLanes * 8) }}rem; border-left: 1px solid var(--pso-border);">
                                    <div
                                        class="flex items-center justify-center text-sm font-semibold"
                                        style="height: 2.25rem; color: var(--pso-text); border-bottom: 1px solid var(--pso-border-strong);"
                                    >{{ $column['label'] }}</div>

                                    <div style="position: relative; height: {{ $bodyHeight }}rem;">
                                        @for ($i = 1; $i < $hours; $i++)
                                            <div aria-hidden="true" style="position: absolute; left: 0; right: 0; top: {{ round($i / $hours * 100, 3) }}%; border-top: 1px solid var(--pso-border); opacity: 0.6;"></div>
                                        @endfor

                                        @foreach ($column['blocks'] as $block)
                                            @php
                                                $offerId = (int) $block['id'];
                                                // The task's current booking is shown, tagged, but never offered again.
                                                $bookable = $block['valid'] && ! $block['current'];
                                                $lanes = max(1, (int) $block['lanes']);
                                                $lane = (int) $block['lane'];
                                                $blockStyle = 'position: absolute; overflow: hidden; top: '.$block['top'].'%; height: '.$block['height'].'%;'
                                                    .' left: calc('.round($lane / $lanes * 100, 3).'% + 2px); width: calc('.round(100 / $lanes, 3).'% - 4px);';
                                                $blockStyle .= $block['valid']
                                                    ? ' background: var(--pso-surface); color: var(--pso-text); border: 1px solid var(--pso-border-strong);'.($bookable ? ' cursor: pointer;' : '')
                                                    : ' background: var(--pso-surface-2); color: var(--pso-text-muted); border: 1px solid var(--pso-border); opacity: 0.7;';
                                                if ($block['best']) {
                                                    $blockStyle .= ' box-shadow: inset 3px 0 0 var(--pso-accent);';
                                                }
                                                if ($block['current']) {
                                                    $blockStyle .= ' outline: 2px dashed var(--pso-accent); outline-offset: -2px;';
                                                }
                                            @endphp

                                            <div
                                                wire:key="offer-{{ $offerId }}"
                                                data-offer="{{ $offerId }}" data-valid="{{ $block['valid'] ? 'true' : 'false' }}" data-current="{{ $block['current'] ? 'true' : 'false' }}"
                                                @if ($bookable)
                                                    x-on:click="if (! $event.target.closest('button')) $el.querySelector('[data-book]')?.click()"
                                                @endif
                                                class="pso-agenda-block {{ $block['valid'] ? 'pso-agenda-block--valid' : 'pso-agenda-block--invalid' }} {{ $block['best'] ? 'pso-agenda-block--best' : '' }} rounded-md px-2 py-1 text-xs"
                                                style="{{ $blockStyle }}"
                                                title="{{ collect([
                                                    'Offer '.$offerId.': '.$block['times'],
                                                    $block['current'] ? 'the current booking' : null,
                                                    $block['best'] ? 'best offer' : null,
                                                    $block['valid'] ? null : 'not available'.($block['reason'] ? ' (reason '.$block['reason'].')' : ''),
                                                    $block['value'] !== null ? 'value '.$block['value'] : null,
                                                    $block['resource'] === 'no resource' ? 'no resource' : 'resource '.$block['resource'],
                                                    $block['allocated'] ? 'allocated '.$block['allocated'] : null,
                                                    $block['planId'] ? 'plan '.$block['planId'] : null,
                                                    $block['offerSpan'] ? 'offer span '.$block['offerSpan'] : null,
                                                    $block['window'] ? 'window '.$block['window'] : null,
                                                ])->filter()->implode("\n") }}"
                                            >
                                                <div class="flex flex-wrap items-start justify-between gap-1">
                                                    <span class="font-semibold">{{ $block['times'] }}</span>
                                                    @if ($bookable)
                                                        <x-filament::button
                                                            data-book
                                                            size="xs"
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

                                                <div class="mt-1 flex flex-wrap gap-1">
                                                    @if ($block['current'])
                                                        <x-filament::badge color="info" size="sm">Current</x-filament::badge>
                                                    @endif
                                                    @if ($block['best'])
                                                        <x-filament::badge color="success" size="sm">Best offer</x-filament::badge>
                                                    @endif
                                                    @if ($detail && ! $block['valid'])
                                                        <x-filament::badge color="gray" size="sm">Not available</x-filament::badge>
                                                    @endif
                                                </div>

                                                @if ($detail)
                                                    @php
                                                        $rows = array_filter([
                                                            'Value' => $block['value'],
                                                            'Resource' => $block['resource'] === 'no resource' ? null : $block['resource'],
                                                            'Allocated' => $block['allocated'],
                                                            'Offer span' => filled($block['offerSpan']) && $block['offerSpan'] !== $block['times'] ? $block['offerSpan'] : null,
                                                            'Window' => $block['window'],
                                                            'Reason' => $block['valid'] ? null : ($block['reason'] ?? 'not given'),
                                                        ], static fn ($value): bool => filled($value));
                                                    @endphp
                                                    <dl class="pso-agenda-detail mt-2">
                                                        @foreach ($rows as $label => $value)
                                                            <dt>{{ $label }}</dt>
                                                            <dd>{{ $value }}</dd>
                                                        @endforeach
                                                    </dl>
                                                    <div class="pso-agenda-meta mt-2">
                                                        <span>Offer {{ $offerId }}</span>
                                                        @if ($block['planId'])
                                                            <span>Plan {{ $block['planId'] }}</span>
                                                        @endif
                                                        @if ($block['resource'] === 'no resource')
                                                            <span>No resource</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @elseif ($this->screen === 'booked')
            @php
                $summary = $this->booking ?? [];
                $window = $this->formatAppointmentWindow($this->record->appt_window_start, $this->record->appt_window_finish);
                $previousWindow = data_get($this->previousBooking, 'window');
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

            <x-filament::section icon="heroicon-o-check-circle" icon-color="success" :heading="$previousWindow !== null ? 'Rebooked' : 'Booked'">
                <x-slot name="afterHeader">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button size="sm" icon="heroicon-o-arrow-path" wire:click="rebook" wire:loading.attr="disabled" title="Back to the month to pick another window">
                            Rebook
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-left" wire:click="backToMonth">
                            Back to month
                        </x-filament::button>
                    </div>
                </x-slot>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @foreach ($rows as $label => $value)
                        <div>
                            <dt class="text-xs font-semibold uppercase" style="color: var(--pso-text-muted);">{{ $label }}</dt>
                            @if ($label === 'Window' && $previousWindow !== null)
                                <dd data-previous-window style="color: var(--pso-text-muted);" title="The window this booking replaced"><del>{{ $previousWindow }}</del></dd>
                            @endif
                            <dd style="color: var(--pso-text);">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif
    </div>

    {{-- Every services request for this task, newest first: loaded on first open, never on page load. --}}
    <x-filament::section
        icon="heroicon-o-clock"
        heading="Booking history"
        :description="'Every appointment request sent for this task in this dataset, newest first. Times in '.$this->bookingTimeZone().'.'"
        data-booking-history
    >
        <x-slot name="afterHeader">
            <x-filament::button
                size="sm"
                color="gray"
                :icon="$this->historyOpen ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down'"
                wire:click="toggleHistory"
                wire:loading.attr="disabled"
                wire:target="toggleHistory,loadHistory"
                :aria-expanded="$this->historyOpen ? 'true' : 'false'"
            >
                {{ $this->historyOpen ? 'Hide' : 'Show' }}
            </x-filament::button>
        </x-slot>

        @if ($this->historyOpen)
            <div wire:loading.flex wire:target="toggleHistory,loadHistory" class="items-center gap-2 text-sm" style="color: var(--pso-text-muted);">
                <x-filament::loading-indicator class="h-4 w-4" />
                Loading booking history...
            </div>

            @if ($this->history === null && $this->historyError === null)
                <div wire:init="loadHistory" wire:loading.remove wire:target="toggleHistory,loadHistory" class="text-sm" style="color: var(--pso-text-muted);">
                    Loading booking history...
                </div>
            @elseif (filled($this->historyError))
                <div
                    role="alert"
                    wire:loading.remove
                    wire:target="toggleHistory,loadHistory"
                    class="rounded-md px-4 py-3 text-sm"
                    style="background: var(--pso-danger-bg); color: var(--pso-danger); border: 1px solid var(--pso-danger);"
                >
                    {{ $this->historyError }}
                </div>
            @elseif (empty($this->history))
                <p class="text-sm" style="color: var(--pso-text-muted);">No booking requests for this task yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" style="color: var(--pso-text);">
                        <thead>
                            <tr class="text-xs font-semibold uppercase" style="color: var(--pso-text-muted); border-bottom: 1px solid var(--pso-border-strong);">
                                <th class="py-2 pe-4">When</th>
                                <th class="py-2 pe-4">What</th>
                                <th class="py-2 pe-4">Outcome</th>
                                <th class="py-2 pe-4">Offers</th>
                                <th class="py-2">Request</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->history as $row)
                                <tr wire:key="history-{{ $row['requestId'] }}" style="border-bottom: 1px solid var(--pso-border);">
                                    <td class="py-2 pe-4 whitespace-nowrap tabular-nums">{{ $row['when'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['what'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['outcome'] }}</td>
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ $row['offers'] }}</td>
                                    <td class="py-2 font-mono text-xs" title="{{ $row['requestId'] }}">{{ $row['requestIdShort'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
