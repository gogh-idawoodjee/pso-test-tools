<x-filament-panels::page>

    {{$this->env_form}}
    {{-- Your existing form --}}
    {{-- resources/views/filament/pages/technician-details.blade.php --}}
    {{-- Your existing form --}}
    {{ $this->technicianListForm }}

    {{-- Add the component when technician details are loaded --}}
    @if(!empty($technician_details))
        <div class="mt-6 space-y-6" x-data="{ activeTab: 'overview' }">
            {{-- Tab Navigation --}}
            <div class="bg-white rounded-lg shadow-sm border">
                <div class="flex overflow-x-auto">
                    <button @click="activeTab = 'overview'"
                            :class="activeTab === 'overview' ? 'border-primary-500 text-primary-600 bg-primary-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
                            class="flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        <x-heroicon-o-user class="w-4 h-4 mr-2"/>
                        Overview
                    </button>
                    <button @click="activeTab = 'location'"
                            :class="activeTab === 'location' ? 'border-primary-500 text-primary-600 bg-primary-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
                            class="flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        <x-heroicon-o-map-pin class="w-4 h-4 mr-2"/>
                        Location
                    </button>
                    <button @click="activeTab = 'skills'"
                            :class="activeTab === 'skills' ? 'border-primary-500 text-primary-600 bg-primary-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
                            class="flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        <x-heroicon-o-wrench-screwdriver class="w-4 h-4 mr-2"/>
                        Skills & Regions
                    </button>
                    <button @click="activeTab = 'schedule'"
                            :class="activeTab === 'schedule' ? 'border-primary-500 text-primary-600 bg-primary-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
                            class="flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap">
                        <x-heroicon-o-calendar class="w-4 h-4 mr-2"/>
                        Schedule
                    </button>
                </div>
            </div>

            {{-- Overview Tab --}}
            <div x-show="activeTab === 'overview'" class="space-y-6">
                {{-- Stats Cards --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-lg shadow-sm border p-4">
                        <div class="flex flex-col items-center">
                            <x-heroicon-o-calendar class="w-8 h-8 text-primary-500 mb-2"/>
                            <div
                                class="text-2xl font-bold text-primary-600">{{ $technician_details['shifts']['total_shifts'] ?? 0 }}</div>
                            <div class="text-sm text-gray-600">Total Shifts</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm border p-4">
                        <div class="flex flex-col items-center">
                            <x-heroicon-o-wrench-screwdriver class="w-8 h-8 text-success-500 mb-2"/>
                            <div
                                class="text-2xl font-bold text-success-600">{{ count($technician_details['skills'] ?? []) }}</div>
                            <div class="text-sm text-gray-600">Skills</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm border p-4">
                        <div class="flex flex-col items-center">
                            <x-heroicon-o-map-pin class="w-8 h-8 text-warning-500 mb-2"/>
                            <div
                                class="text-2xl font-bold text-warning-600">{{ count($technician_details['regions'] ?? []) }}</div>
                            <div class="text-sm text-gray-600">Service Regions</div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm border p-4">
                        <div class="flex flex-col items-center">
                            <x-heroicon-o-chart-bar class="w-8 h-8 text-purple-500 mb-2"/>
                            <div class="text-2xl font-bold text-purple-600">11%</div>
                            <div class="text-sm text-gray-600">Avg Utilization</div>
                        </div>
                    </div>
                </div>

                {{-- Personal Info Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <x-filament::card>
                        <div class="flex items-center mb-4">
                            <x-heroicon-o-user class="w-5 h-5 text-primary-500 mr-2"/>
                            <h3 class="text-lg font-semibold">Personal Information</h3>
                        </div>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Full Name:</span>
                                <span
                                    class="font-medium">{{ $technician_details['personal']['full_name'] ?? 'N/A' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Resource ID:</span>
                                <span class="font-medium">{{ $technician_details['resource_id'] ?? 'N/A' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Resource Type:</span>
                                <span
                                    class="font-medium">{{ $technician_details['resource_type']['type_id'] ?? 'N/A' }}</span>
                            </div>
                        </div>
                    </x-filament::card>

                    <x-filament::card>
                        <div class="flex items-center mb-4">
                            <x-heroicon-o-cog-6-tooth class="w-5 h-5 text-success-500 mr-2"/>
                            <h3 class="text-lg font-semibold">Additional Attributes</h3>
                        </div>
                        <div class="space-y-3">
                            @if(isset($technician_details['additional_attributes']))
                                @foreach($technician_details['additional_attributes'] as $key => $value)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">{{ $key }}:</span>
                                        <span class="font-medium">{{ $value ?: 'Not specified' }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </x-filament::card>
                </div>
            </div>

            {{-- Location Tab --}}
            <div x-show="activeTab === 'location'" class="space-y-6">
                {{-- Map Placeholder --}}
                <x-filament::card>
                    <div
                        class="bg-gradient-to-br from-primary-50 to-indigo-100 rounded-lg p-8 border-2 border-dashed border-primary-200">
                        <div class="flex items-center justify-center h-64">
                            <div class="text-center">
                                <x-heroicon-o-map class="w-16 h-16 text-primary-500 mx-auto mb-4"/>
                                <h3 class="text-lg font-semibold text-gray-700 mb-2">Interactive Map</h3>
                                <p class="text-gray-600 mb-4">
                                    Location: {{ $technician_details['location']['google_reverse_geocode_lookup']['start']['city'] ?? 'N/A' }}
                                    ,
                                    {{ $technician_details['location']['google_reverse_geocode_lookup']['start']['province'] ?? 'N/A' }}
                                </p>
                                <div class="bg-white rounded-lg p-4 shadow-sm inline-block">
                                    <p class="text-sm text-gray-600">Coordinates:</p>
                                    <p class="font-mono text-primary-600">
                                        {{ $technician_details['location']['pso']['start']['latitude'] ?? 'N/A' }},
                                        {{ $technician_details['location']['pso']['start']['longitude'] ?? 'N/A' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-filament::card>

                {{-- Location Details --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <x-filament::card>
                        <div class="flex items-center mb-4">
                            <x-heroicon-o-building-office class="w-5 h-5 text-primary-500 mr-2"/>
                            <h3 class="text-lg font-semibold">PSO Location Data</h3>
                        </div>
                        <div class="space-y-3">
                            @if(isset($technician_details['location']['pso']['start']))
                                @php $pso = $technician_details['location']['pso']['start']; @endphp
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Address:</span>
                                    <span class="font-medium">{{ $pso['address_line1'] ?? 'N/A' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">City:</span>
                                    <span class="font-medium">{{ $pso['city'] ?? 'N/A' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Province:</span>
                                    <span class="font-medium">{{ $pso['province'] ?? 'N/A' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Postal Code:</span>
                                    <span class="font-medium">{{ $pso['postal_code'] ?? 'N/A' }}</span>
                                </div>
                            @endif
                        </div>
                    </x-filament::card>

                    <x-filament::card>
                        <div class="flex items-center mb-4">
                            <x-heroicon-o-globe-alt class="w-5 h-5 text-success-500 mr-2"/>
                            <h3 class="text-lg font-semibold">Google Geocode Data</h3>
                        </div>
                        <div class="space-y-3">
                            @if(isset($technician_details['location']['google_reverse_geocode_lookup']['start']))
                                @php $google = $technician_details['location']['google_reverse_geocode_lookup']['start']; @endphp
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Street:</span>
                                    <span
                                        class="font-medium">{{ ($google['street_number'] ?? '') . ' ' . ($google['street_name'] ?? '') }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">City:</span>
                                    <span class="font-medium">{{ $google['city'] ?? 'N/A' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Province:</span>
                                    <span class="font-medium">{{ $google['province'] ?? 'N/A' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Country:</span>
                                    <span class="font-medium">{{ $google['country'] ?? 'N/A' }}</span>
                                </div>
                            @endif
                        </div>
                    </x-filament::card>
                </div>
            </div>

            {{-- Skills Tab --}}
            <div x-show="activeTab === 'skills'" class="space-y-6">
                {{-- Skills Section --}}
                <x-filament::card>
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-wrench-screwdriver class="w-5 h-5 text-primary-500 mr-2"/>
                        <h3 class="text-lg font-semibold">Technical Skills</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if(isset($technician_details['skills']))
                            @foreach($technician_details['skills'] as $skill)
                                <div
                                    class="flex items-center p-3 bg-primary-50 rounded-lg border border-primary-100">
                                    <x-heroicon-o-star class="w-4 h-4 text-primary-500 mr-3 flex-shrink-0"/>
                                    <div>
                                        <div class="font-medium text-sm">{{ $skill['description'] ?? 'N/A' }}</div>
                                        <div class="text-xs text-gray-500">{{ $skill['id'] ?? 'N/A' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </x-filament::card>

                {{-- Regions Section --}}
                <x-filament::card>
                    <div class="flex items-center mb-4">
                        <x-heroicon-o-map-pin class="w-5 h-5 text-success-500 mr-2"/>
                        <h3 class="text-lg font-semibold">Service Regions</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if(isset($technician_details['regions']))
                            @foreach($technician_details['regions'] as $region)
                                @if(isset($region['description']))
                                    {{-- Skip the total count entry --}}
                                    <div
                                        class="flex items-center p-3 bg-success-50 rounded-lg border border-success-100">
                                        <div class="w-3 h-3 bg-success-500 rounded-full mr-3 flex-shrink-0"></div>
                                        <div>
                                            <div class="font-medium text-sm">{{ $region['description'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $region['id'] }}</div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    </div>
                </x-filament::card>
            </div>

            {{-- Schedule Tab WITH BIG UTILIZATION BARS --}}
            <div x-show="activeTab === 'schedule'" class="space-y-6">
                <x-filament::card>
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center">
                            <x-heroicon-o-calendar class="w-5 h-5 text-purple-500 mr-2"/>
                            <h3 class="text-lg font-semibold">Recent Shifts</h3>
                        </div>
                        <span class="text-sm text-gray-500">Total: {{ $technician_details['shifts']['total_shifts'] ?? 0 }} shifts</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-4 font-medium text-gray-700">Date</th>
                                <th class="text-left py-3 px-4 font-medium text-gray-700">Time Span</th>
                                <th class="text-left py-3 px-4 font-medium text-gray-700">Duration</th>
                                <th class="text-left py-3 px-4 font-medium text-gray-700">Utilization</th>
                                <th class="text-left py-3 px-4 font-medium text-gray-700">Unutilized Time</th>
                            </tr>
                            </thead>
                            <tbody>
                            @if(isset($technician_details['shifts']['shifts']))
                                @foreach(array_slice($technician_details['shifts']['shifts'], 0, 10) as $index => $shift)
                                    <tr class="{{ $index % 2 === 0 ? 'bg-gray-50' : 'bg-white' }}">
                                        <td class="py-4 px-4">{{ $shift['shift_date'] ?? 'N/A' }}</td>
                                        <td class="py-4 px-4">{{ $shift['shift_span'] ?? 'N/A' }}</td>
                                        <td class="py-4 px-4">{{ ($shift['shift_duration'] ?? 0) }}h</td>
                                        <td class="py-4 px-4">
                                            @php
                                                $percent = (float) ($shift['utilisation']['percent'] ?? 0);
                                                $displayPercent = min($percent, 100);
                                            @endphp
                                            <div class="flex items-center space-x-3">
                                                <div
                                                    class="w-24 bg-gray-200 rounded-full h-4 relative overflow-hidden">
                                                    <div
                                                        class="bg-gradient-to-r from-purple-400 to-purple-600 h-full rounded-full transition-all duration-500 ease-in-out shadow-sm"
                                                        style="width: {{ $displayPercent }}%"></div>
                                                </div>
                                                <span class="text-sm font-semibold text-purple-600 min-w-[45px]">{{ round($percent) }}%</span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4 text-sm text-gray-600">{{ $shift['utilisation']['total_unutilised_time'] ?? 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            @endif
                            </tbody>
                        </table>
                    </div>
                </x-filament::card>
            </div>
        </div>
    @endif

</x-filament-panels::page>
