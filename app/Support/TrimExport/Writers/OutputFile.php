<?php

namespace App\Support\TrimExport\Writers;

use RuntimeException;

/** A buffered file written as `<path>.part` and renamed when complete, so a half-written export is never mistaken for one. */
class OutputFile
{
    private const int BUFFER_BYTES = 262144;

    /** @var resource|null */
    private $handle = null;

    private string $path = '';

    private string $pending = '';

    private int $bytesWritten = 0;

    public function open(string $path): void
    {
        $handle = @fopen($path.'.part', 'wb');
        if ($handle === false) {
            throw new RuntimeException('The trimmed export could not be written.');
        }

        $this->handle = $handle;
        $this->path = $path;
        $this->pending = '';
        $this->bytesWritten = 0;
    }

    public function write(string $bytes): void
    {
        $this->pending .= $bytes;
        if (strlen($this->pending) >= self::BUFFER_BYTES) {
            $this->flush();
        }
    }

    public function close(): int
    {
        $this->flush();
        fclose($this->handle);
        $this->handle = null;

        if (! @rename($this->path.'.part', $this->path)) {
            @unlink($this->path.'.part');

            throw new RuntimeException('The trimmed export could not be written.');
        }

        return $this->bytesWritten;
    }

    public function abort(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        @unlink($this->path.'.part');
        $this->pending = '';
    }

    private function flush(): void
    {
        if ($this->pending === '') {
            return;
        }

        $written = fwrite($this->handle, $this->pending);
        if ($written === false || $written < strlen($this->pending)) {
            throw new RuntimeException('The trimmed export could not be written.');
        }

        $this->bytesWritten += $written;
        $this->pending = '';
    }
}
