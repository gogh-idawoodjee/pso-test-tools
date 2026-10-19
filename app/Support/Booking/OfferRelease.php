<?php

namespace App\Support\Booking;

use App\Enums\HttpMethod;
use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases an open appointment request's offers (DELETE appointment/{id}) with the task's dataset environment,
 * so they do not wait in PSO until they time out. Used by the booking page and by the leave-page beacon.
 */
final class OfferRelease
{
    /** No toasts: a release runs on page exit or behind another action, and a flashed toast would show on the next page. */
    private BookingClient $client;

    public function __construct(Task $task)
    {
        $this->client = new BookingClient($task, notify: false);
    }

    /** @return array{ok: bool, error: ?string} */
    public function release(string $appointmentRequestId): array
    {
        if (! $this->client->hasEnvironment()) {
            return ['ok' => false, 'error' => 'The task has no environment to release the offers in.'];
        }

        try {
            $result = $this->client->send('appointment/'.rawurlencode($appointmentRequestId), ['data' => ['appointmentRequestId' => $appointmentRequestId]], HttpMethod::DELETE);
        } catch (Throwable $exception) {
            Log::warning('Releasing appointment offers failed', ['appointment_request_id' => $appointmentRequestId, 'message' => $exception->getMessage()]);

            return ['ok' => false, 'error' => $exception->getMessage()];
        }

        $status = $result['status'];
        if ($status === null) {
            // No reply: a failed logon keeps its reason, anything else (timeout, unreachable) reads the same.
            $logon = str_starts_with((string) $result['error'], 'Could not log on to PSO');

            return ['ok' => false, 'error' => $logon ? $result['error'] : 'Could not reach the PSO services API.'];
        }
        if ($status >= 300) {
            return ['ok' => false, 'error' => "The PSO services API answered {$status}."];
        }

        return ['ok' => true, 'error' => null];
    }
}
