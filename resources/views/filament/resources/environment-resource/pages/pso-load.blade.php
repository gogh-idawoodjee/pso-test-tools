<x-filament-panels::page
    @class([
            'fi-resource-view-record-page',
            'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
//            'fi-resource-record-' . $record->getKey(),
        ])>

    {{ $this->psoload }}


</x-filament-panels::page>
