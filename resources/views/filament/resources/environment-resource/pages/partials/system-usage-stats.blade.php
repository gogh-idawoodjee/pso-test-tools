@if (is_null($groups))
    <div class="text-sm text-gray-500 dark:text-gray-400">
        Click "Get System Usage" to fetch the latest usage data for this environment.
    </div>
@elseif (empty($groups))
    <div class="text-sm text-gray-500 dark:text-gray-400">
        No usage data was returned for that range.
    </div>
@else
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        @foreach ($groups as $group)
            @php
                $type = $group['type'];
                $icon = $type?->getIcon() ?? 'heroicon-o-question-mark-circle';
                $label = $type?->getLabel() ?? 'Unknown Usage Type';
                $unit = $type?->getUnit();
                $datetime = $group['latestDatetime']
                    ? \Carbon\Carbon::parse($group['latestDatetime'])->format('M j, Y g:ia')
                    : null;
            @endphp
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex flex-col items-center text-center">
                    <x-dynamic-component :component="$icon" class="w-8 h-8 text-primary-500 mb-2" />
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                        {{ $group['latestValue'] }}
                        @if ($unit)
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">{{ $unit }}</span>
                        @endif
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-300">{{ $label }}</div>
                    @if ($datetime)
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            as of {{ $datetime }}
                            @if ($group['readingCount'] > 1)
                                &middot; {{ $group['readingCount'] }} readings in range
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
