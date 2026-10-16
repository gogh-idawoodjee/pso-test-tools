@php
    $isSys = $kind === 'sys';
    $maxFiles = (int) config('sys-compare.max_files');
    $maxKilobytes = (int) config('sys-compare.max_file_kilobytes');
    $maxMegabytes = (int) round($maxKilobytes / 1024);
    $extension = $isSys ? 'xml' : 'csv';
@endphp

{{--
    Posts each file to our own upload endpoint (private local disk), not Livewire's temporary
    uploads (shared R2 bucket): sys files contain customer data. Only the returned id is handed
    to the Livewire component.
--}}
<div
    x-data="{
        dragging: false,
        uploads: [],
        nextUploadId: 0,
        kind: @js($kind),
        url: @js(route('filament.app.sys-compare.uploads.store')),
        extension: @js($extension),
        maxBytes: @js($maxKilobytes * 1024),
        maxMegabytes: @js($maxMegabytes),
        maxFiles: @js($maxFiles),

        fileCount() {
            return Object.keys($wire.formData?.environments ?? {}).length
        },

        inFlight() {
            return this.uploads.filter((upload) => ! upload.error).length
        },

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
            if (! file.name.toLowerCase().endsWith('.' + this.extension)) {
                return file.name + ': please choose a .' + this.extension + ' file.'
            }

            if (file.size > this.maxBytes) {
                return file.name + ': the file is larger than ' + this.maxMegabytes + ' MB.'
            }

            if (this.kind === 'sys' && this.fileCount() + this.inFlight() >= this.maxFiles) {
                return file.name + ': you can compare up to ' + this.maxFiles + ' files at a time.'
            }

            return null
        },

        send(file) {
            return new Promise((resolve) => {
                // Alpine hands array items back as reactive proxies, so the plain object pushed here is
                // not the item in the list: look the item up by id and change that one, or the progress
                // never updates and a finished upload can never be taken out of the list again.
                const id = ++this.nextUploadId
                this.uploads.push({ id, name: file.name, percent: 0, error: null })
                const upload = this.uploads.find((item) => item.id === id)

                const body = new FormData()
                body.append('file', file)
                body.append('kind', this.kind)

                const request = new XMLHttpRequest()
                request.open('POST', this.url)
                request.setRequestHeader('Accept', 'application/json')
                request.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]')?.content ?? '')

                request.upload.onprogress = (event) => {
                    if (event.lengthComputable) {
                        upload.percent = Math.round((100 * event.loaded) / event.total)
                    }
                }

                request.onload = async () => {
                    let response = {}

                    try {
                        response = JSON.parse(request.responseText)
                    } catch (error) {
                        response = {}
                    }

                    if (request.status === 201 && response.id) {
                        await $wire.addUploadedFile(this.kind, response.id, response.name ?? file.name)
                        this.remove(id)
                    } else if (request.status === 413) {
                        upload.error = file.name + ': the file is larger than the server allows.'
                    } else {
                        upload.error = response.message ?? file.name + ': the upload failed.'
                    }

                    resolve()
                }

                request.onerror = () => {
                    upload.error = file.name + ': the upload failed. Check your connection and try again.'
                    resolve()
                }

                request.send(body)
            })
        },

        remove(id) {
            this.uploads = this.uploads.filter((item) => item.id !== id)
        },

        // Display only: the target's ring follows the drag and the newest upload's error.
        state() {
            if (this.dragging) {
                return 'drag'
            }

            return this.uploads.at(-1)?.error ? 'error' : 'idle'
        },
    }"
    class="space-y-3"
>
    <label
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="dragging = false; pick($event.dataTransfer.files)"
        x-bind:data-state="state()"
        data-state="idle"
        @class([
            'pso-dropzone relative flex min-h-14 items-center gap-x-2 rounded-lg bg-white px-3 py-2.5 text-sm ring-1 ring-gray-950/10 cursor-pointer transition duration-75 motion-reduce:transition-none',
            'hover:ring-gray-950/20 focus-within:ring-2 focus-within:ring-primary-500 focus-within:hover:ring-primary-500',
            'dark:bg-white/5 dark:ring-white/20 dark:hover:ring-white/30 dark:focus-within:ring-primary-500 dark:focus-within:hover:ring-primary-500',
            'data-[state=drag]:ring-2 data-[state=drag]:ring-primary-500 data-[state=drag]:hover:ring-primary-500 dark:data-[state=drag]:ring-primary-500 dark:data-[state=drag]:hover:ring-primary-500 data-[state=drag]:bg-primary-50 dark:data-[state=drag]:bg-primary-500/10',
            'data-[state=error]:ring-danger-500 data-[state=error]:hover:ring-danger-500 dark:data-[state=error]:ring-danger-500 dark:data-[state=error]:hover:ring-danger-500',
        ])
    >
        <x-filament::icon icon="heroicon-m-arrow-up-tray" class="size-4 shrink-0 text-gray-400 dark:text-gray-500" />

        <span class="min-w-0 flex-1">
            <span class="block text-gray-950 dark:text-white">
                @if ($isSys)
                    Drop PSO system data exports here or <span class="pso-browse font-medium text-primary-600 dark:text-primary-400">browse</span>
                @else
                    Drop a ParamDefinitions.csv here or <span class="pso-browse font-medium text-primary-600 dark:text-primary-400">browse</span>
                @endif
            </span>

            <span class="block text-xs text-gray-500 dark:text-gray-400">
                @if ($isSys)
                    .xml files, up to {{ $maxFiles }} files and {{ $maxMegabytes }} MB each. They are kept on a private disk and deleted as soon as the comparison finishes.
                @else
                    A .csv file with the columns Parameter, Definition, Note and Basis.
                @endif
            </span>
        </span>

        <input
            x-ref="input"
            type="file"
            class="sr-only"
            accept=".{{ $extension }}"
            @if ($isSys) multiple @endif
            x-on:change="pick($event.target.files)"
        />
    </label>

    @unless ($isSys)
        <template x-if="$wire.formData?.definitions">
            <div class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm rounded-lg bg-white ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20">
                <span class="truncate text-gray-950 dark:text-white" x-text="$wire.formData.definitions.fileName"></span>
                <x-filament::button type="button" size="xs" color="danger" outlined class="shrink-0" x-on:click="$wire.removeDefinitions()">
                    Remove
                </x-filament::button>
            </div>
        </template>
    @endunless

    <ul class="space-y-2" x-show="uploads.length > 0" x-cloak>
        <template x-for="upload in uploads" x-bind:key="upload.id">
            <li class="text-sm">
                <template x-if="! upload.error">
                    <div class="relative overflow-hidden rounded-lg bg-white px-3 py-1.5 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20">
                        <div aria-hidden="true" class="absolute inset-y-0 left-0 bg-primary-500/15 transition-[width] duration-200 motion-reduce:transition-none" x-bind:style="'width:' + upload.percent + '%'"></div>
                        <div class="relative flex justify-between gap-3 text-gray-950 dark:text-white">
                            <span class="truncate" x-text="upload.name"></span>
                            <span class="shrink-0 text-gray-500 tabular-nums dark:text-gray-400" x-text="upload.percent + '%'"></span>
                        </div>
                    </div>
                </template>

                <template x-if="upload.error">
                    <div class="flex items-start justify-between gap-3 px-3 py-1.5 rounded-lg bg-white ring-1 ring-danger-500 dark:bg-white/5">
                        <span class="text-danger-600 dark:text-danger-400" role="alert" x-text="upload.error"></span>
                        <x-filament::button type="button" size="xs" color="gray" class="shrink-0" x-on:click="remove(upload.id)">
                            Dismiss
                        </x-filament::button>
                    </div>
                </template>
            </li>
        </template>
    </ul>
</div>
