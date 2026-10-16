<?php

namespace App\Livewire;

use App\Models\PsoGatewayUpload;
use App\Models\PsoGatewayUploadLogLine;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The live status panel of one gateway upload on the Environment Tools page:
 * file name, progress bar, status line, run log and the response summary.
 *
 * Its own component so the two-second poll re-renders only this panel. When
 * the poll lived on the page, every tick re-rendered the whole page (the four
 * tabs and the file upload field) and the browser jumped back to the top.
 *
 * Holds the upload id and the log lines only: the lines never carry the
 * password or the session token.
 */
class GatewayUploadStatus extends Component
{
    /**
     * How long the panel keeps polling after a run finishes: the job can
     * still write log lines (the logoff) just after the final status.
     */
    public const int LOG_GRACE_SECONDS = 30;

    /** Locked: the page passes an id it has already scoped to the environment. */
    #[Locked]
    public string $uploadId;

    /**
     * Run-log lines of the upload, appended to on each poll.
     *
     * @var array<int, array{level: string, text: string, at: string}>
     */
    public array $logLines = [];

    /** Newest log line id already in $logLines, so each poll reads only the lines after it. */
    #[Locked]
    public int $lastLogId = 0;

    /**
     * Whether the page has been told the run finished (or it had already
     * finished when the panel opened), so it is told once.
     */
    #[Locked]
    public bool $finishAnnounced = false;

    public function mount(string $uploadId): void
    {
        $this->uploadId = $uploadId;
        $this->finishAnnounced = $this->upload()?->status->isTerminal() ?? true;

        $this->poll();
    }

    /**
     * Appends the log lines written since the last poll. Cheap on purpose:
     * the panel calls this every two seconds while a run is in progress, so
     * it reads only lines after the newest id already shown.
     */
    public function poll(): void
    {
        $upload = $this->upload();

        if ($upload === null) {
            return;
        }

        $newLines = PsoGatewayUploadLogLine::query()
            ->where('pso_gateway_upload_id', $upload->id)
            ->where('id', '>', $this->lastLogId)
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'level', 'text', 'created_at']);

        foreach ($newLines as $line) {
            $this->logLines[] = [
                'level' => $line->level->value,
                'text' => $line->text,
                'at' => $line->created_at->format('H:i:s'),
            ];
            $this->lastLogId = $line->id;
        }

        // The page disables the form while a run is in progress; it re-renders
        // once when the run finishes so the form opens again.
        if (! $this->finishAnnounced && $upload->status->isTerminal()) {
            $this->finishAnnounced = true;
            $this->dispatch('gateway-upload-finished');
        }
    }

    /**
     * Whether the panel should keep polling: while the run is in progress,
     * and for a short while after it finishes so lines written just after
     * the final status (the logoff) still arrive.
     */
    private function shouldPoll(?PsoGatewayUpload $upload): bool
    {
        if ($upload === null) {
            return false;
        }

        if (! $upload->status->isTerminal()) {
            return true;
        }

        return $upload->completed_at !== null
            && $upload->completed_at->greaterThan(now()->subSeconds(self::LOG_GRACE_SECONDS));
    }

    public function render(): View
    {
        $upload = $this->upload();

        return view('livewire.gateway-upload-status', [
            'upload' => $upload,
            'keepPolling' => $this->shouldPoll($upload),
        ]);
    }

    private function upload(): ?PsoGatewayUpload
    {
        return PsoGatewayUpload::query()->whereKey($this->uploadId)->first();
    }
}
