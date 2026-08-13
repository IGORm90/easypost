<?php

namespace App\Services\Media\Support;

use App\Services\Media\Exceptions\SinkSizeLimitExceededException;

/**
 * Review finding 5 (Important), second pass: MediaUploadService::download()
 * originally capped the from-url body size via `CURLOPT_PROGRESSFUNCTION`,
 * a Guzzle option documented as deprecated for removal in Guzzle 8, and
 * never exercised by this codebase's Http::fake()-based tests (Guzzle's
 * real cURL transport is never reached when faked), so a future Guzzle
 * upgrade would have silently disabled the only defense against a
 * hostile server streaming unbounded bytes.
 *
 * The first replacement attempt passed a `Psr\Http\Message\StreamInterface`
 * object directly as the `sink` request option. That works correctly
 * against REAL cURL (verified against a real local HTTP server — see
 * tests/Unit/Services/Media/SizeCappedSinkStreamTest.php) but breaks
 * `Http::fake()`: Laravel's own `PendingRequest::sinkStubHandler()` calls
 * `fwrite($sink, $body)` unconditionally, which requires a genuine PHP
 * stream *resource*, not an object — a StreamInterface sink under
 * `Http::fake()` throws a TypeError there, not the size-limit exception
 * this class exists to raise, and every existing from-url Feature test
 * uses `Http::fake()`.
 *
 * This class is a PHP user stream wrapper instead — registered once as
 * the `mediacap://` scheme, `fopen('mediacap://sink', 'w+b', ..., $context)`
 * returns a genuine resource, so it satisfies BOTH consumers: real
 * cURL (via Guzzle's `Psr7\Utils::streamFor($resource)` -> `Psr7\Stream`,
 * whose `write()` calls `fwrite()` on the underlying resource, which PHP
 * routes to `stream_write()` below) AND `Http::fake()`'s own
 * `fwrite($sink, $body)` call (same routing). One enforcement mechanism,
 * exercised identically by real network tests and by every existing
 * `Http::fake()`-based Feature test — no split between "what production
 * enforces" and "what the fast test suite can see enforced".
 */
final class CappedSinkStreamWrapper
{
    private const string SCHEME = 'mediacap';

    /** @var resource|null Set by PHP itself when opened via a stream context. */
    public $context;

    /** @var resource */
    private $handle;

    private int $bytesWritten = 0;

    private int $maxBytes;

    public static function register(): void
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    /**
     * Opens a real fopen()-able resource that writes through to
     * $realPath, capped at $maxBytes.
     *
     * @return resource
     */
    public static function open(string $realPath, int $maxBytes)
    {
        self::register();

        $context = stream_context_create([
            self::SCHEME => ['path' => $realPath, 'maxBytes' => $maxBytes],
        ]);

        $resource = fopen(self::SCHEME.'://sink', 'w+b', false, $context);

        if ($resource === false) {
            throw new \RuntimeException('Could not open the capped sink stream.');
        }

        return $resource;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $options = stream_context_get_options($this->context ?? stream_context_get_default());
        $params = $options[self::SCHEME] ?? null;

        if (! is_array($params) || ! isset($params['path'], $params['maxBytes'])) {
            return false;
        }

        $this->maxBytes = (int) $params['maxBytes'];

        $handle = fopen((string) $params['path'], $mode);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        $this->bytesWritten += strlen($data);

        if ($this->bytesWritten > $this->maxBytes) {
            throw new SinkSizeLimitExceededException(
                "Sink received {$this->bytesWritten} bytes, exceeding its {$this->maxBytes}-byte cap.",
            );
        }

        $written = fwrite($this->handle, $data);

        return $written === false ? 0 : $written;
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        $position = ftell($this->handle);

        return $position === false ? 0 : $position;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
