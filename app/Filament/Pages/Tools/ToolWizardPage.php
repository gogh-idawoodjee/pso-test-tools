<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;
use App\Models\Dataset;
use App\Models\Environment;
use App\Tools\ToolRegistry;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
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

    protected static string|UnitEnum|null $navigationGroup = 'Analyse exports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected string $view = 'filament.pages.tools.tool-wizard';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $runId = null;

    abstract public static function tool(): ToolId;

    public function mount(): void
    {
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

        $this->form->fill(['environment_id' => $environmentId, 'dataset_id' => $datasetId, 'files' => [], 'options' => []]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make($this->steps())
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit">Run {{ $label }}</x-filament::button>',
                        ['label' => static::tool()->getLabel()],
                    ))),
            ]);
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [$this->whereStep()];
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
                    ->afterStateUpdated(static fn (Set $set) => $set('dataset_id', null))
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

    /** Task 6 starts the run here. */
    public function start(): void {}

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return app(ToolRegistry::class)->has(static::tool());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return static::tool()->getLabel();
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
