<x-filament-panels::page>
    {{$this->env_form}}

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            {{$this->infolist}}
        </div>
        <div>
            {{$this->taskForm}}
        </div>
    </div>
</x-filament-panels::page>
