<div class="h-80 w-full rounded-lg overflow-hidden border border-gray-200">
    @php
        $lat = $getRecord()->location['pso']['start']['latitude'] ?? '43.83475';
        $lng = $getRecord()->location['pso']['start']['longitude'] ?? '-79.32055';
        $address = $getRecord()->location['pso']['start']['address_line1'] ?? '';
        $city = $getRecord()->location['pso']['start']['city'] ?? '';
        $province = $getRecord()->location['pso']['start']['province'] ?? '';
        $postalCode = $getRecord()->location['pso']['start']['postal_code'] ?? '';
    @endphp

    <div class="w-full h-full" id="map"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize the map
            const map = new google.maps.Map(document.getElementById('map'), {
                center: { lat: {{ $lat }}, lng: {{ $lng }} },
                zoom: 14,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                mapTypeControl: true,
                streetViewControl: true,
                fullscreenControl: true,
            });

            // Add a marker for the location
            const marker = new google.maps.Marker({
                position: { lat: {{ $lat }}, lng: {{ $lng }} },
                map: map,
                title: '{{ addslashes($getRecord()->personal['full_name'] ?? 'Location') }}',
                animation: google.maps.Animation.DROP
            });

            // Add an info window
            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div class="p-2">
                        <h3 class="font-medium">{{ addslashes($getRecord()->personal['full_name'] ?? 'Technician') }}</h3>
                        <p>{{ addslashes($address) }}, {{ addslashes($city) }}</p>
                        <p>{{ addslashes($province) }}, {{ addslashes($postalCode) }}</p>
                    </div>
                `
            });

            // Open info window when marker is clicked
            marker.addListener('click', function() {
                infoWindow.open(map, marker);
            });

            // Initially open the info window
            infoWindow.open(map, marker);
        });
    </script>
</div>

<div class="mt-2 p-3 bg-gray-50 rounded-lg text-sm">
    <div class="font-medium text-gray-700">Address Information</div>
    <div class="mt-1 text-gray-600">
        {{ $getRecord()->location['pso']['start']['address_line1'] ?? '' }}<br>
        {{ $getRecord()->location['pso']['start']['city'] ?? '' }}, {{ $getRecord()->location['pso']['start']['province'] ?? '' }} {{ $getRecord()->location['pso']['start']['postal_code'] ?? '' }}
    </div>
</div>
