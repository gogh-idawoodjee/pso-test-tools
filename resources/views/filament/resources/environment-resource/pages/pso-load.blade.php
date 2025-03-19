<x-filament-panels::page
    @class([
            'fi-resource-view-record-page',
            'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
//            'fi-resource-record-' . $record->getKey(),
        ])>
    is this the right page?
    {{ $this->form }}


    {{$data['dse_duration']}}
</x-filament-panels::page>
