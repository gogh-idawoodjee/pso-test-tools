<?php

namespace App\Filament\Widgets;

use App\Enums\ExportKind;
use App\Enums\Tool as ToolId;
use App\Filament\Navigation\Groups;
use App\Filament\Pages;
use App\Filament\Resources;
use App\Tools\ToolRegistry;
use Filament\Support\Colors\Color;
use Filament\Widgets\Widget;

class ToolCatalogue extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tool-catalogue';

    /** @return list<array{label: string, accent: string, items: list<array{label: string, question: string, needs: ?string, url: string}>}> */
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
            $analyse[] = ['label' => $tool->getLabel(), 'question' => $tool->question(), 'needs' => $this->needs($impl->requiredKinds(), $impl->optionalKinds()), 'url' => $page::getUrl()];
        }
        $analyse[] = ['label' => 'PSO Sys File Compare', 'question' => 'What differs between two or more SYS exports?', 'needs' => 'Needs two or more '.ExportKind::Sys->getLabel().' exports', 'url' => Pages\SysFileCompare::getUrl()];
        $analyse[] = ['label' => 'Filter Load File', 'question' => 'Can I cut a LOAD file down to chosen regions, resources or activities?', 'needs' => 'Needs a '.ExportKind::Load->getLabel().' file', 'url' => Pages\FilterLoadFile::getUrl()];
        $analyse[] = ['label' => 'Preference Calculator', 'question' => 'Which of two technicians will PSO prefer for an activity, given costs, distances and preference values?', 'needs' => null, 'url' => Pages\PreferenceCalculator::getUrl()];

        return [
            ['label' => Groups::ANALYSE, 'accent' => Color::Cyan[500], 'items' => $analyse],
            ['label' => Groups::LIVE, 'accent' => Color::Rose[500], 'items' => [
                ['label' => 'Activity Services', 'question' => 'Create, update or delete activities in a live environment.', 'needs' => null, 'url' => Pages\HomePages\PSOActivityHome::getUrl()],
                ['label' => 'Resource Services', 'question' => 'Change shifts, events and unavailability for a resource.', 'needs' => null, 'url' => Pages\HomePages\PSOResourceHome::getUrl()],
                ['label' => 'Modelling Services', 'question' => 'Generate regions and resources for a model.', 'needs' => null, 'url' => Pages\HomePages\PSOModellingHome::getUrl()],
                ['label' => 'Gateway upload', 'question' => 'Send a schedule data file to an environment.', 'needs' => 'Open an environment, then its Tools tab', 'url' => Resources\EnvironmentResource::getUrl()],
                ['label' => 'Travel Analyzer', 'question' => 'How do PSO travel times compare with the map provider?', 'needs' => null, 'url' => Pages\TravelAnalyzer::getUrl()],
                ['label' => 'Generic Delete', 'question' => 'Delete one object from a live environment.', 'needs' => null, 'url' => Pages\GenericDelete::getUrl()],
                ['label' => 'Generate Custom Exception', 'question' => 'Raise a custom exception in a live environment.', 'needs' => null, 'url' => Pages\GenerateCustomException::getUrl()],
            ]],
            ['label' => Groups::SETUP, 'accent' => Color::Amber[500], 'items' => [
                ['label' => 'Environments', 'question' => 'The PSO systems you connect to.', 'needs' => null, 'url' => Resources\EnvironmentResource::getUrl()],
                ['label' => 'Datasets', 'question' => 'One per customer dataset: settings, exports and run history.', 'needs' => null, 'url' => Resources\DatasetResource::getUrl()],
                ['label' => 'Reference values', 'question' => 'Regions, skills and types synced from PSO.', 'needs' => null, 'url' => Resources\RegionResource::getUrl()],
            ]],
            ['label' => Groups::ADMIN, 'accent' => Color::Emerald[500], 'items' => [
                ['label' => 'Token usage', 'question' => 'API calls made through this app.', 'needs' => null, 'url' => Resources\TokenUsageLogResource::getUrl()],
                ['label' => 'Issue token', 'question' => 'Create an API token.', 'needs' => null, 'url' => Pages\IssueToken::getUrl()],
                ['label' => 'Health', 'question' => 'Is this app healthy?', 'needs' => null, 'url' => Pages\HealthCheckResults::getUrl()],
                ['label' => 'Backups', 'question' => 'Database and file backups.', 'needs' => null, 'url' => Pages\Backups::getUrl()],
            ]],
        ];
    }

    /** @return class-string<Pages\Tools\ToolWizardPage>|null */
    private function wizardPageFor(ToolId $tool): ?string
    {
        foreach ([Pages\Tools\LoadAuditEntry::class, Pages\Tools\RegionAvailabilityEntry::class, Pages\Tools\AppointmentTimelineEntry::class, Pages\Tools\PlanTravelsEntry::class, Pages\Tools\ScheduleReviewEntry::class, Pages\Tools\SysCompareEntry::class] as $page) {
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

        return 'Needs a '.$need.' export'.($opt !== '' ? '; '.$opt.' optional' : '');
    }
}
