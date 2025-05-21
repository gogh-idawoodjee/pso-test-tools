<div class="relative h-64 w-full overflow-hidden rounded-lg">
    @php
        $lat = $getRecord()->location['pso']['start']['latitude'] ?? '43.83475';
        $lng = $getRecord()->location['pso']['start']['longitude'] ?? '-79.32055';
        $name = $getRecord()->location['pso']['start']['name'] ?? 'Location';
        $mapUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$lat},{$lng}&zoom=13&size=400x300&maptype=roadmap&markers=color:red%7C{$lat},{$lng}&key=" . config('services.google_maps.key');
    @endphp

    <div class="absolute inset-0 bg-gray-100 rounded-lg flex items-center justify-center">
        <img src="{{ $mapUrl }}" alt="Location Map" class="w-full h-full object-cover rounded-lg" />
    </div>

    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-gray-900/80 to-transparent p-3 text-white">
        <div class="text-sm font-medium">{{ $name }}</div>
        <div class="text-xs">{{ $lat }}, {{ $lng }}</div>
    </div>
</div>
