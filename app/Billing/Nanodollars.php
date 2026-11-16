<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * All money in the app is stored as integer nanodollars (1 USD = 1,000,000,000).
 *
 * Pixelforge reports costs as exact decimals with nine places, so a nanodollar
 * is the smallest unit we ever need and integer maths never drifts.
 */
final class Nanodollars
{
    public const PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" -> 46125000 */
    public static function fromDecimal(string $dollars): int
    {
        if (! is_numeric($dollars) || ! preg_match('/^-?\d+(\.\d{1,9})?$/', $dollars)) {
            throw new InvalidArgumentException("Not a dollar amount with at most 9 decimals: {$dollars}");
        }

        return (int) bcmul($dollars, (string) self::PER_DOLLAR, 0);
    }

    /** 46125000 -> "$0.046125", keeping every significant digit (at least cents). */
    public static function formatUsd(int $nanodollars): string
    {
        [$dollars, $fraction] = explode('.', self::toDecimal(abs($nanodollars)));
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');

        return ($nanodollars < 0 ? '-' : '').'$'.number_format((int) $dollars).'.'.$fraction;
    }

    /** 46125000 -> "0.046125000" */
    public static function toDecimal(int $nanodollars): string
    {
        return bcdiv((string) $nanodollars, (string) self::PER_DOLLAR, 9);
    }
}
