<x-filament-panels::page>
    <x-filament::breadcrumbs :breadcrumbs="[
    '/' => 'Home',
    '/activity-services' => 'Activity Services',
    '/activity-status' => 'Update Activity Status',
]"/>
    {{$this->env_form}}

    {{$this->activity_form}}


</x-filament-panels::page>
