<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Livewire\ExportDropZone;
use App\Models\Dataset;
use App\Models\ExportFile;
use App\Support\Exports\RunInputPicker;
use App\Tools\TravelTuning\SourceRuns;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Override;

/**
 * Travel tuning: Where, Inputs (finished Plan travels runs of the scope, in order, each with an optional SYS export
 * for its polygons), Options. The inputs are runs, not files, so this page replaces the Inputs step and hands the
 * rows to StartToolRun, which checks them again.
 */
class TravelTuningEntry extends ToolWizardPage
{
    /** The options this page fills in from the scope. */
    private const array PREFILLED_OPTIONS = ['client', 'dataset', 'region_label'];

    /** @var array<string, string> option key => the value this page last filled in; a different value is the user's and is kept */
    #[Locked]
    public array $suggestedOptions = [];

    public static function tool(): ToolId
    {
        return ToolId::TravelTuning;
    }

    #[Override]
    public function mount(): void
    {
        parent::mount();
        $this->data['runs'] = self::emptyRuns();
        // Nothing here is the user's yet: the region label field's own default (Region) must not stop the dataset's.
        foreach (self::PREFILLED_OPTIONS as $key) {
            unset($this->data['options'][$key]);
        }
        $this->prefillOptions();
        // The form was built by fill() before the scope was set, so its Inputs step is stale: build it again.
        $this->cacheSchema('form');
    }

    #[Override]
    public function resetInputs(): void
    {
        parent::resetInputs();
        $this->data['runs'] = self::emptyRuns();
        $this->prefillOptions();
    }

    #[Override]
    protected function inputsStep(): Step
    {
        return Step::make('Inputs')
            ->description('The plan travels runs to compare, baseline first and final run last, and the polygons each one used.')
            ->schema(fn (): array => $this->runFields());
    }

    /** @return list<Component> */
    protected function runFields(): array
    {
        if (! $this->hasScope()) {
            return [Text::make('Choose the environment and dataset first.')->color('gray')];
        }
        $scope = $this->scopeModel();
        $rows = app(SourceRuns::class)->rows($scope);
        if ($rows === []) {
            return [Text::make('There are no finished plan travels runs here yet. Run plan travels with the Google check on, once for the baseline and once after each change to the weightings.')->color('gray')];
        }

        $runOptions = [];
        $withoutGoogle = [];
        foreach ($rows as $row) {
            $runOptions[$row['id']] = SourceRuns::optionLabel($row);
            if (! $row['google']) {
                $withoutGoogle[] = $row['id'];
            }
        }
        $sysOptions = app(RunInputPicker::class)->optionsForKind($scope, ExportKind::Sys);
        $sysScope = $scope instanceof Dataset ? $scope->environment : $scope;
        // The drop zone polls only itself: re-render the step while a SYS export is read, so it joins the Polygons lists.
        $sysReading = ExportFile::query()->where('scope_type', $sysScope->getMorphClass())->where('scope_id', (string) $sysScope->getKey())
            ->where('kind', ExportKind::Sys)->pending()->exists();

        return [
            View::make('filament.pages.tools.travel-tuning-runs')->viewData(['rows' => $rows]),
            Group::make([Repeater::make('runs')
                ->label('Runs in the report')
                ->helperText('The first run is the baseline and the last is the final run. Use the arrows to change the order. A blank label becomes Baseline, Run 1, Run 2.')
                ->schema([
                    Select::make('run_id')
                        ->label('Plan travels run')
                        ->options($runOptions)
                        ->disableOptionWhen(static fn (string $value): bool => in_array($value, $withoutGoogle, true))
                        ->required()
                        ->native(false)
                        ->columnSpan(['default' => 1, 'md' => 2]),
                    TextInput::make('label')->label('Label')->placeholder('Baseline, Run 1, Run 2')->maxLength(60),
                    Select::make('sys_file_id')
                        ->label('Polygons (SYS export)')
                        ->options($sysOptions)
                        ->placeholder('None')
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(static function (?string $state, Set $set): void {
                            if (filled($state) && ! self::sysHasPolygons($state)) {
                                $set('sys_file_id', null);
                            }
                        })
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 3])
                ->itemNumbers()
                ->reorderableWithButtons()
                ->minItems(2)
                ->maxItems(SourceRuns::MAX_RUNS)
                ->defaultItems(2)
                ->addActionLabel('Add a run')])
                ->extraAttributes($sysReading ? ['wire:poll.2s' => '$refresh'] : []),
            Livewire::make(ExportDropZone::class, ['scope' => $sysScope, 'kinds' => [ExportKind::Sys], 'compact' => true])
                ->key("drop-sys-{$sysScope->getKey()}"),
            Text::make('A new SYS export appears in the Polygons lists once it has been read. SYS exports are kept for '.(int) config('exports.ttl_hours', 12).' hours. A finished report keeps working after its plan travels runs and SYS exports are gone.')->color('gray'),
        ];
    }

    /** Says why a picked SYS export cannot be used, and returns false so the field is emptied. */
    public static function sysHasPolygons(string $fileId): bool
    {
        $file = ExportFile::query()->ready()->find($fileId);
        $problem = $file === null ? 'That SYS export is no longer available' : app(SourceRuns::class)->sysError($file);
        if ($problem === null) {
            return true;
        }

        Notification::make()->title($problem)->body('Choose another SYS export, or None.')->danger()->send();

        return false;
    }

    #[Override]
    protected function chosenRuns(array $state): array
    {
        return array_values(array_filter((array) ($state['runs'] ?? []), 'is_array'));
    }

    /** @return array<string, array{run_id: null, label: null, sys_file_id: null}> two empty rows, keyed as the repeater keys its items */
    private static function emptyRuns(): array
    {
        $empty = ['run_id' => null, 'label' => null, 'sys_file_id' => null];

        return [(string) Str::uuid() => $empty, (string) Str::uuid() => $empty];
    }

    /** Client, dataset name and region label follow the scope until the user types their own; client and dataset are blank for Unfiled, the region label is "Region". */
    private function prefillOptions(): void
    {
        $dataset = $this->hasScope() && ! $this->isUnfiled() ? $this->scopeModel() : null;
        $settings = $dataset instanceof Dataset ? $dataset->settings : null;
        $suggested = [
            'client' => (string) ($settings?->displayName ?: ($dataset instanceof Dataset ? ($dataset->environment?->account_id ?? '') : '')),
            'dataset' => (string) ($settings?->datasetDisplayName ?: ($dataset instanceof Dataset ? $dataset->name : '')),
            'region_label' => (string) ($settings?->regionLabel ?: 'Region'),
        ];

        foreach ($suggested as $key => $value) {
            $current = $this->data['options'][$key] ?? null;
            if (blank($current) || $current === ($this->suggestedOptions[$key] ?? null)) {
                $this->data['options'][$key] = $value;
            }
        }
        $this->suggestedOptions = $suggested;
    }
}
