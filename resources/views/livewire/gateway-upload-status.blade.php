@php
    /** @var \App\Models\PsoGatewayUpload|null $upload */
    /** @var list<\App\Support\GatewayUpload\RunLogNode> $timeline */
    /** @var bool $keepPolling */
@endphp

{{-- Polls only this panel, never the page: re-rendering the whole page every two seconds scrolled the window back to the top. --}}
<div
    @if ($keepPolling)
        wire:poll.2s="poll"
    @endif
>
    @if ($upload)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
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

            @php
                $succeeded = $upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED;
                $failed = $upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED;
                $detailClasses = [
                    'sub' => 'is-sub',
                    'warn' => 'is-warn',
                    'error' => 'is-error',
                    'ok' => 'is-ok',
                ];
            @endphp

            @unless ($succeeded || $failed)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $upload->status->getLabel() }}...</p>
            @endunless

            @if (count($timeline) > 0 || $succeeded || $failed)
                <div class="mt-3">
                    <p class="text-sm font-medium">Run log</p>
                    {{-- wire:ignore.self keeps the height the user dragged it to across polls; the list inside still updates. --}}
                    <div
                        wire:key="gateway-upload-log-{{ $upload->id }}"
                        wire:ignore.self
                        class="pso-timeline mt-1"
                        x-data="{ stick: true }"
                        x-init="
                            $el.scrollTop = $el.scrollHeight;
                            new MutationObserver(() => { if (stick) { $el.scrollTop = $el.scrollHeight } })
                                .observe($el, { childList: true, subtree: true });
                        "
                        x-on:scroll="stick = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 24"
                        x-on:pointerdown="
                            const box = $el.getBoundingClientRect();
                            if ($event.clientX > box.right - 24 && $event.clientY > box.bottom - 24) {
                                $el.style.height = $el.offsetHeight + 'px';
                                $el.style.maxHeight = 'none';
                            }
                        "
                    >
                        <ol class="pso-timeline-list">
                            @foreach ($timeline as $index => $node)
                                <li class="pso-timeline-node is-{{ $node->state() }}" wire:key="gateway-upload-node-{{ $upload->id }}-{{ $index }}">
                                    <span class="pso-timeline-marker" aria-hidden="true">
                                        @if ($node->state() === 'ok')
                                            <svg viewBox="0 0 16 16" fill="none"><path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        @endif
                                    </span>
                                    <div class="pso-timeline-head">
                                        <p class="pso-timeline-title">{{ $node->title }}</p>
                                        @if ($node->at)
                                            <time class="pso-timeline-time">{{ $node->at }}</time>
                                        @endif
                                    </div>
                                    @if (count($node->details) > 0)
                                        <ul class="pso-timeline-details">
                                            @foreach ($node->details as $detail)
                                                <li class="pso-timeline-detail {{ $detailClasses[$detail['level']] ?? 'is-sub' }}">{{ $detail['text'] }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach

                            @if ($succeeded)
                                <li class="pso-timeline-node is-ok is-terminal" wire:key="gateway-upload-node-{{ $upload->id }}-end">
                                    <span class="pso-timeline-marker" aria-hidden="true">
                                        <svg viewBox="0 0 16 16" fill="none"><path d="M3.5 8.5l3 3 6-7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </span>
                                    <div class="pso-timeline-head">
                                        <p class="pso-timeline-title">Succeeded, internal ID <span class="pso-timeline-id">{{ $upload->internal_id }}</span></p>
                                        @if ($upload->completed_at)
                                            <time class="pso-timeline-time">{{ $upload->completed_at->format('H:i:s') }}</time>
                                        @endif
                                    </div>
                                </li>
                            @elseif ($failed)
                                <li class="pso-timeline-node is-error is-terminal" wire:key="gateway-upload-node-{{ $upload->id }}-end">
                                    <span class="pso-timeline-marker" aria-hidden="true">
                                        <svg viewBox="0 0 16 16" fill="none"><path d="M5 5l6 6M11 5l-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" /></svg>
                                    </span>
                                    <div class="pso-timeline-head">
                                        <p class="pso-timeline-title">Failed: {{ $upload->error_message }}</p>
                                        @if ($upload->completed_at)
                                            <time class="pso-timeline-time">{{ $upload->completed_at->format('H:i:s') }}</time>
                                        @endif
                                    </div>
                                </li>
                            @endif
                        </ol>
                    </div>
                </div>
            @endif

            @php
                // `dataset_id_in_file` is the file's own value; `dataset_id` is read from what was sent.
                $datasetInFile = $upload->dataset_id_in_file ?? ($upload->dataset_id_override ? null : $upload->dataset_id);
                $datasetSent = $upload->dataset_id_override ? ($upload->dataset_id ?? $upload->dataset_id_override) : null;
            @endphp
            @if ($datasetInFile || $datasetSent || $upload->input_reference_datetime || $upload->activity_count || $upload->resource_count)
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-4">
                    @if ($datasetInFile)
                        <div>
                            <dt class="font-medium">Dataset in the file</dt>
                            <dd>{{ $datasetInFile }}</dd>
                        </div>
                    @endif
                    @if ($datasetSent)
                        <div>
                            <dt class="font-medium">Dataset ID sent</dt>
                            <dd>{{ $datasetSent }}</dd>
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

            @if ($upload->webhook_url)
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <dt class="font-medium">Webhook endpoint</dt>
                        <dd>
                            <code class="break-all">{{ $upload->webhook_url }}</code>
                            <button
                                type="button"
                                class="ml-2 text-primary-600 underline dark:text-primary-400"
                                x-data="{ copied: false }"
                                x-on:click="navigator.clipboard.writeText(@js($upload->webhook_url)); copied = true; setTimeout(() => copied = false, 1500)"
                                x-text="copied ? 'Copied' : 'Copy'"
                            >Copy</button>
                        </dd>
                    </div>
                    @if ($upload->webhook_view_url)
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="font-medium">Dashboard</dt>
                            <dd>
                                <a href="{{ $upload->webhook_view_url }}" target="_blank" rel="noopener noreferrer" class="break-all text-primary-600 underline dark:text-primary-400">{{ $upload->webhook_view_url }}</a>
                            </dd>
                        </div>
                    @endif
                </dl>
            @elseif ($upload->skip_webhook)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Webhook broadcast skipped: the file was sent as it is.</p>
            @endif

            @if ($upload->response_status !== null)
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-4">
                    <div>
                        <dt class="font-medium">HTTP status</dt>
                        <dd>{{ $upload->response_status }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Wire size</dt>
                        <dd>{{ number_format(($upload->wire_bytes ?? 0) / 1048576, 2) }} MB</dd>
                    </div>
                    <div>
                        <dt class="font-medium">POST duration</dt>
                        <dd>{{ number_format(($upload->post_duration_ms ?? 0) / 1000, 2) }} s</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Effective rate</dt>
                        <dd>
                            @if (($upload->post_duration_ms ?? 0) > 0)
                                {{ number_format((($upload->wire_bytes ?? 0) / 1048576) / ($upload->post_duration_ms / 1000), 2) }} MB/s
                            @else
                                n/a
                            @endif
                        </dd>
                    </div>
                </dl>

                @if (filled($upload->response_body))
                    <div class="mt-3">
                        <p class="text-sm font-medium">PSO response</p>
                        <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap break-all rounded bg-gray-100 p-2 text-xs dark:bg-gray-800">{{ $upload->response_body }}</pre>
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
