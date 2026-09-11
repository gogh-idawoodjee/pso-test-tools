@php
    /** @var \App\Models\PsoGatewayUpload|null $upload */
    /** @var \Illuminate\Support\Collection $history */
@endphp

<div
    @if ($upload && ! $upload->status->isTerminal())
        wire:poll.3s
    @endif
    class="space-y-4"
>
    @if ($upload)
        <div class="rounded-lg border p-4">
            <p class="font-medium">{{ $upload->original_filename }}</p>

            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div
                    class="h-2 rounded-full transition-all duration-500 ease-out {{ match (true) {
                        $upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED => 'bg-danger-500',
                        $upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED => 'bg-success-500',
                        default => 'bg-primary-500',
                    } }}"
                    style="width: {{ $upload->status->progressPercent() }}%"
                ></div>
            </div>

            @if ($upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                <p class="mt-2 text-success-600">✅ Success — Internal ID: {{ $upload->internal_id }}</p>
            @elseif ($upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                <p class="mt-2 text-danger-600">❌ Failed — {{ $upload->error_message }}</p>
            @else
                <p class="mt-2">🟡 {{ $upload->status->getLabel() }}…</p>
            @endif

            @if ($upload->dataset_id || $upload->input_reference_datetime || $upload->activity_count || $upload->resource_count)
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-4">
                    @if ($upload->dataset_id)
                        <div>
                            <dt class="font-medium">Dataset</dt>
                            <dd>{{ $upload->dataset_id }}</dd>
                        </div>
                    @endif
                    @if ($upload->input_reference_datetime)
                        <div>
                            <dt class="font-medium">Input Reference</dt>
                            <dd>{{ $upload->input_reference_datetime->format('Y-m-d H:i') }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="font-medium">Activities</dt>
                        <dd>{{ number_format($upload->activity_count ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Resources</dt>
                        <dd>{{ number_format($upload->resource_count ?? 0) }}</dd>
                    </div>
                </dl>
            @endif
        </div>
    @endif

    @if ($history->isNotEmpty())
        <div>
            <p class="mb-2 font-medium">Recent uploads</p>
            <ul class="space-y-1 text-sm">
                @foreach ($history as $item)
                    <li>
                        {{ $item->original_filename }}
                        @if ($item->dataset_id)
                            ({{ $item->dataset_id }})
                        @endif
                        —
                        {{ $item->status->getLabel() }}
                        @if ($item->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                            (ID: {{ $item->internal_id }})
                        @elseif ($item->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                            ({{ $item->error_message }})
                        @endif
                        — {{ $item->created_at->diffForHumans() }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
