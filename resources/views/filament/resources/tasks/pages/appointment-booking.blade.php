<x-filament-panels::page>
    {{ $this->env_form }}

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            {{ $this->infolist }}
        </div>
        <div>
            {{ $this->taskForm }}
        </div>
    </div>

    @if ($response && filled(data_get($response, 'data.appointment_offers.valid_offers')))
        <div
            x-data="{
                expiresAt: null,
                countdown: '--:--',
                interval: null,
                hasSynced: false,

                startCountdown(expiry) {
                    this.expiresAt = new Date(expiry);
                    this.tick();
                    this.interval = setInterval(() => this.tick(), 1000);
                },

                tick() {
                    const now = new Date();
                    const diff = this.expiresAt - now;

                    if (diff <= 0) {
                        clearInterval(this.interval);
                        this.countdown = 'Expired';

                        if (!this.hasSynced) {
                            $wire.set('countdownExpired', true);
                            this.hasSynced = true;
                        }

                        return;
                    }

                    const minutes = Math.floor(diff / 60000);
                    const seconds = Math.floor((diff % 60000) / 1000);
                    this.countdown = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                }
            }"
            x-init="startCountdown('{{ $countdownExpiresAt }}')"
            class="text-red-600 text-lg font-semibold mb-4"
        >
            <div>
                ⏳ Offer expires in: <span x-text="countdown"></span>
            </div>
        </div>

        @if ($countdownExpired && $response)
            <p class="text-yellow-600">⏱ Offers have expired. Please search again.</p>
        @endif

        @php
            $offers = data_get($response, 'data.appointment_offers');
            $validOffers = collect(data_get($offers, 'valid_offers', []));
            $bestId = data_get($offers, 'best_offer.id');
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
                                $startTime = data_get($offer, 'window_start_time', '12:00 PM');
                                [$time, $ampm] = explode(' ', $startTime);
                                [$hour] = explode(':', $time);
                                $hour = (int) $hour;
                                $hour24 = ($ampm === 'PM' && $hour < 12) ? $hour + 12 : ($ampm === 'AM' && $hour === 12 ? 0 : $hour);

                                if ($hour24 < 12) {
                                    $period = 'Morning';
                                    $periodIcon = '☀️';
                                    $bgColor = '#0a3056';
                                    $borderColor = '#4299e1';
                                } elseif ($hour24 < 15) {
                                    $period = 'Midday';
                                    $periodIcon = '🌞';
                                    $bgColor = '#553c10';
                                    $borderColor = '#f59e0b';
                                } elseif ($hour24 < 18) {
                                    $period = 'Afternoon';
                                    $periodIcon = '🌤️';
                                    $bgColor = '#5a2917';
                                    $borderColor = '#f97316';
                                } else {
                                    $period = 'Evening';
                                    $periodIcon = '🌙';
                                    $bgColor = '#372965';
                                    $borderColor = '#8b5cf6';
                                }

                                $isBest = data_get($offer, 'id') === $bestId;
                                $bestStyle = $isBest ? 'border: 3px solid #10b981; box-shadow: 0 0 10px rgba(16, 185, 129, 0.4);' : '';
                            @endphp

                            <div
                                style="border-radius: 10px; overflow: hidden; margin-bottom: 8px; background-color: {{ $bgColor }}; border: 1px solid {{ $borderColor }}; {{ $bestStyle }}"
                                x-data
                                x-tooltip.raw="Offer Value: {{ number_format(data_get($offer, 'offer_value'), 2) }}&#10; - ResourceID: {{ data_get($offer, 'prospective_resource_id') }}"
                                class="cursor-help"
                            >
                                <div style="padding: 12px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                        <div style="display: flex; align-items: center;">
                                            <span style="margin-right: 4px;">{{ $periodIcon }}</span>
                                            <span style="font-size: 0.875rem; font-weight: 600; color: white;">{{ $period }}</span>
                                        </div>

                                        @if ($isBest)
                                            <div
                                                style="padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; background-color: #065f46; color: white;">
                                                🏆 Best
                                            </div>
                                        @endif
                                    </div>

                                    <div style="font-size: 1rem; font-weight: 700; color: white; margin-bottom: 4px;">
                                        {{ data_get($offer, 'window_start_time', '') }} → {{ data_get($offer, 'window_end_time', '') }}
                                    </div>

                                    <div style="display: flex; justify-content: flex-end; margin-top: 12px;">
                                        <button
                                            type="button"
                                            style="padding: 6px 12px; font-size: 0.75rem; font-weight: 600; border-radius: 4px; background-color: #3b82f6; color: white; border: none; cursor: pointer;"
                                            @click="alert('Booking offer ID: {{ data_get($offer, 'id') }}')"
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
