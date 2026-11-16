<?php

namespace App\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * An error response from Pixelforge (or a failure to reach it at all).
 *
 * `code` is Pixelforge's error code, e.g. "overloaded" or "validation_failed".
 * Branch on that, not on the HTTP status.
 */
class PixelforgeException extends RuntimeException
{
    /** Codes where nothing was created and the same request may succeed later. */
    private const RETRYABLE_CODES = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        'connection_failed',
        'server_error',
    ];

    /** @param array<string, list<string>> $errors */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self('connection_failed', 'Could not reach Pixelforge: '.$previous->getMessage(), previous: $previous);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true);
    }
}
