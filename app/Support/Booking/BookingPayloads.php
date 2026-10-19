<?php

namespace App\Support\Booking;

use App\Models\Task;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The request bodies the appointment booking page sends to the services app.
 * Every id is a PSO id, never a row uuid. Datetimes are UTC without an offset,
 * taken from midnight of the base day in the dataset's time zone.
 */
final class BookingPayloads
{
    private const int WINDOW_DAYS = 21;

    public function __construct(
        private readonly Task $task,
        private readonly BookingSlaTypes $sla,
    ) {}

    /** Ask PSO which days have slots: no template id, surveyTemplate true. */
    public function survey(CarbonImmutable $baseDay): array
    {
        $data = $this->common($baseDay);
        $data['surveyTemplate'] = true;

        return ['data' => $data];
    }

    /** Ask PSO for one template's slots; optional fields only when given. */
    public function day(CarbonImmutable $baseDay, string $templateId, ?string $slotUsageRuleId, ?bool $blocking, ?string $inputDatetime): array
    {
        $data = $this->common($baseDay);
        $data['appointmentTemplateId'] = $templateId;

        if (filled($slotUsageRuleId)) {
            $data['slotUsageRuleId'] = $slotUsageRuleId;
        }
        if ($blocking !== null) {
            $data['blocking'] = $blocking;
        }
        if (filled($inputDatetime)) {
            $data['inputDatetime'] = $inputDatetime;
        }

        return ['data' => $data];
    }

    public function accept(int $offerId, ?string $previousSlaTypeId): array
    {
        $data = [
            'appointmentOfferId' => $offerId,
            'bookingSlaTypeId' => $this->sla->bookingSlaTypeId,
        ];

        if (filled($previousSlaTypeId)) {
            $data['previousSlaTypeId'] = $previousSlaTypeId;
        }

        return ['data' => $data];
    }

    public function check(int $offerId): array
    {
        return ['data' => ['appointmentOfferId' => $offerId]];
    }

    private function common(CarbonImmutable $baseDay): array
    {
        $customer = $this->task->customer;
        if ($customer?->lat === null || $customer->long === null) {
            throw new RuntimeException('The customer has no coordinates.');
        }

        $timeZone = $this->task->dataset->timeZone();
        $day = $baseDay->setTimezone($timeZone);
        $start = self::utc($day->startOfDay());

        return [
            'activityId' => $this->task->friendly_id,
            'activityTypeId' => $this->task->taskType?->pso_id,
            'duration' => (int) $this->task->duration,
            'baseValue' => (int) round((float) $this->task->base_value),
            'priority' => (int) $this->task->taskType?->attribute('priority'),
            'lat' => (float) $customer->lat,
            'long' => (float) $customer->long,
            'regions' => array_values($this->task->regions ?? []),
            'skills' => array_values($this->task->skills ?? []),
            'slaTypeId' => $this->sla->appointmentSlaTypeId,
            'slaStart' => $start,
            'slaEnd' => self::utc($day->addDays(self::WINDOW_DAYS)->startOfDay()),
            'appointmentTemplateDuration' => self::WINDOW_DAYS,
            'appointmentBaseDateTime' => $start,
            'appointmentTemplateDatetime' => $start,
            'timezone' => $timeZone,
        ];
    }

    private static function utc(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d\TH:i:s');
    }
}
