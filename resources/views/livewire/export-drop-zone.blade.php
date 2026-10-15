{{--
    Each file is uploaded straight to R2: ask the app for a presigned PUT url (slot), PUT the file
    there with an XHR so progress is real, then tell the app it arrived (complete). No request in
    this flow carries a file body to the app.
--}}
@php
    $hint = static function (array $extensions) use ($maxMegabytes, $ttlHours): string {
        $dotted = array_map(static fn (string $extension): string => '.'.$extension, $extensions);
        $last = array_pop($dotted);
        $list = $dotted === [] ? $last : implode(', ', $dotted).' or '.$last;

        return "{$list}, up to {$maxMegabytes} MB. Deleted from storage {$ttlHours} hours after upload.";
    };
@endphp
<div class="grid gap-4 {{ ! $compact && count($targets) > 1 ? 'md:grid-cols-2' : '' }}">
    @foreach ($targets as $kindValue => $kindLabel)
        <div
            wire:key="export-drop-{{ $kindValue }}"
            x-data="{
                dragging: false,
                uploads: [],
                nextUploadId: 0,
                kind: @js($kindValue),
                extensions: @js($extensionsByKind[$kindValue]),
                maxBytes: @js($maxMegabytes * 1024 * 1024),
                maxMegabytes: @js($maxMegabytes),
                csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',

                async pick(fileList) {
                    for (const file of Array.from(fileList)) {
                        const problem = this.problemWith(file)

                        if (problem) {
                            this.uploads.push({ id: ++this.nextUploadId, name: file.name, percent: 0, error: problem })

                            continue
                        }

                        await this.send(file)
                    }

                    if (this.$refs.input) {
                        this.$refs.input.value = ''
                    }
                },

                problemWith(file) {
                    const extension = file.name.split('.').pop().toLowerCase()

                    if (! this.extensions.includes(extension)) {
                        return file.name + ': please choose a .' + this.extensions.join(', .') + ' file.'
                    }

                    if (file.size > this.maxBytes) {
                        return file.name + ': the file is larger than ' + this.maxMegabytes + ' MB.'
                    }

                    return null
                },

                async send(file) {
                    // Alpine hands array items back as reactive proxies: look the item up by id and change
                    // that one, or progress never updates and a finished upload cannot be removed.
                    const id = ++this.nextUploadId
                    this.uploads.push({ id, name: file.name, percent: 0, error: null })
                    const upload = this.uploads.find((item) => item.id === id)

                    const slotResponse = await fetch(@js(route('filament.app.exports.slots.store')), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                        body: JSON.stringify({ scope_type: @js($scopeType), scope_id: @js($scope->getKey()), kind: this.kind, file_name: file.name, file_size: file.size }),
                    })
                    const slot = await slotResponse.json().catch(() => ({}))

                    if (! slotResponse.ok) {
                        const errors = slot.errors ? Object.values(slot.errors).flat().join(' ') : null
                        upload.error = file.name + ': ' + (errors ?? slot.message ?? 'the upload could not start.')

                        return
                    }

                    const stored = await new Promise((resolve) => {
                        const request = new XMLHttpRequest()
                        request.open('PUT', slot.upload.url)
                        Object.entries(slot.upload.headers ?? {}).forEach(([name, value]) => request.setRequestHeader(name, value))
                        request.upload.onprogress = (event) => {
                            if (event.lengthComputable) {
                                upload.percent = Math.round((100 * event.loaded) / event.total)
                            }
                        }
                        request.onload = () => resolve(request.status >= 200 && request.status < 300)
                        request.onerror = () => resolve(false)
                        request.send(file)
                    })

                    if (! stored) {
                        upload.error = file.name + ': the upload failed. Check your connection and try again.'

                        return
                    }

                    const done = await fetch(@js(url('exports')) + '/' + slot.id + '/complete', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    })

                    if (! done.ok) {
                        const body = await done.json().catch(() => ({}))
                        upload.error = file.name + ': ' + (body.message ?? 'the upload did not complete.')

                        return
                    }

                    this.remove(id)
                    $wire.dispatch('export-uploaded')
                },

                remove(id) {
                    this.uploads = this.uploads.filter((item) => item.id !== id)
                },

                // Display only: one upload turns the target itself into its progress bar; several stack below.
                single() {
                    return this.uploads.length === 1 ? this.uploads[0] : null
                },

                state() {
                    if (this.dragging) {
                        return 'drag'
                    }

                    return this.single()?.error ? 'error' : (this.single() ? 'busy' : 'idle')
                },
            }"
            class="space-y-2"
        >
            <label
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; pick($event.dataTransfer.files)"
                x-bind:data-state="state()"
                data-state="idle"
                @if ($compact) title="{{ $hint($extensionsByKind[$kindValue]) }}" @endif
                @class([
                    'relative flex items-center gap-x-2 overflow-hidden rounded-lg bg-white px-3 text-sm ring-1 ring-gray-950/10 cursor-pointer transition duration-75 motion-reduce:transition-none',
                    'hover:ring-gray-950/20 focus-within:ring-2 focus-within:ring-primary-500 focus-within:hover:ring-primary-500',
                    'dark:bg-white/5 dark:ring-white/20 dark:hover:ring-white/30 dark:focus-within:ring-primary-500 dark:focus-within:hover:ring-primary-500',
                    'data-[state=drag]:ring-2 data-[state=drag]:ring-primary-500 data-[state=drag]:hover:ring-primary-500 dark:data-[state=drag]:ring-primary-500 dark:data-[state=drag]:hover:ring-primary-500 data-[state=drag]:bg-primary-50 dark:data-[state=drag]:bg-primary-500/10',
                    'data-[state=error]:ring-danger-500 data-[state=error]:hover:ring-danger-500 dark:data-[state=error]:ring-danger-500 dark:data-[state=error]:hover:ring-danger-500',
                    'min-h-9 py-1.5' => $compact,
                    'min-h-14 py-2.5' => ! $compact,
                ])
            >
                {{-- The target fills as the progress bar while a single upload runs. --}}
                <div
                    aria-hidden="true"
                    x-show="single() && ! single().error"
                    x-cloak
                    class="absolute inset-y-0 left-0 bg-primary-500/15 transition-[width] duration-200 motion-reduce:transition-none"
                    x-bind:style="'width: ' + (single()?.percent ?? 0) + '%'"
                ></div>

                <x-filament::icon
                    icon="heroicon-m-arrow-up-tray"
                    class="relative size-4 shrink-0 text-gray-400 dark:text-gray-500"
                    x-bind:class="state() === 'error' ? 'text-danger-500 dark:text-danger-400' : ''"
                />

                <span class="relative min-w-0 flex-1">
                    <span x-show="! single()" class="block">
                        <span class="block truncate text-gray-950 dark:text-white">Drop a new {{ $kindLabel }} here or <span class="font-medium text-primary-600 dark:text-primary-400">browse</span></span>
                        @unless ($compact)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hint($extensionsByKind[$kindValue]) }}</span>
                        @endunless
                    </span>

                    <template x-if="single() && ! single().error">
                        <span class="flex items-center justify-between gap-x-3 text-gray-950 dark:text-white">
                            <span class="truncate" x-text="'Uploading ' + single().name"></span>
                            <span class="shrink-0 text-gray-500 tabular-nums dark:text-gray-400" x-text="single().percent + '%'"></span>
                        </span>
                    </template>

                    <template x-if="single()?.error">
                        <span class="flex items-start justify-between gap-x-3">
                            <span class="text-danger-600 dark:text-danger-400" role="alert" x-text="single().error"></span>
                            <button type="button" class="shrink-0 font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" x-on:click.prevent="remove(single().id)">Dismiss</button>
                        </span>
                    </template>
                </span>

                <input
                    x-ref="input"
                    type="file"
                    multiple
                    class="sr-only"
                    x-bind:accept="extensions.map((extension) => '.' + extension).join(',')"
                    x-on:change="pick($event.target.files)"
                />
            </label>

            <div class="space-y-2" x-show="uploads.length > 1" x-cloak>
                <template x-for="upload in uploads" x-bind:key="upload.id">
                    <div
                        class="relative overflow-hidden rounded-lg bg-white px-3 py-1.5 text-sm ring-1 dark:bg-white/5"
                        x-bind:class="upload.error ? 'ring-danger-500' : 'ring-gray-950/10 dark:ring-white/20'"
                    >
                        <div
                            aria-hidden="true"
                            x-show="! upload.error"
                            class="absolute inset-y-0 left-0 bg-primary-500/15 transition-[width] duration-200 motion-reduce:transition-none"
                            x-bind:style="'width: ' + upload.percent + '%'"
                        ></div>
                        <div class="relative flex items-start justify-between gap-x-3">
                            <span class="min-w-0 truncate text-gray-950 dark:text-white" x-show="! upload.error" x-text="upload.name"></span>
                            <span class="shrink-0 text-gray-500 tabular-nums dark:text-gray-400" x-show="! upload.error" x-text="upload.percent + '%'"></span>
                            <span class="text-danger-600 dark:text-danger-400" role="alert" x-show="upload.error" x-text="upload.error"></span>
                            <button type="button" class="shrink-0 font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" x-show="upload.error" x-on:click="remove(upload.id)">Dismiss</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    @endforeach
</div>
