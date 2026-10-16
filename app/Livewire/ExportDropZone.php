<?php

namespace App\Livewire;

use App\Enums\ExportFileStatus;
use App\Enums\ExportKind;
use App\Models\Dataset;
use App\Models\ExportFile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One drop target per export kind (all the scope accepts, or the ones given).
 * Files go from the browser straight to R2 through a presigned url; this
 * component only renders the targets, the upload itself is JavaScript (see the view).
 * After the upload the server reads the file (SummariseExportFileJob): each target shows
 * that too, as "Reading <name>" while it runs and the reason when it fails.
 */
class ExportDropZone extends Component
{
    #[Locked]
    public Model $scope;

    /** @var array<string, string> kind value => label */
    #[Locked]
    public array $targets = [];

    /** One line per target, the hint in a tooltip: for a target beside a form field. */
    #[Locked]
    public bool $compact = false;

    /** How long a failed read stays on its target. */
    private const FAILED_SHOWN_MINUTES = 10;

    /** @var array<string, string> kind value => 'reading' or 'error', set on each render; the view's Alpine reads it */
    #[Locked]
    public array $serverStates = [];

    /** @var list<string> failed file ids the user dismissed on this page */
    #[Locked]
    public array $dismissed = [];

    /** @param  list<ExportKind>|null  $kinds  defaults to every kind the scope accepts */
    public function mount(Model $scope, ?array $kinds = null, bool $compact = false): void
    {
        $kinds ??= ExportKind::forScope($scope::class);

        foreach ($kinds as $kind) {
            if (! $kind->allows($scope::class)) {
                throw new InvalidArgumentException("A {$kind->getLabel()} export cannot be uploaded to a ".class_basename($scope).'.');
            }
        }

        $this->scope = $scope;
        $this->compact = $compact;
        $this->targets = collect($kinds)
            ->mapWithKeys(static fn (ExportKind $kind): array => [$kind->value => $kind->getLabel()])
            ->all();
    }

    /** An upload finished: re-read the server state so the target shows the file being read. */
    #[On('export-uploaded')]
    public function refreshState(): void {}

    public function dismiss(string $fileId): void
    {
        $this->dismissed = array_values(array_unique([...$this->dismissed, $fileId]));
    }

    /**
     * Per kind, the newest file still arriving or being read, else, unless the user dismissed it,
     * the newest file when it is an abandoned upload or a failed read of the last few minutes.
     *
     * @return array<string, array{state: 'reading'|'error', file: ExportFile}>
     */
    private function serverFiles(): array
    {
        $found = [];

        foreach (array_keys($this->targets) as $kind) {
            $files = ExportFile::query()
                ->where('scope_type', $this->scope->getMorphClass())->where('scope_id', $this->scope->getKey())
                ->where('kind', $kind);

            $pending = (clone $files)->pending()->latest()->first(['id', 'original_filename', 'status']);
            if ($pending !== null) {
                $found[$kind] = ['state' => 'reading', 'file' => $pending];

                continue;
            }

            $newest = (clone $files)->where('status', '!=', ExportFileStatus::Purged)
                ->latest()->first(['id', 'original_filename', 'status', 'error_message', 'created_at', 'updated_at']);
            if ($newest === null || in_array($newest->id, $this->dismissed, true)) {
                continue;
            }

            // An upload the browser never completed: say so until dismissed, whatever its age (the purge marks it failed).
            if ($newest->isAbandonedUpload()) {
                $newest->error_message = 'Upload did not finish';
                $found[$kind] = ['state' => 'error', 'file' => $newest];

                continue;
            }

            if ($newest->status === ExportFileStatus::Failed
                && $newest->updated_at->greaterThanOrEqualTo(now()->subMinutes(self::FAILED_SHOWN_MINUTES))) {
                $found[$kind] = ['state' => 'error', 'file' => $newest];
            }
        }

        return $found;
    }

    public function render(): View
    {
        $serverFiles = $this->serverFiles();
        $this->serverStates = array_map(static fn (array $found): string => $found['state'], $serverFiles);

        return view('livewire.export-drop-zone', [
            'serverFiles' => $serverFiles,
            'scopeType' => $this->scope instanceof Dataset ? 'dataset' : 'environment',
            'maxMegabytes' => (int) config('exports.max_file_megabytes', 400),
            'ttlHours' => (int) config('exports.ttl_hours', 12),
            'extensionsByKind' => collect(array_keys($this->targets))
                ->mapWithKeys(static fn (string $kind): array => [$kind => ExportKind::from($kind)->extensions()])
                ->all(),
        ]);
    }
}
