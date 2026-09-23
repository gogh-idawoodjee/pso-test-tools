<?php

namespace App\Filament\Resources\EnvironmentResource\Pages;

use App\Filament\Resources\EnvironmentResource;
use App\Models\Environment;
use App\Support\PsoEnvironmentsExporter;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEnvironments extends ListRecords
{
    protected static string $resource = EnvironmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('show_json')
                ->label('Show as JSON')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->slideOver()
                ->modalWidth(Width::TwoExtraLarge)
                ->modalHeading('Environments as JSON')
                ->modalDescription('Connection info only — passwords are not included.')
                ->modalContent(static fn (): View => view('filament.resources.environment.environments-json', [
                    'json' => app(PsoEnvironmentsExporter::class)->toJson(Environment::query()->orderBy('name')->get()),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Actions\Action::make('export_psd1')
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(static fn (): StreamedResponse => response()->streamDownload(
                    static function (): void {
                        echo app(PsoEnvironmentsExporter::class)->toPsd1(Environment::query()->orderBy('name')->get());
                    },
                    PsoEnvironmentsExporter::FILENAME,
                    ['Content-Type' => 'text/plain; charset=UTF-8'],
                )),
            Actions\CreateAction::make(),
        ];
    }
}
