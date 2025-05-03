@php use Spatie\ShikiPhp\Shiki; @endphp
<x-filament-panels::page>


    {{$this->env_form}}
    {{$this->exception_form}}

    <x-filament::modal id="show-json" slide-over width="5xl">


        <x-code-block
            language="json"
            :code="$this->response"
            :highlightLines="[1, '4-6']"
        />

    </x-filament::modal>

</x-filament-panels::page>
