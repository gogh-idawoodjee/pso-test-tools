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
    }"
    class="space-y-3"
>
    <label
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="dragging = false; pick($event.dataTransfer.files)"
        x-bind:class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-300 dark:border-white/20'"
        class="flex flex-col items-center justify-center gap-1 px-6 py-8 text-center transition border-2 border-dashed cursor-pointer rounded-xl hover:border-primary-500"
    >
        <x-filament::icon icon="heroicon-o-arrow-up-tray" class="w-8 h-8 text-gray-400" />

        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
            @if ($isSys)
                Drop PSO system data exports here, or click to choose files
            @else
                Drop a ParamDefinitions.csv here, or click to choose it
            @endif
        </span>

        <span class="text-xs text-gray-500 dark:text-gray-400">
            @if ($isSys)
                .xml files, up to {{ $maxFiles }} files and {{ $maxMegabytes }} MB each. They are kept on a private disk and deleted as soon as the comparison finishes.
            @else
                A .csv file with the columns Parameter, Definition, Note and Basis.
            @endif
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
            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-white/5">
                <span x-text="$wire.formData.definitions.fileName"></span>
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
                    <div>
                        <div class="flex justify-between text-gray-700 dark:text-gray-300">
                            <span x-text="upload.name"></span>
                            <span x-text="upload.percent + '%'"></span>
                        </div>
                        <div class="h-1.5 mt-1 overflow-hidden bg-gray-200 rounded-full dark:bg-gray-700">
                            <div class="h-1.5 bg-primary-500" x-bind:style="'width:' + upload.percent + '%'"></div>
                        </div>
                    </div>
                </template>

                <template x-if="upload.error">
                    <div class="flex items-start justify-between gap-3 px-3 py-2 rounded-lg bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                        <span x-text="upload.error"></span>
                        <x-filament::button type="button" size="xs" color="danger" outlined class="shrink-0" x-on:click="remove(upload.id)">
                            Dismiss
                        </x-filament::button>
                    </div>
                </template>
            </li>
        </template>
    </ul>
</div>
