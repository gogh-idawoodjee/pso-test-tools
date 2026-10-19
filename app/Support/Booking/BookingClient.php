<?php

namespace App\Support\Booking;

use App\Enums\HttpMethod;
use App\Models\Environment;
use App\Models\Task;
use App\Traits\PSOInteractionsTrait;
use JsonException;

/**
 * Sends the booking page's appointment calls through the PSO services API with the task's dataset environment,
 * and reads each reply into data, error text and HTTP status. Shared by the booking page and OfferRelease.
 */
final class BookingClient
{
    use PSOInteractionsTrait {
        notifyPayloadSent as private toast;
    }

    public ?Environment $selectedEnvironment = null;

    public ?string $selectedDataset = null;

    public ?array $environment_data = ['send_to_pso' => true];

    /** @param  bool  $notify  false raises no toasts at all (a release on page exit would flash one on the next page) */
    public function __construct(private Task $task, private bool $notify = true)
    {
        $this->task->loadMissing('dataset.environment');
        $this->selectedEnvironment = $this->task->dataset?->environment;
        $this->selectedDataset = $this->task->dataset?->name;
    }

    public function hasEnvironment(): bool
    {
        return $this->selectedEnvironment !== null;
    }

    /**
     * Send one appointment call through services and read the reply.
     *
     * @return array{data: ?array, error: ?string, status: ?int}
     */
    public function send(string $segment, array $payload, ?HttpMethod $method = null): array
    {
        $payload = array_merge($payload, $this->environment_payload_data());

        $tokenized = $this->prepareTokenizedPayload((bool) data_get($this->environment_data, 'send_to_pso'), $payload);
        if ($tokenized === false) {
            return ['data' => null, 'error' => 'Could not log on to PSO'.($this->session_error_message ? ': '.$this->session_error_message : '.'), 'status' => null];
        }

        // The page shows its own progress, so a toast per request is noise; errors still notify.
        $this->quietSuccess = true;

        try {
            $raw = $this->sendToPSONew($segment, $tokenized, method: $method, isNonStandardResponse: true, timeout: self::appointmentTimeout());
        } catch (JsonException) {
            return ['data' => null, 'error' => 'The PSO services API answered with something that is not JSON.', 'status' => null];
        }

        $status = $this->last_response_status;
        $decoded = json_decode($raw, true);

        // A non-2xx reply comes back as the raw body, JSON-encoded once more.
        if (is_string($decoded)) {
            return ['data' => null, 'error' => self::servicesErrorText(json_decode($decoded, true)) ?? 'The PSO services API returned an error.', 'status' => $status];
        }

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) {
            return ['data' => null, 'error' => self::servicesErrorText($decoded) ?? 'The PSO services API returned an error.', 'status' => $status];
        }

        $data = $decoded['data'];
        if ($method === null && ! is_array(data_get($data, 'appointmentOffers'))) {
            return ['data' => null, 'error' => 'The PSO services API answered without appointment offers.', 'status' => $status];
        }

        return ['data' => is_array($data) ? $data : [], 'error' => null, 'status' => $status];
    }

    /** Seconds allowed for each appointment call (config psott.appointment.timeout, env APPOINTMENT_TIMEOUT). */
    public static function appointmentTimeout(): int
    {
        return (int) config('psott.appointment.timeout', 60);
    }

    private static function servicesErrorText(mixed $body): ?string
    {
        foreach (['error', 'message.error', 'message', 'details', 'details.Message'] as $key) {
            $value = data_get($body, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
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

    public function notifyPayloadSent(string $title, string $body, bool $pass): void
    {
        if ($this->notify) {
            $this->toast($title, $body, $pass);
        }
    }
}
