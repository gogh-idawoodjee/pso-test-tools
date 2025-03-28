<x-filament-panels::page>
    <x-filament::breadcrumbs :breadcrumbs="[
    '/' => 'Home',
    '/activity-services' => 'Activity Services',
    '/activity-delete' => 'Delete Activities',
]"/>
{{$this->env_form}}
{{$this->activity_form}}

    <pre>
<x-torchlight-code language='json'>
    {!! $this->response !!}


    </x-torchlight-code>
    </pre>
</x-filament-panels::page>
