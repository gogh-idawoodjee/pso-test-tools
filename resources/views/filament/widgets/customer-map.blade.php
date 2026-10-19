<x-filament-widgets::widget>
    @php($pins = $this->pins())
    <x-filament::section heading="Customer map" :description="count($pins) === 1 ? '1 customer with coordinates. Click a pin for the address and a link to the customer.' : number_format(count($pins)).' customers with coordinates. Click a pin for the address and a link to the customer.'">
        @if ($pins === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">No customers have coordinates yet. Add a latitude and longitude to a customer (or use the map icon next to its address) and it will appear here.</p>
        @else
            @once
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
                <script id="pso-leaflet-js" src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
                <style>
                    .pso-customer-map { position: relative; z-index: 0; isolation: isolate; height: 420px; width: 100%; border: 1px solid var(--pso-border); border-radius: 0.5rem; overflow: hidden; }
                    .pso-customer-map .leaflet-popup-content { color: var(--pso-text); }
                    .pso-customer-map .pso-pin-name { font-weight: 600; }
                    .pso-customer-map .pso-pin-line { color: var(--pso-text-muted); }
                    .pso-customer-map .pso-pin-link { display: inline-block; margin-top: 0.25rem; color: var(--pso-link); font-weight: 500; }
                </style>
                <script>
                    window.psoCustomerMap = function (el, pins) {
                        if (el._psoMap) {
                            return;
                        }
                        const start = function () {
                            if (el._psoMap || !window.L) {
                                return;
                            }
                            const map = L.map(el, { scrollWheelZoom: false });
                            el._psoMap = map;
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                maxZoom: 19,
                                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                            }).addTo(map);
                            const line = function (parent, cls, text) {
                                if (text) {
                                    L.DomUtil.create('div', cls, parent).textContent = text;
                                }
                            };
                            const markers = pins.map(function (pin) {
                                const box = L.DomUtil.create('div');
                                line(box, 'pso-pin-name', pin.name);
                                line(box, 'pso-pin-line', pin.address);
                                line(box, 'pso-pin-line', pin.city);
                                const link = L.DomUtil.create('a', 'pso-pin-link', box);
                                link.href = pin.url;
                                link.textContent = 'Open customer';
                                return L.marker([pin.lat, pin.long], { title: pin.name }).bindPopup(box);
                            });
                            const group = L.featureGroup(markers).addTo(map);
                            map.fitBounds(group.getBounds(), { padding: [24, 24], maxZoom: 13 });
                            if (window.ResizeObserver) {
                                new ResizeObserver(function () { map.invalidateSize(); }).observe(el);
                            }
                        };
                        if (window.L) {
                            start();
                            return;
                        }
                        let script = document.getElementById('pso-leaflet-js');
                        if (!script) {
                            script = document.createElement('script');
                            script.id = 'pso-leaflet-js';
                            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                            script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
                            script.crossOrigin = '';
                            document.head.appendChild(script);
                        }
                        script.addEventListener('load', start);
                    };
                </script>
            @endonce
            <div wire:ignore>
                <div class="pso-customer-map" role="region" aria-label="Map of customers" x-data x-init="psoCustomerMap($el, @js($pins))"></div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
