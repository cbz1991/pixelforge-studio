const NANO_PER_DOLLAR = 1_000_000_000;

/**
 * Formats integer nanodollars as US dollars without ever going through a
 * float, e.g. 381887500 -> "$0.3818875".
 *
 * `decimals` limits the precision (rounding half up, or up when `roundUp`);
 * by default every significant digit is shown, so users see exactly what
 * they were charged.
 */
export function formatUsd(
    nanodollars: number,
    { decimals = 9, roundUp = false } = {},
): string {
    const unit = 10 ** (9 - Math.min(9, Math.max(2, decimals)));
    const rounded = roundUp
        ? Math.ceil(nanodollars / unit) * unit
        : Math.floor((nanodollars + unit / 2) / unit) * unit;

    const dollars = Math.floor(rounded / NANO_PER_DOLLAR);
    const fraction = String(rounded % NANO_PER_DOLLAR)
        .padStart(9, '0')
        .replace(/0+$/, '')
        .padEnd(2, '0');

    return `$${dollars.toLocaleString('en-US')}.${fraction}`;
}
