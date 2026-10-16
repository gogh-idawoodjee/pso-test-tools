@php
    /** @var \App\Models\PsoGatewayUpload|null $upload */
    /** @var \Illuminate\Support\Collection $history */
    /** @var array<int, array{level: string, text: string, at: string}> $logLines */
    /** @var bool $keepPolling */

    $levelClasses = [
        'step' => 'font-semibold text-gray-900 dark:text-gray-100',
        'sub' => 'pl-4 text-gray-600 dark:text-gray-400',
        'warn' => 'text-warning-600 dark:text-warning-400',
        'error' => 'font-semibold text-danger-600 dark:text-danger-400',
        'ok' => 'text-success-600 dark:text-success-400',
    ];
@endphp

<div
    @if ($keepPolling)
        wire:poll.2s="pollGatewayUploadLog"
    @endif
    class="space-y-4"
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

            @if ($upload->status === \App\Enums\PsoGatewayUploadStatus::SUCCEEDED)
                <p class="mt-2 text-success-600 dark:text-success-400">Succeeded. Internal ID: {{ $upload->internal_id }}</p>
            @elseif ($upload->status === \App\Enums\PsoGatewayUploadStatus::FAILED)
                <p class="mt-2 text-danger-600 dark:text-danger-400">Failed: {{ $upload->error_message }}</p>
            @else
                <p class="mt-2">{{ $upload->status->getLabel() }}...</p>
            @endif

            @if (count($logLines) > 0)
                <div class="mt-3">
                    <p class="text-sm font-medium">Run log</p>
                    <div
                        class="mt-1 max-h-96 overflow-auto rounded bg-gray-100 p-2 font-mono text-xs dark:bg-gray-800"
                        x-data="{ stick: true }"
                        x-init="
                            $el.scrollTop = $el.scrollHeight;
                            new MutationObserver(() => { if (stick) { $el.scrollTop = $el.scrollHeight } })
                                .observe($el, { childList: true, subtree: true });
                        "
                        x-on:scroll="stick = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 24"
                    >
                        @foreach ($logLines as $line)
                            <div class="{{ $levelClasses[$line['level']] ?? $levelClasses['sub'] }} whitespace-pre-wrap break-all">
                                <span class="text-gray-400">{{ $line['at'] }}</span> {{ $line['text'] }}
                            </div>
                        @endforeach
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
