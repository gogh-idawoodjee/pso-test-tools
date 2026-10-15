<?php

namespace App\Livewire;

use App\Enums\ExportKind;
use App\Models\Dataset;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One drop target per export kind (all the scope accepts, or the ones given).
 * Files go from the browser straight to R2 through a presigned url; this
 * component only renders the targets, the upload itself is JavaScript (see the view).
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

    /** @param  list<ExportKind>|null  $kinds  defaults to every kind the scope accepts */
    public function mount(Model $scope, ?array $kinds = null, bool $compact = false): void
    {
        $kinds ??= ExportKind::forScope($scope::class);

        foreach ($kinds as $kind) {
            if ($kind->allowedScope() !== $scope::class) {
                throw new InvalidArgumentException("A {$kind->getLabel()} export cannot be uploaded to a ".class_basename($scope).'.');
            }
        }

        $this->scope = $scope;
        $this->compact = $compact;
        $this->targets = collect($kinds)
            ->mapWithKeys(static fn (ExportKind $kind): array => [$kind->value => $kind->getLabel()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.export-drop-zone', [
            'scopeType' => $this->scope instanceof Dataset ? 'dataset' : 'environment',
            'maxMegabytes' => (int) config('exports.max_file_megabytes', 400),
            'ttlHours' => (int) config('exports.ttl_hours', 12),
            'extensionsByKind' => collect(array_keys($this->targets))
                ->mapWithKeys(static fn (string $kind): array => [$kind => ExportKind::from($kind)->extensions()])
                ->all(),
        ]);
    }
}
