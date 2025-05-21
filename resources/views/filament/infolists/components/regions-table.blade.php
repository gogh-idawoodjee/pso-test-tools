@php
    $regions = $getRecord()->regions ?? [];
    // Remove the total element if it exists
    $regions = array_filter($regions, function($region) {
        return !isset($region['total']);
    });
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm text-left text-gray-700">
        <thead class="text-xs text-gray-600 uppercase bg-gray-100">
        <tr>
            <th scope="col" class="px-4 py-3 rounded-tl-lg">ID</th>
            <th scope="col" class="px-4 py-3 rounded-tr-lg">Description</th>
        </tr>
        </thead>
        <tbody>
        @forelse($regions as $region)
            <tr class="bg-white border-b hover:bg-gray-50">
                <td class="px-4 py-2 font-medium text-gray-900">
                    {{ $region['id'] ?? '' }}
                </td>
                <td class="px-4 py-2">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            {{ $region['description'] ?? '' }}
                        </span>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="2" class="px-4 py-3 text-center text-gray-500">
                    No regions found
                </td>
            </tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td colspan="2" class="px-4 py-2 text-right text-xs font-semibold">
                Total Regions: {{ count($regions) }}
            </td>
        </tr>
        </tfoot>
    </table>
</div>
