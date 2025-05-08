<?php

namespace App\Traits;

use App\Models\Dataset;
use App\Models\Environment;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Collection;


trait FormTrait
{

    use PSOInteractionsTrait;

    public ?Collection $environments = null;
    public ?array $environment_data = [];

    public ?Environment $selectedEnvironment;
    public ?string $selectedDataset = null;
    public mixed $response = null;
    public bool $isDataSetHidden = false;
    public bool $isDataSetRequired = false;
    public bool $isHeaderActionHidden = true;

    public bool $isAuthenticationRequired = false;

    public ?string $headerActionLabel = 'Header Action Label';


    public function validateForms(array $forms): void
    {
        foreach ($forms as $form) {
            $this->{$form}->getState();
        }
    }

    protected function fillForms($forms): void
    {
        foreach ($forms as $form) {
            $this->{$form}->fill();
        }
    }

    public function env_form(Form $form): Form
    {
        return $form
            ->schema($this->getEnvFormSchema())->statePath('environment_data');

    }

    public function getEnvFormSchema(): array
    {
        return [
            Section::make('Environment')
                ->headerActions([
                    Action::make($this->headerActionLabel)
                        ->action(fn() => $this->environmentHeaderAction())
                        ->hidden($this->isHeaderActionHidden),
                ])
                ->description($this->isAuthenticationRequired ? 'This function requires PSO Authentication. Send to PSO must be selected.' : null)
                ->icon('heroicon-s-circle-stack')
                ->schema([
                    Toggle::make('send_to_pso')
                        ->label('Send to PSO')
                        ->inline(false)
                        ->live()
                        ->default($this->isAuthenticationRequired)
                        ->disabled($this->isAuthenticationRequired),
                    Select::make('environment_id')
                        ->label('Environment')
                        ->prefixIcon('heroicon-o-globe-alt')
                        ->options($this->environments?->pluck('name', 'id')->toArray() ?? [])
                        ->required()
                        ->preload()
                        ->native(false)
                        ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                            $livewire->validateOnly($component->getStatePath());
                            $this->setCurrentEnvironment($state);
                            $set('dataset_id', null); // Clear old dataset
                        })
                        ->live(),
                    Select::make('dataset_id')
                        ->label('Dataset')
                        ->preload()
                        ->prefixIcon('heroicon-o-cube-transparent')
                        ->options(fn(Get $get) => $this->getDatasetOptions($get))
                        ->required(!$this->isDataSetRequired)
                        ->hidden($this->isDataSetHidden)
                        ->disabled(static fn(Get $get) => blank($get('environment_id')))
                        ->live()
                        ->native(false)
                        ->searchable()
                        ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                            $livewire->validateOnly($component->getStatePath());
                            $this->setCurrentDataset($state);
                        })
                        ->hint(static fn(Get $get) => blank($get('environment_id')) ? 'Please select an environment first.' : null)

                        // 1) Build the “new dataset” modal
                        ->createOptionForm([
                            Hidden::make('environment_id')
                                ->default(static fn(Get $get) => $get('environment_id')),

                            TextInput::make('name')
                                ->label('New Dataset Name')
                                ->required(),
                        ])
                        ->createOptionModalHeading('Create Dataset')

                        // 2) Persist it and return the new ID
                        ->createOptionUsing(function (Get $get, array $data): string {
                            $dataset = Dataset::create([
                                'environment_id' => $get('environment_id'),
                                'name' => $data['name'],
                                'rota' => $data['name'],
                            ]);

                            return $dataset->id;
                        })
                ])
                ->columns(3)
        ];
    }


    private function getDatasetOptions(Get $get): array
    {
        return $this->environments
            ->find($get('environment_id'))
            ?->datasets
            ?->pluck('name', 'name')
            ->toArray() ?? [];
    }

    private function setCurrentEnvironment($id): void
    {
        $this->selectedEnvironment = $this->environments->find($id);

    }

    private function setCurrentDataset($id): void
    {
        $this->selectedDataset = $id;

    }

    public function environnment_payload_data(): array
    {

        return [
            'environment' => [
                'datasetId' => $this->selectedDataset,
                'baseUrl' => $this->selectedEnvironment?->getAttribute('base_url'),
                'sendToPso' => data_get($this->environment_data, 'send_to_pso'),
                'accountId' => $this->selectedEnvironment?->getAttribute('account_id'),
            ]
        ];

//        return [
//            'dataset_id' => $this->selectedDataset,
//            'base_url' => $this->selectedEnvironment?->getAttribute('base_url'),
//            'send_to_pso' => data_get($this->environment_data, 'send_to_pso'),
//            'account_id' => $this->selectedEnvironment?->getAttribute('account_id'),
//        ];

    }

    protected function buildPayload(array $required, array $optional = [], array $extra = []): array
    {

        // this merges the extra data with the required and optional data for most sendToPso calls
        // formatted in this format:
        //        [
        //            'env' => [],
        //            'data' => []
        //         ]
        return array_merge(
            $this->environnment_payload_data(),
            $extra,
            [
                'data' => array_merge(
                    $required,
                    array_filter($optional, static fn($v) => $v !== null)
                )
            ]
        );
    }


    public function environmentHeaderAction(): void
    {
        // used for overriding in any child

    }


}
