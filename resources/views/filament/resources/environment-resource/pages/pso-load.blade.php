<x-filament-panels::page
    @class([
            'fi-resource-view-record-page',
            'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
            'fi-resource-record-' . $record->getKey(),
        ])>

    {{ $this->psoload }}



    {{--    {{$this->response}}--}}

    <pre><x-torchlight-code language='json'>
        {{--                {!! json_encode($this->response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) !!}--}}
{!! ($this->response) !!}


    </x-torchlight-code></pre>
    <x-torchlight-code language='php'>

        echo "Hello World!";

    </x-torchlight-code>

</x-filament-panels::page>
