<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\Environment;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Illuminate\Support\Collection;
use Filament\Forms;
use Filament\Forms\Form;

class ViewCustomer extends ViewRecord
{
    use InteractsWithRecord;

    protected static string $resource = CustomerResource::class;
    protected static string $view = 'filament.resources.customers.pages.view-customer';

    public Collection $environments;

    public function mount(int|string $record): void
    {
        $this->environments = Environment::all();

        $this->record = $this->resolveRecord($record);
    }

    public function Abe()
    {
        return [
            Forms\Components\Select::make('status')
                ->options([
                    'draft' => 'Draft',
                    'reviewing' => 'Reviewing',
                    'published' => 'Published',
                ])
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
