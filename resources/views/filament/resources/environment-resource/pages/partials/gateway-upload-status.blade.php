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

            @if ($upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                <p class="text-success-600">✅ Success — Internal ID: {{ $upload->internal_id }}</p>
            @elseif ($upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                <p class="text-danger-600">❌ Failed — {{ $upload->error_message }}</p>
            @else
                <p>🟡 {{ $upload->status->getLabel() }}…</p>
            @endif
        </div>
    @endif

    @if ($history->isNotEmpty())
        <div>
            <p class="mb-2 font-medium">Recent uploads</p>
            <ul class="space-y-1 text-sm">
                @foreach ($history as $item)
                    <li>
                        {{ $item->original_filename }} —
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
