<?php

namespace App\Filament\Resources\DatasetReferenceValueResource\Pages;

use App\Filament\Resources\DatasetReferenceValueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

abstract class ListDatasetReferenceValues extends ListRecords
{
    protected function getHeaderActions(): array
    {
        /** @var class-string<DatasetReferenceValueResource> $resource */
        $resource = static::$resource;

        return [
            CreateAction::make()
                ->slideOver()
                ->mutateDataUsing(static fn (array $data): array => $resource::completeNewRow($data, $resource::entityType())),
        ];
    }
}
