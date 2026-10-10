<?php

namespace App\Livewire;

use App\Enums\ExportKind;
use App\Models\Dataset;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * One drop target per export kind the scope accepts. Files go from the browser
 * straight to R2 through a presigned url; this component only renders the
 * targets, the upload itself is JavaScript (see the view).
 */
class ExportDropZone extends Component
{
    public Model $scope;

    /** @var array<string, string> kind value => label */
    public array $kinds = [];

    public function mount(Model $scope): void
    {
        $this->scope = $scope;
        $this->kinds = collect(ExportKind::forScope($scope::class))
            ->mapWithKeys(static fn (ExportKind $kind): array => [$kind->value => $kind->getLabel()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.export-drop-zone', [
            'scopeType' => $this->scope instanceof Dataset ? 'dataset' : 'environment',
            'maxMegabytes' => (int) config('exports.max_file_megabytes', 400),
            'ttlHours' => (int) config('exports.ttl_hours', 12),
            'extensionsByKind' => collect(ExportKind::forScope($this->scope::class))
                ->mapWithKeys(static fn (ExportKind $kind): array => [$kind->value => $kind->extensions()])
                ->all(),
        ]);
    }
}
