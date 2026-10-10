{{--
    Each file is uploaded straight to R2: ask the app for a presigned PUT url (slot), PUT the file
    there with an XHR so progress is real, then tell the app it arrived (complete). No request in
    this flow carries a file body to the app.
--}}
<div class="grid gap-4 {{ count($kinds) > 1 ? 'md:grid-cols-2' : '' }}">
    @foreach ($kinds as $kindValue => $kindLabel)
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
            }"
            class="space-y-3"
        >
            <label
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; pick($event.dataTransfer.files)"
                x-bind:class="dragging ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-gray-300 dark:border-white/20'"
                class="flex flex-col items-center justify-center gap-1 px-6 py-6 text-center transition border-2 border-dashed cursor-pointer rounded-xl hover:border-primary-500"
            >
                <x-filament::icon icon="heroicon-o-arrow-up-tray" class="w-8 h-8 text-gray-400" />

                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    Drop a {{ $kindLabel }} export here, or click to choose it
                </span>

                <span class="text-xs text-gray-500 dark:text-gray-400">
                    .{{ implode(', .', $extensionsByKind[$kindValue]) }} files up to {{ $maxMegabytes }} MB. Deleted from storage {{ $ttlHours }} hours after upload.
                </span>

                <input
                    x-ref="input"
                    type="file"
                    multiple
                    class="hidden"
                    x-bind:accept="extensions.map((extension) => '.' + extension).join(',')"
                    x-on:change="pick($event.target.files)"
                />
            </label>

            <template x-for="upload in uploads" x-bind:key="upload.id">
                <div class="px-3 py-2 text-sm rounded-lg ring-1 ring-gray-950/10 dark:ring-white/20">
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate" x-text="upload.name"></span>
                        <button type="button" class="text-xs text-gray-500 hover:text-gray-700" x-show="upload.error" x-on:click="remove(upload.id)">Dismiss</button>
                    </div>
                    <p class="mt-1 text-xs text-danger-600" x-show="upload.error" x-text="upload.error"></p>
                    <div class="h-1.5 mt-2 overflow-hidden rounded bg-gray-200 dark:bg-white/10" x-show="! upload.error">
                        <div class="h-full bg-primary-500" x-bind:style="'width: ' + upload.percent + '%'"></div>
                    </div>
                </div>
            </template>
        </div>
    @endforeach
</div>
