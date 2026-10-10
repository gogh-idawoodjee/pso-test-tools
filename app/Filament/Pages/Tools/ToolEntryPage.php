<?php

namespace App\Filament\Pages\Tools;

use App\Enums\Tool as ToolId;
use App\Filament\Resources\DatasetResource;
use App\Models\Dataset;
use App\Tools\ToolRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Override;
use UnitEnum;

/**
 * A thin entry under Additional Tools for a dataset tool: pick one of your
 * datasets and land on its Runs tab, where the tool is run against uploaded
 * exports. Hidden until the tool has an implementation registered.
 */
abstract class ToolEntryPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Additional Tools';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected string $view = 'filament.pages.tools.tool-entry';

    abstract public static function tool(): ToolId;

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
        return 'Run '.static::tool()->getLabel();
    }

    /** @return Collection<int, array{name: string, environment: string, url: string}> */
    public function datasets(): Collection
    {
        return Dataset::query()
            ->ownedByCurrentUser()
            ->with('environment')
            ->orderBy('name')
            ->get()
            ->map(static fn (Dataset $dataset): array => [
                'name' => $dataset->name,
                'environment' => (string) $dataset->environment?->name,
                'url' => DatasetResource::getUrl('view', ['record' => $dataset]).'?relation=toolRuns',
            ]);
    }
}
