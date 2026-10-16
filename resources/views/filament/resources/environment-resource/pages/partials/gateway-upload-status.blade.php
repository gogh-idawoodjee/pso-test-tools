@php
    /** @var string|null $uploadId */
    /** @var \Illuminate\Support\Collection $history */
@endphp

{{-- No wire:poll here: the live panel is its own component (App\Livewire\GatewayUploadStatus) and polls itself. --}}
<div class="space-y-4">
    @if ($uploadId)
        @livewire(\App\Livewire\GatewayUploadStatus::class, ['uploadId' => $uploadId], key('gateway-upload-'.$uploadId))
    @endif

    @if ($history->isNotEmpty())
        <div>
            <p class="mb-2 font-medium">Recent uploads</p>
            <ul class="space-y-1 text-sm">
                @foreach ($history as $item)
                    <li wire:key="gateway-upload-history-{{ $item->id }}">
                        <button
                            type="button"
                            class="text-left text-primary-600 underline dark:text-primary-400"
                            wire:click="viewGatewayUpload(@js($item->id))"
                        >{{ $item->original_filename }}</button>
                        @if ($item->dataset_id)
                            ({{ $item->dataset_id }})
                        @endif
                        &middot;
                        {{ $item->status->getLabel() }}
                        @if ($item->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                            (ID: {{ $item->internal_id }})
                        @elseif ($item->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                            ({{ $item->error_message }})
                        @endif
                        &middot; {{ $item->created_at->diffForHumans() }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
