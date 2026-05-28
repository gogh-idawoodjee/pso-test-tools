<?php

namespace App\Filament\Pages;

use App\Classes\PSOObjectRegistry;
use App\Enums\PSOEntities;
use App\Models\Environment;
use App\Traits\FormTrait;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use JsonException;
use UnitEnum;

class GenerateCustomException extends Page
{
    use FormTrait;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string|BackedEnum|null $activeNavigationIcon = 'heroicon-s-exclamation-triangle';

    protected string $view = 'filament.pages.generate-custom-exception';

    protected static string|UnitEnum|null $navigationGroup = 'API Services';

    public ?array $exception_data = [];

    public ?array $PSOObjectTypes = [];

    protected function getForms(): array
    {
        return ['exception_form', 'env_form'];
    }

    public function mount(): void
    {
        $this->environments = Environment::with('datasets')->get();
        $this->PSOObjectTypes = PSOObjectRegistry::findByEntities(PSOEntities::eventEntities());

        $this->fillForms($this->getForms());
    }

    public function exception_form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        Select::make('object_type_id')
                            ->label('Exception Generated For:')
                            ->options($this->PSOObjectTypes)
                            ->required()
                            ->native(false)
                            ->live(),

                        TextInput::make('schedule_exception_type_id')
                            ->label('Schedule Exception Type ID')
                            ->helperText('Please ensure this exists in PSO')
                            ->numeric()
                            ->integer()
                            ->required(),

                        TextInput::make('resourceId')
                            ->label('Resource ID')
                            ->visible(static fn (Get $get) => $get('object_type_id') === 'resource')
                            ->required(static fn (Get $get) => $get('object_type_id') === 'resource')
                            ->placeholder('Enter Resource ID'),

                        TextInput::make('activity_id')
                            ->label('Activity ID')
                            ->visible(static fn (Get $get) => $get('object_type_id') === 'activity')
                            ->required(static fn (Get $get) => $get('object_type_id') === 'activity')
                            ->placeholder('Enter Activity ID'),

                        TextInput::make('label')
                            ->required(),
                        TextInput::make('value')
                            ->required(),
                        Actions::make([Action::make('generate_exception')
                            ->label('Generate Exception')
                            ->icon('heroicon-o-exclamation-triangle')
                            ->action(function () {
                                $this->generate_exception();
                            })->disabled(static fn (Get $get) => ! $get('object_type_id')),
                        ])->columnSpan(2),
                    ])
                    ->columns(),
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
        $object_value = $this->exception_data['object_type_id'] === 'activity' ? $this->exception_data['activity_id'] : $this->exception_data['resource_id'];

        $payload = $this->buildPayload(
            required: [
                $object_id.'Id' => $object_value,
                'label' => $this->exception_data['label'],
                'value' => $this->exception_data['value'],
                'exceptionTypeId' => $this->exception_data['schedule_exception_type_id'],
            ],
        );

        if ($tokenized_payload = $this->prepareTokenizedPayload($this->environment_data['send_to_pso'], $payload)) {

            $this->response = $this->sendToPSONew('exception', $tokenized_payload);
            $this->json_form_data['json_response_pretty'] = $this->response;
            $this->dispatch('json-updated');
            $this->dispatch('open-modal', id: 'show-json');
        }

    }
}
