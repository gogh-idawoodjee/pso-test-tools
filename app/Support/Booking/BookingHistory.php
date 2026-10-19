<?php

namespace App\Support\Booking;

use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Every services appointment request for one task's activity in its dataset, newest first, read from services'
 * own records (no PSO call) and turned into the booking page's history rows in the booking zone.
 */
final class BookingHistory
{
    public function __construct(private Task $task) {}

    /**
     * @return array{rows: list<array{when: string, what: string, outcome: string, offers: string, requestId: string, requestIdShort: string}>, error: ?string}
     */
    public function fetch(): array
    {
        $this->task->loadMissing(['dataset', 'customer']);

        $result = (new BookingClient($this->task, notify: false))->get('appointment', [
            'activityId' => (string) $this->task->friendly_id,
            'datasetId' => (string) $this->task->dataset->name,
        ]);

        if ($result['error'] !== null) {
            return ['rows' => [], 'error' => $result['error']];
        }

        $history = data_get($result['data'], 'history');
        if (! is_array($history)) {
            return ['rows' => [], 'error' => 'The PSO services API answered without the booking history.'];
        }

        return ['rows' => self::rows($history, $this->task->timeZone()), 'error' => null];
    }

    /**
     * Services' history rows as the table's text, in the order given (services sends newest first).
     *
     * @return list<array{when: string, what: string, outcome: string, offers: string, requestId: string, requestIdShort: string}>
     */
    public static function rows(array $history, string $timeZone): array
    {
        $rows = [];
        foreach ($history as $row) {
            if (! is_array($row)) {
                continue;
            }

            $requestId = (string) data_get($row, 'appointmentRequestId', '');

            $rows[] = [
                'when' => self::moment(data_get($row, 'createdAt'), $timeZone)?->format('M j, g:i A') ?? 'Not recorded',
                'what' => self::what($row, $timeZone),
                'outcome' => self::outcome($row, $timeZone),
                'offers' => (int) data_get($row, 'offersReturned', 0).' returned, '.(int) data_get($row, 'validOffers', 0).' valid',
                'requestId' => $requestId,
                'requestIdShort' => substr($requestId, 0, 8),
            ];
        }

        return $rows;
    }

    /** "Survey (wide net), 21 days from Oct 19" or "Offers, template Half Day, 21 days from Oct 20, rule set AM, blocking". */
    private static function what(array $row, string $timeZone): string
    {
        $days = data_get($row, 'appointmentTemplateDuration');
        $from = self::moment(data_get($row, 'appointmentTemplateDatetime'), $timeZone);
        $span = trim((is_numeric($days) ? (int) $days.' days ' : '').($from ? 'from '.$from->format('M j') : ''));

        if (data_get($row, 'survey') === true) {
            return implode(', ', array_filter(['Survey (wide net)', $span]));
        }

        $blocking = data_get($row, 'blocking');

        return implode(', ', array_filter([
            'Offers',
            filled(data_get($row, 'appointmentTemplateId')) ? 'template '.data_get($row, 'appointmentTemplateId') : null,
            $span,
            filled(data_get($row, 'slotUsageRuleId')) ? 'rule set '.data_get($row, 'slotUsageRuleId') : null,
            is_bool($blocking) ? ($blocking ? 'blocking' : 'not blocking') : null,
        ]));
    }

    /** The row's status in plain words; a survey always reads Released. */
    private static function outcome(array $row, string $timeZone): string
    {
        if (data_get($row, 'survey') === true) {
            return 'Released';
        }

        return match (data_get($row, 'status')) {
            'ACCEPTED' => self::booked($row, $timeZone),
            'DECLINED' => 'Released',
            'FAILED' => 'Failed',
            'CHECKED' => 'Checked, slot '.match (data_get($row, 'appointedCheck')) {
                'available' => 'free',
                'unavailable' => 'taken',
                default => 'unknown',
            },
            default => self::moment(data_get($row, 'offerExpiryDatetime'), 'UTC')?->isPast()
                ? 'Expired, offers left to PSO'
                : 'Open',
        };
    }

    /** "Booked Thu Oct 22, 8:00 AM to 12:00 PM America/Toronto EDT, SLA INSTALL (previous Appointment)". */
    private static function booked(array $row, string $timeZone): string
    {
        $window = AgendaPresenter::readableWindow(data_get($row, 'acceptedWindowStart'), data_get($row, 'acceptedWindowEnd'), $timeZone);
        $booking = data_get($row, 'bookingSlaTypeId');
        $previous = data_get($row, 'previousSlaTypeId');
        $sla = filled($booking) ? 'SLA '.$booking.(filled($previous) ? ' (previous '.$previous.')' : '') : null;

        return implode(', ', array_filter(['Booked'.($window ? ' '.$window : ''), $sla]));
    }

    private static function moment(mixed $value, string $timeZone): ?CarbonImmutable
    {
        return AgendaPresenter::utcMoment($value)?->setTimezone($timeZone);
    }
}
