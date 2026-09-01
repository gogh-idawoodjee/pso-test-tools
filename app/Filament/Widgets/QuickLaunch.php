<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Backups;
use App\Filament\Pages\FilterLoadFile;
use App\Filament\Pages\GenerateCustomException;
use App\Filament\Pages\GenericDelete;
use App\Filament\Pages\HealthCheckResults;
use App\Filament\Pages\HomePages\PSOActivityHome;
use App\Filament\Pages\HomePages\PSOModellingHome;
use App\Filament\Pages\HomePages\PSOResourceHome;
use App\Filament\Pages\IssueToken;
use App\Filament\Pages\PreferenceCalculator;
use App\Filament\Pages\TechnicianAvail;
use App\Filament\Pages\TechnicianDetails;
use App\Filament\Pages\TravelAnalyzer;
use App\Filament\Resources\AppointmentTemplateResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\EnvironmentResource;
use App\Filament\Resources\RegionResource;
use App\Filament\Resources\SkillResource;
use App\Filament\Resources\SlotUsageRuleResource;
use App\Filament\Resources\TaskTypeResource;
use App\Filament\Resources\TokenUsageLogResource;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

class QuickLaunch extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-launch';

    /**
     * Accent hex per group, kept in sync with the named colors
     * registered in AppPanelProvider::colors().
     *
     * @return array<string, string>
     */
    public static function accents(): array
    {
        return [
            'core' => Color::Emerald[500],
            'base-data' => Color::Amber[500],
            'api-services' => Color::Rose[500],
            'additional-tools' => Color::Cyan[500],
        ];
    }

    /**
     * @return array<int, array{label: string, color: string, items: array<int, array{label: string, icon: Heroicon, class: class-string}>}>
     */
    public function getGroups(): array
    {
        return [
            [
                'label' => 'Core',
                'color' => 'core',
                'items' => [
                    ['label' => 'Token Usage Log', 'icon' => Heroicon::OutlinedRectangleStack, 'class' => TokenUsageLogResource::class],
                    ['label' => 'Issue Token', 'icon' => Heroicon::OutlinedKey, 'class' => IssueToken::class],
                    ['label' => 'Health Check', 'icon' => Heroicon::OutlinedCpuChip, 'class' => HealthCheckResults::class],
                    ['label' => 'Backups', 'icon' => Heroicon::OutlinedCpuChip, 'class' => Backups::class],
                ],
            ],
            [
                'label' => 'Base Data',
                'color' => 'base-data',
                'items' => [
                    ['label' => 'Environments', 'icon' => Heroicon::OutlinedCircleStack, 'class' => EnvironmentResource::class],
                    ['label' => 'Customers', 'icon' => Heroicon::OutlinedUserGroup, 'class' => CustomerResource::class],
                    ['label' => 'Regions', 'icon' => Heroicon::OutlinedMap, 'class' => RegionResource::class],
                    ['label' => 'Skills', 'icon' => Heroicon::OutlinedAcademicCap, 'class' => SkillResource::class],
                    ['label' => 'Task Types', 'icon' => Heroicon::OutlinedRectangleStack, 'class' => TaskTypeResource::class],
                    ['label' => 'Appointment Templates', 'icon' => Heroicon::OutlinedCalendar, 'class' => AppointmentTemplateResource::class],
                    ['label' => 'Slot Usage Rules', 'icon' => Heroicon::OutlinedAdjustmentsHorizontal, 'class' => SlotUsageRuleResource::class],
                ],
            ],
            [
                'label' => 'API Services',
                'color' => 'api-services',
                'items' => [
                    ['label' => 'Activity Home', 'icon' => Heroicon::OutlinedDocumentText, 'class' => PSOActivityHome::class],
                    ['label' => 'Modelling Home', 'icon' => Heroicon::OutlinedCubeTransparent, 'class' => PSOModellingHome::class],
                    ['label' => 'Resource Home', 'icon' => Heroicon::OutlinedUsers, 'class' => PSOResourceHome::class],
                    ['label' => 'Technician Details', 'icon' => Heroicon::OutlinedDocumentText, 'class' => TechnicianDetails::class],
                    ['label' => 'Travel Analyzer', 'icon' => Heroicon::OutlinedMap, 'class' => TravelAnalyzer::class],
                    ['label' => 'Generic Delete', 'icon' => Heroicon::OutlinedTrash, 'class' => GenericDelete::class],
                    ['label' => 'Generate Custom Exception', 'icon' => Heroicon::OutlinedExclamationTriangle, 'class' => GenerateCustomException::class],
                ],
            ],
            [
                'label' => 'Additional Tools',
                'color' => 'additional-tools',
                'items' => [
                    ['label' => 'Filter Load File', 'icon' => Heroicon::OutlinedFunnel, 'class' => FilterLoadFile::class],
                    ['label' => 'Technician Availability', 'icon' => Heroicon::OutlinedCalendar, 'class' => TechnicianAvail::class],
                    ['label' => 'Preference Calculator', 'icon' => Heroicon::OutlinedCalculator, 'class' => PreferenceCalculator::class],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, color: string, items: array<int, array{label: string, icon: Heroicon, url: string}>}>
     */
    public function getVisibleGroups(): array
    {
        $accents = static::accents();
        $groups = [];

        foreach ($this->getGroups() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                $class = $item['class'];

                if (! $class::canAccess()) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'url' => $class::getUrl(),
                ];
            }

            if ($items !== []) {
                $groups[] = [
                    'label' => $group['label'],
                    'accent' => $accents[$group['color']],
                    'items' => $items,
                ];
            }
        }

        return $groups;
    }
}
