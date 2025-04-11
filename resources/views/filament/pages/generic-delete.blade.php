<x-filament-panels::page>

    {{$this->env_form}}
    {{$this->deletion_form}}


    <x-filament::modal id="show-json" slide-over width="5xl">
        {{$this->json_form}}
    </x-filament::modal>

</x-filament-panels::page>
