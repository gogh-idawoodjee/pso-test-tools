<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use App\Models\Environment;
use App\Traits\PSOInteractionsTrait;
use Filament\Actions;


use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Crypt;


class EditEnvironment extends EditRecord
{

    use PSOInteractionsTrait;

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
                    return EnvironmentResource::getUrl('environmentTools', compact('record'));
                }),
            Actions\Action::make('test_configuration')
                ->icon('heroicon-o-check')
                ->action(function (Environment $record) {
                    $this->authenticatePSO($record->base_url, $record->account_id, $record->username, Crypt::decryptString($record->password));
                    $message = 'Configuration valid';
                    if ($this->error_value === 401) {
                        $message = 'Check Credentials';
                    }
                    if ($this->error_value === 500) {
                        $message = 'Check Configuration';
                    }
                    $this->notifyPayloadSent(!$this->error_value ? "Looks Good" : "Something Looks Off", $message, !$this->error_value);
                }),
//                ->url(function (Environment $record) {
//                    return EnvironmentResource::getUrl('environmentTools', compact('record'));
//                }),
            Actions\DeleteAction::make(),
        ];
    }


}
