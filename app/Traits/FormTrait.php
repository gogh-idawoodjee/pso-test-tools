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

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Novadaemon\FilamentPrettyJson\Form\PrettyJsonField;

trait FormTrait
{

    use PSOInteractionsTrait;

    public ?Collection $environments = null;
    public ?array $environment_data = [];
    public ?array $json_form_data = [];
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
                        ->afterStateUpdated(function ($livewire, $component, Set $set, ?string $state) {
                            $livewire->validateOnly($component->getStatePath());
                            $this->setCurrentEnvironment($state);
                            $set('dataset_id', null); // Clear old dataset
                        })
                        ->live(),
                    Select::make('dataset_id')
                        ->label('Dataset')
                        ->prefixIcon('heroicon-o-cube-transparent')
                        ->options(fn(Get $get) => $this->getDatasetOptions($get))
                        ->required(!$this->isDataSetRequired)
                        ->hidden($this->isDataSetHidden)
                        ->disabled(static fn(Get $get) => blank($get('environment_id')))
                        ->live()
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
            'datasetId' => $this->selectedDataset,
            'baseUrl' => $this->selectedEnvironment?->getAttribute('base_url'),
            'sendToPso' => data_get($this->environment_data, 'send_to_pso'),
            'accountId' => $this->selectedEnvironment?->getAttribute('account_id'),
        ];

        return [
            'dataset_id' => $this->selectedDataset,
            'base_url' => $this->selectedEnvironment?->getAttribute('base_url'),
            'send_to_pso' => data_get($this->environment_data, 'send_to_pso'),
            'account_id' => $this->selectedEnvironment?->getAttribute('account_id'),
        ];

    }

    public function prepareTokenizedPayload($send_to_pso, $payload)
    {

        $token = $send_to_pso ? $this->authenticatePSO(
            $this->selectedEnvironment->getAttribute('base_url'),
            $this->selectedEnvironment->getAttribute('account_id'),
            $this->selectedEnvironment->getAttribute('username'),
            Crypt::decryptString($this->selectedEnvironment->getAttribute('password'))
        ) : null;


        if ($send_to_pso && !$token) {

            $this->notifyPayloadSent('Send to PSO Failed', 'Please see the event log (when it is actually completed)', false);
            return false;
        }

        if ($token) {

            $payload = Arr::add($payload, 'environment.token', $token);

        }

        return $payload; // will either return a payload or false

    }

    public function environmentHeaderAction(): void
    {
        // used for overriding in any child

    }

    protected function json_form(Form $form): Form
    {
        return $form
            ->schema([
                PrettyJsonField::make('json_response_pretty')
                    ->label('Response from Services')
                    ->copyable()
                    ->copyMessage('Your JSON is copied to the clipboard')
                    ->copyMessageDuration(1500),

            ])->statePath('json_form_data');
    }

}
