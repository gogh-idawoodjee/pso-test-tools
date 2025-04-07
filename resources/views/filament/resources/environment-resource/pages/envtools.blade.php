<x-filament-panels::page>


    {{ $this->psoload }}

    <x-filament::modal id="show-json" slide-over width="5xl">
        <pre>
<x-torchlight-code language='json'>
    {!! $this->response !!}


    </x-torchlight-code>
    </pre>
    </x-filament::modal>


</x-filament-panels::page>


