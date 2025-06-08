<?php

namespace App\Filament\Pages;

use App\Classes\PSOObjectRegistry;
use App\Enums\HttpMethod;
use App\Models\Environment;
use App\Traits\FormTrait;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;

use Override;

class GenericDelete extends Page
{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationIcon = 'heroicon-o-trash';
    protected static ?string $activeNavigationIcon = 'heroicon-o-trash';

    protected static string $view = 'filament.pages.generic-delete';
    protected static ?string $navigationGroup = 'API Services';

    public ?array $deletion_data = [];
    public ?array $PSOObjectTypes = [];
    public ?array $selectedPSOObject = null;

    #[Override]
    protected function getForms(): array
    {
        return ['deletion_form', 'env_form', 'json_form'];
    }

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();

        $this->PSOObjectTypes = PSOObjectRegistry::forSelect();

        //pre-filling
        $this->deletion_data = collect(range(1, 4))->mapWithKeys(static function ($index) {
            return ["object_pk{$index}" => null];
        })->toArray();

        $this->fillForms($this->getForms());

    }

    public function deletion_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        Select::make('object_type_id')
                            ->label('PSO Object')
                            ->options($this->PSOObjectTypes)
                            ->live()
                            ->afterStateUpdated(fn(Get $get) => $this->setSelectedObject($get('object_type_id'))),

                        Fieldset::make('deletion_details')
                            ->label(fn(Get $get) => ($this->selectedPSOObject['label'] . ' Deletion Details' ?? ''))
                            ->visible(fn() => filled($this->selectedPSOObject))
                            ->schema(fn() => $this->getPkInputFields()),
                        Forms\Components\Actions::make([Forms\Components\Actions\Action::make('delete_object')
                            ->label('Delete Object')
                            ->icon('heroicon-o-trash')
                            ->action(function () {
                                $this->delete_object();
                            })->visible(fn() => filled($this->selectedPSOObject))
                        ])
                    ]),

            ])
            ->statePath('deletion_data');
    }

    public function setSelectedObject(?string $objectKey): void
    {
        if (!$objectKey) {
            $this->selectedPSOObject = null;
            return;
        }

        $this->selectedPSOObject = PSOObjectRegistry::get($objectKey);
    }

    protected function getPkInputFields(): array
    {

        if (!$this->selectedPSOObject || empty($this->selectedPSOObject['attributes'])) {
            return [];
        }

        return collect($this->selectedPSOObject['attributes'])->map(function ($attribute, $index) {
            $key = "object_pk" . ($index + 1);
            $label = Str::of($attribute['name'])->replace('_', ' ')->title()
                ->replace('Id', 'ID')->replace('Sla', 'SLA');

            return match ($attribute['type']) {
                'boolean' => Toggle::make($key)
                    ->label($label)
                    ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                    ->inline(false),
                'int', 'integer' => TextInput::make($key)
                    ->label($label)
                    ->required()
                    ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath()))
                    ->numeric()
                    ->step(1)
                    ->minValue(0),
                default =>
                TextInput::make($key)
                    ->label($label)
                    ->required()
                    ->afterStateUpdated(static fn($livewire, $component) => $livewire->validateOnly($component->getStatePath())),
            };
        })->toArray();

    }

    protected function getPkValueAsString(int $index): string
    {
        $value = $this->deletion_data["object_pk{$index}"] ?? null;

        return empty($value) ? 'false' : (string)$value;
    }


    /**
     * @throws JsonException
     */
    public function delete_object(): void
    {

        $this->response = null;
        $this->validateForms($this->getForms());

        $objectAttributes = collect($this->selectedPSOObject['attributes'])
            ->mapWithKeys(function ($attribute, $index) {
                $i = $index + 1;

                return [
                    'objectPkName' . $i => $attribute['name'],
                    'objectPk' . $i => $this->getPkValueAsString($i),
                ];
            })
            ->toArray();

        $payload = $this->buildPayload(
            required: array_merge(
                $objectAttributes,
                [
                    'objectType' => $this->selectedPSOObject['entity'],
                ]
            )
        );

        Log::info(json_encode($payload, JSON_PRETTY_PRINT));

//        dd($payload);

//        $payload = array_merge(collect($this->selectedPSOObject['attributes'])->mapWithKeys(function ($attribute, $index) {
//            return [
//                'object_pk_name' . ($index + 1) => $attribute['name'],
//                'object_pk' . ($index + 1) => $this->getPkValueAsString($index + 1)
//            ];
//        })->toArray(),
//            $this->environnment_payload_data(), ['object_type' => $this->selectedPSOObject['entity']]
//        );


        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSO('delete', $tokenized_payload, HttpMethod::DELETE);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated'); // Add this line
            $this->dispatch('open-modal', id: 'show-json');
        }

    }

}
