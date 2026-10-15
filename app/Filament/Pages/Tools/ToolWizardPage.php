<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Enums\ToolRunStatus;
use App\Filament\Navigation\Groups;
use App\Livewire\ExportDropZone;
use App\Models\Dataset;
use App\Models\Environment;
use App\Models\ExportFile;
use App\Models\ToolRun;
use App\Support\Exports\RunInputPicker;
use App\Support\Tools\StartToolRun;
use App\Support\Tools\UnusableInputException;
use App\Tools\ToolRegistry;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Override;
use UnitEnum;

/**
 * One guided page per tool: Where (environment, dataset), Inputs (exports),
 * Options (when the tool has any), Run. Subclasses only name their tool.
 * Hidden from navigation until the tool has an implementation registered.
 */
abstract class ToolWizardPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = Groups::ANALYSE;

    protected string $view = 'filament.pages.tools.tool-wizard';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?string $runId = null;

    /** @var list<string> kinds the user emptied on purpose; never refilled with a default */
    public array $clearedKinds = [];

    /** @var array<string, list<string>> kind value => usable file ids at the last refresh */
    public array $knownFiles = [];

    private const WIZARD_KEY = 'tool-wizard';

    abstract public static function tool(): ToolId;

    public function mount(): void
    {
        // Filament's own canAccess check runs after this mount, too late to stop the form asking for a missing implementation.
        abort_unless(static::canAccess(), 403);

        $remembered = auth()->user()->last_tool_context ?? [];
        $environments = Environment::query()->orderBy('name')->get(['id']);
        $environmentId = in_array($remembered['environment_id'] ?? null, $environments->pluck('id')->all(), true)
            ? $remembered['environment_id']
            : ($environments->count() === 1 ? $environments->first()->id : null);

        $datasetId = null;
        if ($this->isDatasetScoped() && $environmentId !== null) {
            $datasets = Dataset::query()->ownedByCurrentUser()->where('environment_id', $environmentId)->get(['id']);
            $datasetId = in_array($remembered['dataset_id'] ?? null, $datasets->pluck('id')->all(), true)
                ? $remembered['dataset_id']
                : ($datasets->count() === 1 ? $datasets->first()->id : null);
        }

        // fill() with no state applies the fields' own defaults (an option's default value); the scope and files go on top.
        $this->form->fill();
        $this->data = [...$this->data ?? [], 'environment_id' => $environmentId, 'dataset_id' => $datasetId, 'files' => $this->emptyFiles()];
        $this->refreshInputs();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make($this->steps())
                    ->key(self::WIZARD_KEY, isInheritable: false)
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit">Run {{ $label }}</x-filament::button>',
                        ['label' => static::tool()->getLabel()],
                    ))),
            ]);
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return array_values(array_filter(
            [$this->whereStep(), $this->inputsStep(), $this->optionsStep()],
            static fn (Step $step): bool => ! $step->isHidden(),
        ));
    }

    protected function optionsStep(): Step
    {
        $fields = app(ToolRegistry::class)->for(static::tool())->optionFields();

        return Step::make('Options')
            ->description('Settings for this run. Leave blank for the defaults.')
            ->schema($fields)
            ->hidden($fields === []);
    }

    protected function whereStep(): Step
    {
        return Step::make('Where')
            ->description($this->isDatasetScoped() ? 'The environment and dataset the exports belong to.' : 'The environment the exports belong to.')
            ->schema(array_values(array_filter([
                Select::make('environment_id')
                    ->label('Environment')
                    ->options(fn (): array => Environment::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(static function (Set $set, self $livewire): void {
                        $set('dataset_id', null);
                        $livewire->resetInputs();
                    })
                    ->createOptionForm(Environment::getForm())
                    // The form encrypts the password on dehydrate; the model's creating hook sets slug and commit token.
                    ->createOptionUsing(static fn (array $data): string => Environment::query()->create([...$data, 'user_id' => auth()->id()])->getKey()),
                $this->isDatasetScoped() ? Select::make('dataset_id')
                    ->label('Dataset')
                    ->options(fn (Get $get): array => $get('environment_id')
                        ? Dataset::query()->ownedByCurrentUser()->where('environment_id', $get('environment_id'))->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->required()
                    ->live()
                    ->native(false)
                    ->disabled(static fn (Get $get): bool => blank($get('environment_id')))
                    ->afterStateUpdated(static fn (self $livewire) => $livewire->resetInputs())
                    ->createOptionForm([
                        TextInput::make('name')->required(),
                        TextInput::make('rota')->required()->helperText('The PSO rota id for this dataset.'),
                    ])
                    ->createOptionUsing(static function (array $data, Get $get): string {
                        // Only into one of the user's own environments.
                        $environment = Environment::query()->findOrFail($get('environment_id'));

                        return Dataset::query()->create([...$data, 'environment_id' => $environment->getKey()])->getKey();
                    })
                    : null,
            ])));
    }

    protected function inputsStep(): Step
    {
        return Step::make('Inputs')
            ->description('The exports to run against. Reuse a file already uploaded or drop a new one.')
            ->schema(fn (): array => $this->inputFields());
    }

    /**
     * One row per kind the tool takes: the reuse select, and beside it a drop target that uploads
     * that kind to where it belongs (the dataset, or the environment for a SYS export).
     *
     * @return list<Component>
     */
    protected function inputFields(): array
    {
        if (! $this->hasScope()) {
            return [Text::make($this->isDatasetScoped() ? 'Choose the environment and dataset first.' : 'Choose the environment first.')->color('gray')];
        }
        $tool = app(ToolRegistry::class)->for(static::tool());
        $scope = $this->scopeModel();
        $options = app(RunInputPicker::class)->optionsWithExpiryFor($scope, static::tool());
        $pending = $this->pendingKinds();
        $rows = [];

        foreach ([...$tool->requiredKinds(), ...$tool->optionalKinds()] as $kind) {
            $required = in_array($kind, $tool->requiredKinds(), true);
            $kindOptions = $options[$kind->value] ?? [];
            $uploadScope = $this->uploadScopeFor($kind, $scope);
            $helperText = array_filter([
                in_array($kind, $pending, true) ? 'Reading the '.$kind->getLabel().' export you just uploaded. It is chosen here when ready.' : null,
                $uploadScope !== $scope ? $this->aExport($kind).' comes from the environment, not the dataset, because it describes the whole PSO system.' : null,
            ]);
            $rows[] = Grid::make(['default' => 1, 'md' => 3])->schema([
                Select::make("files.{$kind->value}")
                    ->label($kind->getLabel().' export')
                    ->hint($required ? 'Required' : 'Optional')
                    ->helperText($helperText === [] ? null : implode(' ', $helperText))
                    ->options($kindOptions)
                    ->getOptionLabelUsing(fn ($value): ?string => $kindOptions[$value] ?? null)
                    ->placeholder($kindOptions === [] ? 'No '.$kind->getLabel().' export uploaded yet' : 'Choose a file')
                    ->required($required)
                    ->validationMessages(['required' => $this->aExport($kind).' is required.'])
                    ->native(false)
                    ->afterStateUpdated(static fn (?string $state, self $livewire) => $livewire->rememberChoice($kind->value, $state))
                    ->columnSpan(['default' => 1, 'md' => 2]),
                // A new key per scope, so changing the dataset mounts a target for the new one.
                Livewire::make(ExportDropZone::class, ['scope' => $uploadScope, 'kinds' => [$kind], 'compact' => true])
                    ->key("drop-{$kind->value}-{$uploadScope->getKey()}")
                    // Lines the target up with the select, below its label.
                    ->extraAttributes(['class' => 'md:pt-8'])
                    ->columnSpan(['default' => 1, 'md' => 1]),
            ]);
        }

        return [
            Group::make($rows)->extraAttributes($pending === [] ? [] : ['wire:poll.5s' => 'refreshInputs']),
            Text::make('Files are kept for '.(int) config('exports.ttl_hours', 12).' hours, then the raw file is deleted. Results from runs that used it are kept.')->color('gray'),
        ];
    }

    /** Where an upload of this kind goes: the scope, or a dataset's environment for an environment kind. */
    private function uploadScopeFor(ExportKind $kind, Model $scope): Model
    {
        return $kind->allowedScope() === Environment::class && $scope instanceof Dataset ? $scope->environment : $scope;
    }

    /** "A LOAD export", "An End of day export". */
    private function aExport(ExportKind $kind): string
    {
        $label = $kind->getLabel();

        return (in_array(strtolower($label[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'An ' : 'A ').$label.' export';
    }

    protected function hasScope(): bool
    {
        return filled($this->data['environment_id'] ?? null) && (! $this->isDatasetScoped() || filled($this->data['dataset_id'] ?? null));
    }

    /** @return array<string, null> one empty entry per kind the tool takes */
    protected function emptyFiles(): array
    {
        return array_fill_keys(
            array_map(static fn (ExportKind $kind): string => $kind->value, app(RunInputPicker::class)->kindsOf(static::tool())),
            null,
        );
    }

    /** The scope changed: drop the old choices and preselect the new scope's files. */
    public function resetInputs(): void
    {
        $this->data['files'] = $this->emptyFiles();
        $this->clearedKinds = [];
        $this->knownFiles = [];
        $this->refreshInputs();
    }

    /** A user who empties a field keeps it empty; picking a file again lifts that. */
    public function rememberChoice(string $kind, ?string $fileId): void
    {
        $this->clearedKinds = blank($fileId)
            ? array_values(array_unique([...$this->clearedKinds, $kind]))
            : array_values(array_diff($this->clearedKinds, [$kind]));
    }

    /**
     * Select the newest file when it arrived since the last refresh (the upload the user just made),
     * then fill the remaining empty fields the user has not cleared with the picker's
     * default (the newest set that belongs together).
     */
    #[On('export-uploaded')]
    public function refreshInputs(): void
    {
        if (! $this->hasScope()) {
            return;
        }
        $picker = app(RunInputPicker::class);
        $scope = $this->scopeModel();
        $files = array_map(static fn (array $options): array => array_map('strval', array_keys($options)), $picker->optionsFor($scope, static::tool()));

        foreach ($files as $kind => $ids) {
            // Only a file that is new since the last refresh; an older one surfacing because the newest expired does not count.
            $newest = $ids[0] ?? null;
            if ($newest !== null && array_key_exists($kind, $this->knownFiles) && ! in_array($newest, $this->knownFiles[$kind], true)) {
                $this->data['files'][$kind] = $newest;
                $this->clearedKinds = array_values(array_diff($this->clearedKinds, [$kind]));
            }
        }
        $this->knownFiles = $files;

        foreach ($picker->defaultsFor($scope, static::tool()) as $kind => $fileId) {
            if (blank($this->data['files'][$kind] ?? null) && ! in_array($kind, $this->clearedKinds, true)) {
                $this->data['files'][$kind] = $fileId;
            }
        }
    }

    public function hasPendingUploads(): bool
    {
        return $this->pendingKinds() !== [];
    }

    /** @return list<ExportKind> the tool's kinds with an upload still arriving or being read, in either scope */
    protected function pendingKinds(): array
    {
        if (! $this->hasScope()) {
            return [];
        }
        $scope = $this->scopeModel();

        return array_values(array_filter(app(RunInputPicker::class)->kindsOf(static::tool()), function (ExportKind $kind) use ($scope): bool {
            $uploadScope = $this->uploadScopeFor($kind, $scope);

            return ExportFile::query()->where('scope_type', $uploadScope->getMorphClass())->where('scope_id', $uploadScope->getKey())
                ->where('kind', $kind)
                ->whereIn('status', [ExportFileStatus::Uploading, ExportFileStatus::Summarising])->exists();
        }));
    }

    protected function isDatasetScoped(): bool
    {
        return static::tool()->scope() === Dataset::class;
    }

    /** The chosen scope, or 404 when it is not one of the user's own. */
    protected function scopeModel(): Model
    {
        $environment = Environment::query()->findOrFail($this->data['environment_id'] ?? null);
        if (! $this->isDatasetScoped()) {
            return $environment;
        }

        return Dataset::query()->ownedByCurrentUser()->where('environment_id', $environment->id)->findOrFail($this->data['dataset_id'] ?? null);
    }

    public function start(): void
    {
        $scope = $this->scopeModel();

        try {
            // A file that expired since the form opened is no longer an option, so say so before the form's own validation does.
            app(StartToolRun::class)->assertUsable($scope, static::tool(), $this->data['files'] ?? []);
            $state = $this->form->getState();
            $run = app(StartToolRun::class)->start($scope, static::tool(), $state['files'] ?? [], $state['options'] ?? [], auth()->user());
        } catch (UnusableInputException $e) {
            // Empty on purpose, so the default does not put the same unusable file back.
            $this->data['files'][$e->kind->value] = null;
            $this->rememberChoice($e->kind->value, null);
            $this->refreshInputs();
            Notification::make()->title('The run did not start')->body($e->getMessage())->danger()->send();
            // The emptied field is on the Inputs step; take the user there rather than leave them on a later step.
            $this->dispatch('go-to-wizard-step', ...$this->inputsStepTarget());

            return;
        }

        $this->runId = $run->id;
    }

    /** @return array{key: string, step: string} the payload the wizard's go-to-wizard-step listener expects */
    private function inputsStepTarget(): array
    {
        /** @var Wizard $wizard */
        $wizard = $this->form->getComponent(self::WIZARD_KEY);
        $inputs = $wizard->getChildSchema()->getComponent(static fn ($component): bool => $component instanceof Step && $component->getLabel() === 'Inputs');

        return ['key' => $wizard->getKey(), 'step' => $inputs->getKey()];
    }

    public function currentRun(): ?ToolRun
    {
        return $this->runId === null ? null : ToolRun::query()->findOrFail($this->runId);
    }

    public function isRunning(): bool
    {
        $run = $this->currentRun();

        return $run !== null && in_array($run->status, [ToolRunStatus::Queued, ToolRunStatus::Running], true);
    }

    /** @return Collection<string, ExportFile|null> */
    public function inputFiles(): Collection
    {
        $named = $this->currentRun()?->inputs['files'] ?? [];
        $files = ExportFile::query()->whereKey(array_values($named))->get()->keyBy('id');

        return collect($named)->map(static fn (string $fileId): ?ExportFile => $files->get($fileId));
    }

    public function resultView(): string
    {
        $specific = 'filament.tool-runs.'.$this->currentRun()?->tool->value;

        return view()->exists($specific) ? $specific : 'filament.tool-runs._generic';
    }

    /** Back to the form with the same choices. */
    public function runAgain(): void
    {
        $this->runId = null;
    }

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return app(ToolRegistry::class)->has(static::tool());
    }

    /** An unregistered tool's URL is refused rather than crashing on its missing implementation. */
    #[Override]
    public static function canAccess(): bool
    {
        return app(ToolRegistry::class)->has(static::tool());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return static::tool()->getLabel();
    }

    #[Override]
    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::tool()->icon();
    }

    #[Override]
    public static function getSlug(?Panel $panel = null): string
    {
        return 'tools/'.str_replace('_', '-', static::tool()->value);
    }

    #[Override]
    public function getTitle(): string
    {
        return static::tool()->getLabel();
    }

    #[Override]
    public function getSubheading(): ?string
    {
        return static::tool()->question();
    }
}
