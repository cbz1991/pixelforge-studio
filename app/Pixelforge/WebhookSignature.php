<?php

namespace App\Pixelforge;

/**
 * Checks the Pixelforge-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256
 * of "<t>.<raw body>" keyed with the signing secret>".
 */
final class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds,
    ) {}

    public function isValid(string $payload, string $header, int $now): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($this->secret === '' || ! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        // Reject old (or far-future) timestamps so a captured request can't be replayed.
        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        return hash_equals($expected, $signature);
    }
}
