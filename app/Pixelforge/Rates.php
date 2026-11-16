<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * Prices in US dollars per 1M tokens, as exact decimal strings (e.g. "12.50").
 */
final readonly class Rates
{
    /** @param array<string, numeric-string> $perMillion keyed by TokenType value */
    private function __construct(private array $perMillion) {}

    /** @param array<string, string|int|float> $rates */
    public static function fromArray(array $rates): self
    {
        $perMillion = [];

        foreach (TokenType::cases() as $type) {
            $rate = (string) ($rates[$type->value] ?? throw new InvalidArgumentException("Missing rate for {$type->value}."));

            if (! is_numeric($rate) || bccomp($rate, '0', 12) < 0) {
                throw new InvalidArgumentException("Invalid rate for {$type->value}: {$rate}");
            }

            $perMillion[$type->value] = $rate;
        }

        return new self($perMillion);
    }

    /** @return numeric-string */
    public function perMillion(TokenType $type): string
    {
        return $this->perMillion[$type->value];
    }

    /** @return array<string, numeric-string> */
    public function toArray(): array
    {
        return $this->perMillion;
    }
}
