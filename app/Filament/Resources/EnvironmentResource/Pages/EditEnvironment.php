<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use App\Models\Environment;
use Filament\Actions;


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

    public function getContentTabIcon(): ?string
    {
        return 'heroicon-o-circle-stack';
    }


    protected function getHeaderActions(): array
    {
        return [
//            Actions\ViewAction::make()->label('Tools'),
            Actions\Action::make('tools')
                ->icon('heroicon-o-wrench-screwdriver')
                ->url(function (Environment $record) {
                    return route('environments.tools', compact('record'));
                }),
            Actions\DeleteAction::make(),
        ];
    }


}
