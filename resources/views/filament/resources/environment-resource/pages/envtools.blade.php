<x-filament-panels::page>


    {{ $this->psoload }}

    <pre>
        <x-torchlight-code language='json'>
    {!! $this->response !!}
        </x-torchlight-code>
    </pre>


</x-filament-panels::page>
