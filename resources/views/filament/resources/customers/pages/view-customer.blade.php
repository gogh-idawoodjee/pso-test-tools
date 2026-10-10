<x-filament-panels::page>
    {{-- Infolist (or form) plus the relation manager tabs, as Filament v5 lays them out. --}}
    {{ $this->content }}

    <x-filament::section collapsible="true">
        <x-slot name="heading">
            Appointment Booking
        </x-slot>

        {{-- Content --}}
    </x-filament::section>
</x-filament-panels::page>
