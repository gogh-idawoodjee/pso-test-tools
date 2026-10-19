<?php

namespace App\Support\Booking;

use App\Enums\HttpMethod;
use App\Filament\Resources\TaskResource\Pages\AppointmentBooking;
use App\Models\Environment;
use App\Models\Task;
use App\Traits\PSOInteractionsTrait;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases an open appointment request's offers (DELETE appointment/{id}) with the task's dataset environment,
 * so they do not wait in PSO until they time out. Used by the booking page and by the leave-page beacon.
 */
final class OfferRelease
{
    use PSOInteractionsTrait;

    public ?Environment $selectedEnvironment = null;

    public ?string $selectedDataset = null;

    public ?array $environment_data = ['send_to_pso' => true];

    public function __construct(private Task $task)
    {
        $this->task->loadMissing('dataset.environment');
        $this->selectedEnvironment = $this->task->dataset?->environment;
        $this->selectedDataset = $this->task->dataset?->name;
    }

    /** @return array{ok: bool, error: ?string} */
    public function release(string $appointmentRequestId): array
    {
        if ($this->selectedEnvironment === null) {
            return ['ok' => false, 'error' => 'The task has no environment to release the offers in.'];
        }

        try {
            $payload = $this->prepareTokenizedPayload(true, [
                'data' => ['appointmentRequestId' => $appointmentRequestId],
                ...$this->environment_payload_data(),
            ]);
            if ($payload === false) {
                return ['ok' => false, 'error' => 'Could not log on to PSO'.($this->session_error_message ? ': '.$this->session_error_message : '.')];
            }

            $this->sendToPSONew('appointment/'.rawurlencode($appointmentRequestId), $payload, method: HttpMethod::DELETE, isNonStandardResponse: true, timeout: AppointmentBooking::appointmentTimeout());
        } catch (Throwable $exception) {
            Log::warning('Releasing appointment offers failed', ['appointment_request_id' => $appointmentRequestId, 'message' => $exception->getMessage()]);

            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        $status = $this->last_response_status;
        if ($status === null || $status >= 300) {
            return ['ok' => false, 'error' => $status === null ? 'Could not reach the PSO services API.' : "The PSO services API answered {$status}."];
        }

        return ['ok' => true, 'error' => null];
    }

    /** The environment block every services call carries (as FormTrait::environment_payload_data). */
    private function environment_payload_data(): array
    {
        return [
            'environment' => [
                'datasetId' => $this->selectedDataset,
                'baseUrl' => $this->selectedEnvironment?->getAttribute('base_url'),
                'sendToPso' => data_get($this->environment_data, 'send_to_pso'),
                'accountId' => $this->selectedEnvironment?->getAttribute('account_id'),
            ],
        ];
    }

    /** No toasts: a release runs on page exit or behind another action, and a flashed toast would show on the next page. */
    public function notifyPayloadSent(string $title, string $body, bool $pass): void {}
}
