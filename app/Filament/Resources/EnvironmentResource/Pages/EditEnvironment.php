<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use Filament\Actions;

use Filament\Pages\Actions\Action;
use Filament\Resources\Pages\EditRecord;


class EditEnvironment extends EditRecord
{
    protected static string $resource = EnvironmentResource::class;
    protected static ?string $title = 'Manage Environment';
    protected static ?string $breadcrumb = 'Manage Environment';


    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        // overriding the parent method
        return 'Environment';
    }


    protected function getHeaderActions(): array
    {
        return [
//            Actions\ViewAction::make()->label('Tools'),
            Actions\Action::make('tools')
                ->url('/psoload/' . $this->record->id),
            Actions\DeleteAction::make(),
        ];
    }


}
