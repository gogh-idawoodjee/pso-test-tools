<x-filament::page>
    <x-filament::tabs>
        <x-filament::tabs.list>
            <x-filament::tabs.item name="profile" label="Profile" />
            <x-filament::tabs.item name="location" label="Location" />
            <x-filament::tabs.item name="regions" label="Regions" />
            <x-filament::tabs.item name="skills" label="Skills" />
            <x-filament::tabs.item name="shifts" label="Shifts" />
        </x-filament::tabs.list>

        <x-filament::tabs.content name="profile">
            <x-filament::grid cols="2">
                <x-filament::card>
                    <x-filament::section heading="Personal">
                        <p><strong>Name:</strong> {{ $data['personal']['full_name'] }}</p>
                        <p><strong>Person ID:</strong> {{ $data['additional_attributes']['Person ID'] }}</p>
                        <p><strong>Resource Type:</strong> {{ $data['resource_type']['description'] }}</p>
                        <p><strong>Home Region:</strong> {{ $data['additional_attributes']['Home Region'] }}</p>
                        <p><strong>Truck ID:</strong> {{ $data['additional_attributes']['Truck ID'] }}</p>
                    </x-filament::section>
                </x-filament::card>
            </x-filament::grid>
        </x-filament::tabs.content>

        <x-filament::tabs.content name="location">
            <x-filament::grid cols="2">
                <x-filament::card heading="Start Location (Google)">
                    <p>{{ $data['location']['google_reverse_geocode_lookup']['start']['street_number'] }} {{ $data['location']['google_reverse_geocode_lookup']['start']['street_name'] }}</p>
                    <p>{{ $data['location']['google_reverse_geocode_lookup']['start']['city'] }}, {{ $data['location']['google_reverse_geocode_lookup']['start']['province'] }}</p>
                </x-filament::card>
                <x-filament::card heading="PSO Location">
                    <p>{{ $data['location']['pso']['start']['address_line1'] }}</p>
                    <p>{{ $data['location']['pso']['start']['city'] }}, {{ $data['location']['pso']['start']['province'] }}</p>
                </x-filament::card>
            </x-filament::grid>
        </x-filament::tabs.content>

        <x-filament::tabs.content name="regions">
            <x-filament::table>
                <x-slot name="head">
                    <x-filament::table.heading>Region ID</x-filament::table.heading>
                    <x-filament::table.heading>Description</x-filament::table.heading>
                </x-slot>
                <x-slot name="body">
                    @foreach ($data['regions'] as $region)
                        @if (isset($region['id']))
                            <x-filament::table.row>
                                <x-filament::table.cell>{{ $region['id'] }}</x-filament::table.cell>
                                <x-filament::table.cell>{{ $region['description'] }}</x-filament::table.cell>
                            </x-filament::table.row>
                        @endif
                    @endforeach
                </x-slot>
            </x-filament::table>
        </x-filament::tabs.content>

        <x-filament::tabs.content name="skills">
            <x-filament::table>
                <x-slot name="head">
                    <x-filament::table.heading>Skill ID</x-filament::table.heading>
                    <x-filament::table.heading>Description</x-filament::table.heading>
                </x-slot>
                <x-slot name="body">
                    @foreach ($data['skills'] as $skill)
                        @if (isset($skill['id']))
                            <x-filament::table.row>
                                <x-filament::table.cell>{{ $skill['id'] }}</x-filament::table.cell>
                                <x-filament::table.cell>{{ $skill['description'] }}</x-filament::table.cell>
                            </x-filament::table.row>
                        @endif
                    @endforeach
                </x-slot>
            </x-filament::table>
        </x-filament::tabs.content>

        <x-filament::tabs.content name="shifts">
            <x-filament::table>
                <x-slot name="head">
                    <x-filament::table.heading>Date</x-filament::table.heading>
                    <x-filament::table.heading>Time</x-filament::table.heading>
                    <x-filament::table.heading>Util %</x-filament::table.heading>
                    <x-filament::table.heading>Break</x-filament::table.heading>
                </x-slot>
                <x-slot name="body">
                    @foreach ($data['shifts']['shifts'] as $shift)
                        <x-filament::table.row>
                            <x-filament::table.cell>{{ $shift['shift_date'] }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ $shift['shift_span'] }}</x-filament::table.cell>
                            <x-filament::table.cell>{{ number_format($shift['utilisation']['percent'], 1) }}%</x-filament::table.cell>
                            <x-filament::table.cell>{{ $shift['utilisation']['total_break_time'] }}</x-filament::table.cell>
                        </x-filament::table.row>
                    @endforeach
                </x-slot>
            </x-filament::table>
        </x-filament::tabs.content>
    </x-filament::tabs>
</x-filament::page>
