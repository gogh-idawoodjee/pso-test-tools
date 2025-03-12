<x-filament-panels::page>
    @if ($this->hasInfolist())
        {{ $this->infolist }}
    @else
        {{ $this->form }}
    @endif

    @if (count($relationManagers = $this->getRelationManagers()))
        <x-filament-panels::resources.relation-managers
            :active-manager="$this->activeRelationManager"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"

        />
    @endif
    <x-filament::section collapsible="true">
        <x-slot name="heading">
            Appointment Booking
        </x-slot>

        {{-- Content --}}
    </x-filament::section>
</x-filament-panels::page>
