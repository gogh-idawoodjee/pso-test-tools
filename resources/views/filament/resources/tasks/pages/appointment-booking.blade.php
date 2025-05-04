<x-filament-panels::page>
    {{$this->env_form}}

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            {{$this->infolist}}
        </div>
        <div>
            {{$this->taskForm}}
        </div>
    </div>

    @if ($response && filled($response['data']['appointment_offers']['valid_offers']))
        @php
            $offers = $response['data']['appointment_offers'];
            $validOffers = collect($offers['valid_offers'] ?? []);
            $bestId = $offers['best_offer']['id'] ?? null;

            $grouped = $validOffers->groupBy('window_day_english');
        @endphp

        <x-filament::section class="mt-8" heading="Valid Appointment Offers" icon="heroicon-o-calendar-days">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6">
                @foreach ($grouped as $day => $offersForDay)
                    <div class="space-y-4">
                        <h4 class="text-base font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-300 dark:border-gray-700 pb-2 px-1">
                            {{ $day }}
                        </h4>

                        @foreach ($offersForDay as $offer)
                            @php
                                $startTime = $offer['window_start_time'] ?? '12:00 PM';
                                [$time, $ampm] = explode(' ', $startTime);
                                [$hour] = explode(':', $time);
                                $hour = (int) $hour;
                                $hour24 = ($ampm === 'PM' && $hour < 12) ? $hour + 12 : ($ampm === 'AM' && $hour === 12 ? 0 : $hour);

                                // Set period and colors based on time
                                if ($hour24 < 12) {
                                    $period = 'Morning';
                                    $periodIcon = '☀️';
                                    $bgColor = '#0a3056'; // Dark blue
                                    $borderColor = '#4299e1';
                                } elseif ($hour24 < 15) {
                                    $period = 'Midday';
                                    $periodIcon = '🌞';
                                    $bgColor = '#553c10'; // Dark amber
                                    $borderColor = '#f59e0b';
                                } elseif ($hour24 < 18) {
                                    $period = 'Afternoon';
                                    $periodIcon = '🌤️';
                                    $bgColor = '#5a2917'; // Dark orange
                                    $borderColor = '#f97316';
                                } else {
                                    $period = 'Evening';
                                    $periodIcon = '🌙';
                                    $bgColor = '#372965'; // Dark purple
                                    $borderColor = '#8b5cf6';
                                }

                                $isBest = $offer['id'] === $bestId;
                                $bestStyle = $isBest ? 'border: 3px solid #10b981; box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);' : '';
                            @endphp

                                <!-- Card with inline styles for guaranteed color -->
                            <div style="border-radius: 10px; overflow: hidden; margin-bottom: 8px; background-color: {{ $bgColor }}; border: 1px solid {{ $borderColor }}; {{ $bestStyle }}"
                                 x-data
                                 x-tooltip.raw="Value: ${{ number_format($offer['offer_value'], 2) }}&#10;Resource: #{{ $offer['prospective_resource_id'] ?? '-' }}"
                                 class="cursor-help">
                                <div style="padding: 12px;">
                                    <!-- Header with time period -->
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center;">
                                            <span style="margin-right: 4px;">{{ $periodIcon }}</span>
                                            <span style="font-size: 0.875rem; font-weight: 600; color: white;">{{ $period }}</span>
                                        </div>

                                        @if ($isBest)
                                            <div style="padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; background-color: #065f46; color: white;">
                                                🏆 Best
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Time slot -->
                                    <div style="font-size: 1rem; font-weight: 700; color: white; margin-bottom: 4px;">
                                        {{ $offer['window_start_time'] ?? '' }} → {{ $offer['window_end_time'] ?? '' }}
                                    </div>
                                    <!-- Book Now Button -->
                                    <div style="display: flex; justify-content: flex-end; margin-top: 12px;">
                                        <button
                                            type="button"
                                            style="padding: 6px 12px; font-size: 0.75rem; font-weight: 600; border-radius: 4px; background-color: #3b82f6; color: white; border: none; cursor: pointer;"
                                            @click="alert('Booking offer ID: {{ $offer['id'] }}')"
                                        >
                                            Book Now
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
