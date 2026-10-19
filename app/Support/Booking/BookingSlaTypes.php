<?php

namespace App\Support\Booking;

use App\Enums\ReferenceEntityType;
use App\Models\DatasetReferenceValue;
use App\Models\Task;

/**
 * The two SLA types a booking needs: the one PSO uses to offer appointment
 * slots (dataset setting) and the one the booked activity carries (task type
 * override, else the dataset default). An id that is not among the dataset's
 * SLA types counts as unset.
 */
final readonly class BookingSlaTypes
{
    public function __construct(
        public ?string $appointmentSlaTypeId,
        public ?string $bookingSlaTypeId,
        public string $appointmentSource,
        public string $bookingSource,
    ) {}

    public static function for(Task $task): self
    {
        $dataset = $task->dataset;
        $known = DatasetReferenceValue::withoutGlobalScopes()
            ->where('dataset_id', $dataset->id)
            ->ofType(ReferenceEntityType::SlaType)
            ->pluck('pso_id')
            ->all();

        $valid = static fn (mixed $id): ?string => is_string($id) && $id !== '' && in_array($id, $known, true) ? $id : null;

        $appointment = $valid($dataset->settings->appointmentSlaTypeId);
        $fromTaskType = $valid($task->taskType?->attribute('booking_sla_type_id'));
        $fromDataset = $valid($dataset->settings->defaultBookingSlaTypeId);

        return new self(
            appointmentSlaTypeId: $appointment,
            bookingSlaTypeId: $fromTaskType ?? $fromDataset,
            appointmentSource: $appointment !== null ? 'dataset' : 'missing',
            bookingSource: match (true) {
                $fromTaskType !== null => 'task type',
                $fromDataset !== null => 'dataset',
                default => 'missing',
            },
        );
    }

    public function complete(): bool
    {
        return $this->appointmentSlaTypeId !== null && $this->bookingSlaTypeId !== null;
    }

    public function missingMessage(): ?string
    {
        $messages = [];
        if ($this->appointmentSlaTypeId === null) {
            $messages[] = 'Set the appointment SLA type on the dataset.';
        }
        if ($this->bookingSlaTypeId === null) {
            $messages[] = 'Set a booking SLA type on the task type or the dataset.';
        }

        return $messages === [] ? null : implode(' ', $messages);
    }
}
