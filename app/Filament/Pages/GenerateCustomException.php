<?php

namespace App\Filament\Pages;

use App\Classes\PSOObjectRegistry;


use App\Enums\PSOEntities;
use App\Models\Environment;
use App\Traits\FormTrait;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Pages\Page;


use JsonException;
use Override;

class GenerateCustomException extends Page

{
    use InteractsWithForms, FormTrait;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $activeNavigationIcon = 'heroicon-s-exclamation-triangle';

    protected static string $view = 'filament.pages.generate-custom-exception';
    protected static ?string $navigationGroup = 'Additional Tools';


    public ?array $exception_data = [];
    public ?array $PSOObjectTypes = [];


    #[Override]
    protected function getForms(): array
    {
        return ['exception_form', 'env_form'];
    }

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
        $this->PSOObjectTypes = PSOObjectRegistry::findByEntities(PSOEntities::eventEntities());
//dd($this->PSOObjectTypes);
        $this->exception_form->fill();
        $this->env_form->fill();
    }


    public function exception_form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        Select::make('object_type_id')
                            ->label('Exception Generated For:')
                            ->options($this->PSOObjectTypes)
                            ->required()
                            ->reactive(), // Use reactive for live updates

                        // Resource ID field visible when 'object_type_id' is 'Resource'
                        TextInput::make('resource_id')
                            ->label('Resource ID')
                            ->visible(fn(callable $get) => $get('object_type_id') === 'resource')
                            ->required(fn(callable $get) => $get('object_type_id') === 'resource')
                            ->placeholder('Enter Resource ID'),

                        // Activity ID field visible when 'object_type_id' is 'Activity'
                        TextInput::make('activity_id')
                            ->label('Activity ID')
                            ->visible(fn(callable $get) => $get('object_type_id') === 'activity')
                            ->required(fn(callable $get) => $get('object_type_id') === 'activity')
                            ->placeholder('Enter Activity ID'),

                        // Generic Label and Value fields
                        TextInput::make('label')
                            ->required(),
                        TextInput::make('value')
                            ->required(),
                        Actions::make([Actions\Action::make('generate_exception')
                            ->label('Generate Exception')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->action(function () {
                                $this->generate_exception();
                            })->disabled(static fn(callable $get) => !$get('object_type_id'))
                        ])->columnSpan(2)
                    ])
                    ->columns()
            ])
            ->statePath('exception_data');

    }

    /**
     * @throws JsonException
     */
    public function generate_exception(): void
    {

        $this->response = null;
        $this->validateForms($this->getForms());

        $object_id = $this->exception_data['object_type_id'];
        $object_value = $this->exception_data['object_value'] === 'activity' ? $this->exception_data['activity_id'] : $this->exception_data['resource_id'];


        $payload = array_merge([
            $object_id => $object_value,
            'label' => $this->exception_data['label'],
            'value' => $this->exception_data['value'],
        ],
            $this->environnment_payload_data()
        );


        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSO('exception', $tokenized_payload);

            $this->dispatch('open-modal', id: 'show-json');
        }

    }

}
