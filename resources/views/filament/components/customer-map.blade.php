@php
    $record = $getRecord(); // ✅ this gives you the Customer instance
    $lat = $record->lat ?? null;
    $long = $record->long ?? null;
    $mapUrl = $lat && $long ? "https://maps.google.com/maps?q={$lat},{$long}&z=14&output=embed" : null;
@endphp

@if ($lat && $long)
    <div class="mt-4 rounded-lg shadow border overflow-hidden">
        <iframe
            width="100%"
            height="250"
            style="border:0"
            src="{{ $mapUrl }}"
            allowfullscreen>
        </iframe>
    </div>
@else
    <p class="text-gray-500 text-sm">No coordinates available for this customer.</p>
@endif
