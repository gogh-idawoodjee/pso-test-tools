{{-- The finished plan travels runs here, newest first. Only a run checked with Google can go in a report. --}}
<div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
            <tr>
                <th class="px-3 py-2 font-medium">Dates</th>
                <th class="px-3 py-2 font-medium">Made</th>
                <th class="px-3 py-2 font-medium text-right">Legs</th>
                <th class="px-3 py-2 font-medium text-right">Flagged</th>
                <th class="px-3 py-2 font-medium">Google</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
            @foreach ($rows as $row)
                <tr @class(['text-gray-400 dark:text-gray-500' => ! $row['google'], 'text-gray-900 dark:text-gray-100' => $row['google']])>
                    <td class="px-3 py-2">{{ $row['range'] }}</td>
                    <td class="px-3 py-2">{{ $row['made'] }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($row['legs']) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($row['flagged']) }}</td>
                    <td class="px-3 py-2">{{ $row['google'] ? 'Yes' : 'Run plan travels again with Google on' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
