<x-filament-panels::page>

    {{$this->env_form}}
    {{$this->deletion_form}}

    <x-filament::modal id="show-json" slide-over width="5xl">
        <pre>
<x-torchlight-code language='json'>
    {!! $this->response !!}


    </x-torchlight-code>
    </pre>
    </x-filament::modal>

</x-filament-panels::page>
