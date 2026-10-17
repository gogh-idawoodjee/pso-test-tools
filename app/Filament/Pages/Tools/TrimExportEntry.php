<?php

namespace App\Filament\Pages\Tools;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Pages\Tools\TrimExport\TrimExportFields;
use App\Models\ExportFile;
use App\Support\TrimExport\ExportFileScanner;
use App\Support\TrimExport\TableDependencies;
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
    public array $tableCounts = [];

    #[Locked]
    public ?string $tablesForFileId = null;

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
                ->schema(TrimExportFields::tables()),
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
            $this->data['options']['tables'] = TableDependencies::cleanupPresent(array_keys($this->tableCounts));
            $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
        }

        return true;
    }

    public function resetTables(): void
    {
        $this->tableCounts = [];
        $this->tablesForFileId = null;
        $this->data['options']['tables'] = [];
        $this->data['options']['preset'] = TrimExportFields::PRESET_CLEANUP;
    }
}
