<x-filament::page>
    <form
        wire:submit.prevent="submit">
        {{ $this->form }}

        <x-filament::button
            type="submit"
            class="mt-6"
            x-bind:disabled="{{ json_encode($this->processing) }}"
        >
            <template x-if="{{ json_encode($this->processing) }}">
                <span class="flex items-center">
                    <svg class="animate-spin h-5 w-5 mr-2 text-white" xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                    </svg>
                    Processing...
                </span>
            </template>
            <template x-if="{{ json_encode(!$this->processing) }}">
                <span>Do the Thing</span>
            </template>
        </x-filament::button>
    </form>
</x-filament::page>
