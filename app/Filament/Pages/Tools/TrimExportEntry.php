<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\TrimExport\TrimExportFields;
use App\Models\ExportFile;
use App\Support\TrimExport\ExportFileScanner;
use App\Support\TrimExport\FilterChoices;
use App\Support\TrimExport\TableDependencies;
use App\Support\TrimExport\TrimPlan;
use App\Tools\TrimExport\TrimExportTool;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Livewire\Attributes\Locked;
use Override;
use RuntimeException;

/**
 * Trim export: Where, Inputs (one LOAD, plan or End of day export), Tables, Rows. Each option
 * step is filled from the chosen file when the step before it is done.
 */
class TrimExportEntry extends ToolWizardPage
{
    /** The kinds this page trims, in the order the choice lists them. */
    private const array KINDS = [ExportKind::Load, ExportKind::Plan, ExportKind::Eod];

    /** @var array<string, int> table => rows in the chosen file (names and counts only) */
    #[Locked]
    public array $tableCounts = [];

    #[Locked]
    public ?string $tablesForFileId = null;

    #[Locked]
    public ?string $choicesForFileId = null;

    /** The decrypted choices for $choicesForFileId, kept for this request only (never sent to the browser). */
    private ?FilterChoices $choicesMemo = null;

    private ?string $choicesMemoFileId = null;

    public static function tool(): ToolId
    {
        return ToolId::TrimExport;
    }

    #[Override]
    public function mount(): void
    {
        parent::mount();

        // Open on the first kind the user has a file of.
        $files = $this->data['files'] ?? [];
        $this->data['input_kind'] = ExportKind::Load->value;
        foreach (self::KINDS as $kind) {
            if (filled($files[$kind->value] ?? null)) {
                $this->data['input_kind'] = $kind->value;

                break;
            }
        }
        // The form was built by fill() before the scope and kind were set, so its Inputs fields are stale: build it again.
        $this->cacheSchema('form');
    }

    #[Override]
    protected function steps(): array
    {
        return [
            $this->whereStep(),
            $this->inputsStep()->afterValidation(function (): void {
                if (! $this->prepareTables()) {
                    throw new Halt;
                }
            }),
            Step::make('Tables')
                ->description('Which whole tables to remove or keep. Counts are rows in the chosen file.')
                ->schema(TrimExportFields::tables())
                ->afterValidation(function (): void {
                    if (! $this->prepareRows()) {
                        throw new Halt;
                    }
                }),
            Step::make('Rows')
                ->description('Which rows to keep. Leave every filter empty to keep all rows.')
                ->schema([
                    ...TrimExportFields::rows(),
                    // Shown for an Unfiled run only (the field hides itself otherwise); a dataset run uses the dataset's zone.
                    $this->timeZoneField()->helperText('The window days are whole days in this zone.'),
                ]),
        ];
    }

    #[Override]
    protected function inputLead(): array
    {
        return [
            Radio::make('input_kind')
                ->label('Export to trim')
                ->options(collect(self::KINDS)->mapWithKeys(static fn (ExportKind $kind): array => [$kind->value => $kind->getLabel()])->all())
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated(static fn (self $livewire) => $livewire->resetTables()),
        ];
    }

    #[Override]
    protected function inputKinds(): array
    {
        return [$this->chosenKind()];
    }

    #[Override]
    protected function requiredInputKinds(): array
    {
        return [$this->chosenKind()];
    }

    #[Override]
    protected function chosenFiles(array $files): array
    {
        $kind = $this->chosenKind()->value;

        return [$kind => $files[$kind] ?? null];
    }

    /** A different file has different tables: forget the list read for the old one. */
    #[Override]
    protected function afterInputsChanged(): void
    {
        if ($this->tablesForFileId !== null && $this->tablesForFileId !== $this->chosenExportFile()?->id) {
            $this->resetTables();
        }
    }

    /**
     * Never run on a table list read from another file: an upload that finished while the user was on a later
     * step can change the chosen file and empty the list. Read the new file's tables and ask for another look.
     * A missing or unusable file is the wizard's own refusal. A trim that would change nothing is refused too.
     */
    #[Override]
    public function start(): void
    {
        $file = $this->chosenExportFile();
        if ($file !== null && $file->id !== $this->tablesForFileId) {
            if ($this->prepareTables()) {
                Notification::make()->title('The run did not start')
                    ->body('The export changed, so the Tables step was read again from the new file. Check it, then run again.')
                    ->warning()->send();
            }

            return;
        }

        // The tool refuses a run that would change nothing; say so here before a run is queued only to fail.
        // A half-filled window is left to the form's own validation, which names the missing day.
        $options = (array) ($this->data['options'] ?? []);
        $windowStarted = filled($options['window_start'] ?? null) || filled($options['window_end'] ?? null);
        if (! $windowStarted && TrimExportTool::changesNothing(TrimPlan::fromOptions($options, 'UTC'))) {
            Notification::make()->title('The run did not start')
                ->body('Choose tables to remove or rows to keep. Nothing would change.')
                ->warning()->send();

            return;
        }

        parent::start();
    }

    public function chosenKind(): ExportKind
    {
        $kind = ExportKind::tryFrom((string) ($this->data['input_kind'] ?? ''));

        return in_array($kind, self::KINDS, true) ? $kind : ExportKind::Load;
    }

    public function chosenExportFile(): ?ExportFile
    {
        $fileId = $this->data['files'][$this->chosenKind()->value] ?? null;

        return blank($fileId) ? null : ExportFile::query()->ready()->find($fileId);
    }

    /** Reads the chosen file's tables (stored counts, or a streamed scan) and ticks the Cleanup preset. False keeps the wizard on Inputs. */
    public function prepareTables(): bool
    {
        $file = $this->chosenExportFile();
        if ($file === null) {
            Notification::make()->title('Choose an export first')->body('Pick the '.$this->chosenKind()->getLabel().' export to trim.')->warning()->send();

            return false;
        }

        if ($file->id === $this->tablesForFileId) {
            return true;
        }

        try {
            $this->tableCounts = app(ExportFileScanner::class)->tableCounts($file);
        } catch (RuntimeException $exception) {
            Notification::make()->title('The tables could not be read')->body($exception->getMessage())->danger()->send();

            return false;
        }

        $this->tablesForFileId = $file->id;
        if (($this->data['options']['preset'] ?? TrimExportFields::PRESET_CLEANUP) === TrimExportFields::PRESET_CLEANUP) {
            $this->data['options']['table_mode'] = TrimPlan::MODE_REMOVE;
            $this->data['options']['tables'] = TableDependencies::cleanupPresent(array_keys($this->tableCounts));
            $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
        }

        return true;
    }

    /** Another file: its tables, choices and chosen ids are not this one's. */
    public function resetTables(): void
    {
        $this->tableCounts = [];
        $this->tablesForFileId = null;
        $this->choicesForFileId = null;
        $this->data['options']['table_mode'] = TrimPlan::MODE_REMOVE;
        $this->data['options']['tables'] = [];
        $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
        // The unlock was for the old file's Input_Reference; a new file starts locked.
        $this->data['options']['allow_input_reference_removal'] = false;
        foreach (['region_ids', 'resource_ids', 'activity_type_ids', 'activity_ids'] as $key) {
            $this->data['options'][$key] = [];
        }
    }

    /** Reads the chosen file's filter choices (one streamed pass, cached per file). False keeps the wizard on Tables. */
    public function prepareRows(): bool
    {
        $file = $this->chosenExportFile();
        if ($file === null) {
            Notification::make()->title('Choose an export first')->body('Pick the '.$this->chosenKind()->getLabel().' export to trim.')->warning()->send();

            return false;
        }

        try {
            app(ExportFileScanner::class)->choices($file);
        } catch (RuntimeException $exception) {
            Notification::make()->title('The rows could not be read')->body($exception->getMessage())->danger()->send();

            return false;
        }

        $this->choicesForFileId = $file->id;
        $this->choicesMemoFileId = null;

        return true;
    }

    /** The chosen file's filter choices once the Tables step has read them; null before, so no scan runs while the page renders. */
    public function filterChoices(): ?FilterChoices
    {
        $fileId = $this->data['files'][$this->chosenKind()->value] ?? null;
        if (blank($fileId) || (string) $fileId !== $this->choicesForFileId) {
            return null;
        }

        if ($this->choicesMemoFileId !== $this->choicesForFileId) {
            $file = $this->chosenExportFile();
            $this->choicesMemo = $file === null ? null : app(ExportFileScanner::class)->cachedChoices($file);
            $this->choicesMemoFileId = $this->choicesForFileId;
        }

        return $this->choicesMemo;
    }
}
