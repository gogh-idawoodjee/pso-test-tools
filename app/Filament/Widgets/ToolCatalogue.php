<?php

namespace App\Filament\Widgets;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Navigation\Groups;
use App\Filament\Pages;
use App\Filament\Resources;
use App\Tools\Contracts\TakesOneInput;
use App\Tools\ToolRegistry;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

class ToolCatalogue extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tool-catalogue';

    /** @return list<array{label: string, items: list<array{label: string, icon: Heroicon|string|\BackedEnum, question: string, needs: ?string, url: string, class: class-string<Page|FilamentResource>}>}> */
    public function sections(): array
    {
        $registry = app(ToolRegistry::class);
        $analyse = [];
        foreach (ToolId::cases() as $tool) {
            $page = $this->wizardPageFor($tool);
            if ($page === null || ! $registry->has($tool)) {
                continue;
            }
            $impl = $registry->for($tool);
            $analyse[] = $this->item($page, $tool->getLabel(), $tool->question(), $impl instanceof TakesOneInput ? $this->needsOne($impl->oneOfKinds()) : $this->needs($impl->requiredKinds(), $impl->optionalKinds()));
        }
        $analyse[] = $this->item(Pages\SysFileCompare::class, 'PSO Sys File Compare', 'What differs between two or more SYS exports?', 'Needs two or more '.ExportKind::Sys->getLabel().' exports');
        $analyse[] = $this->item(Pages\FilterLoadFile::class, 'Filter Load File', 'Can I cut a LOAD file down to chosen regions, resources or activities?', 'Needs a '.ExportKind::Load->getLabel().' file');
        $analyse[] = $this->item(Pages\PreferenceCalculator::class, 'Preference Calculator', 'Which of two technicians will PSO prefer for an activity, given costs, distances and preference values?', null);

        $sections = [
            ['label' => Groups::ANALYSE, 'items' => $analyse],
            ['label' => Groups::LIVE, 'items' => [
                $this->item(Pages\HomePages\PSOActivityHome::class, 'Activity Services', 'Create, update or delete activities in a live environment.', null),
                $this->item(Pages\HomePages\PSOResourceHome::class, 'Resource Services', 'Change shifts, events and unavailability for a resource.', null),
                $this->item(Pages\HomePages\PSOModellingHome::class, 'Modelling Services', 'Generate regions and resources for a model.', null),
                $this->item(Resources\EnvironmentResource::class, 'Gateway upload', 'Send a schedule data file to an environment.', 'Open an environment, then its Tools tab'),
                $this->item(Pages\TravelAnalyzer::class, 'Travel Analyzer', 'How do PSO travel times compare with the map provider?', null),
                $this->item(Pages\GenericDelete::class, 'Generic Delete', 'Delete one object from a live environment.', null),
                $this->item(Pages\GenerateCustomException::class, 'Generate Custom Exception', 'Raise a custom exception in a live environment.', null),
            ]],
            ['label' => Groups::SETUP, 'items' => [
                $this->item(Resources\EnvironmentResource::class, 'Environments', 'The PSO systems you connect to.', null),
                $this->item(Resources\DatasetResource::class, 'Datasets', 'One per customer dataset: settings, exports and run history.', null),
                $this->item(Resources\RegionResource::class, 'Reference values', 'Regions, skills and types synced from PSO.', null),
            ]],
            ['label' => Groups::ADMIN, 'items' => [
                $this->item(Resources\TokenUsageLogResource::class, 'Token usage', 'API calls made through this app.', null),
                $this->item(Pages\IssueToken::class, 'Issue token', 'Create an API token.', null),
                $this->item(Pages\HealthCheckResults::class, 'Health', 'Is this app healthy?', null),
                $this->item(Pages\Backups::class, 'Backups', 'Database and file backups.', null),
            ]],
        ];

        // Only what this user can open, as the sidebar does; a section left with nothing is dropped.
        $sections = array_map(static fn (array $section): array => [
            ...$section,
            'items' => array_values(array_filter($section['items'], static fn (array $item): bool => $item['class']::canAccess())),
        ], $sections);

        return array_values(array_filter($sections, static fn (array $section): bool => $section['items'] !== []));
    }

    /**
     * @param  class-string<Page|FilamentResource>  $class
     * @return array{label: string, icon: Heroicon|string|\BackedEnum, question: string, needs: ?string, url: string, class: class-string<Page|FilamentResource>}
     */
    private function item(string $class, string $label, string $question, ?string $needs): array
    {
        $icon = is_subclass_of($class, Pages\Tools\ToolWizardPage::class) ? $class::tool()->icon() : $class::getNavigationIcon();

        return ['label' => $label, 'icon' => $icon ?? Heroicon::OutlinedSquares2x2, 'question' => $question, 'needs' => $needs, 'url' => $class::getUrl(), 'class' => $class];
    }

    /** @return class-string<Pages\Tools\ToolWizardPage>|null */
    private function wizardPageFor(ToolId $tool): ?string
    {
        foreach ([Pages\Tools\LoadAuditEntry::class, Pages\Tools\RegionAvailabilityEntry::class, Pages\Tools\AppointmentTimelineEntry::class, Pages\Tools\PlanTravelsEntry::class, Pages\Tools\ScheduleReviewEntry::class, Pages\Tools\SysCompareEntry::class, Pages\Tools\TrimExportEntry::class] as $page) {
            if ($page::tool() === $tool) {
                return $page;
            }
        }

        return null;
    }

    /** @param  list<ExportKind>  $required  @param  list<ExportKind>  $optional */
    private function needs(array $required, array $optional): string
    {
        $need = implode(', ', array_map(static fn (ExportKind $k): string => $k->getLabel(), $required));
        $opt = implode(', ', array_map(static fn (ExportKind $k): string => $k->getLabel(), $optional));

        $article = preg_match('/^[aeiou]/i', $need) === 1 ? 'an' : 'a';

        return "Needs {$article} {$need} export".($opt !== '' ? '; '.$opt.' optional' : '');
    }

    /** @param list<ExportKind> $kinds */
    private function needsOne(array $kinds): string
    {
        $labels = array_map(static fn (ExportKind $kind): string => $kind->getLabel(), $kinds);
        $last = array_pop($labels);

        return 'Needs one '.($labels === [] ? $last : implode(', ', $labels).' or '.$last).' export';
    }
}
