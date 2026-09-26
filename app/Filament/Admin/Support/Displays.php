<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Turning database columns into things a person can read (F10).
 *
 * Every money column arrives from Eloquent as `mixed` — a table column's state
 * is whatever the row held — and money is only ever formatted at this edge
 * (D-4). Narrowing it in one place keeps the casts out of five resources and
 * makes "what happens to a null" a single decision rather than five.
 */
final class Displays
{
    private const CURRENCY = 'EGP';

    /**
     * `EGP 1,234.56` from signed minor units.
     *
     * A missing column reads as zero rather than blank: an instructor with no
     * snapshot row has earned nothing, and "—" would suggest we do not know.
     */
    public static function money(mixed $minor): string
    {
        return Money::of(self::minor($minor), self::CURRENCY)->format();
    }

    public static function minor(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A snake_case status as prose: `completed_with_pending` reads as
     * "Completed with pending".
     *
     * Done here rather than by giving the enums a Filament `HasLabel`
     * interface: the enums are domain vocabulary shared by commands, jobs and
     * the ledger, and none of those should have to know the panel exists.
     */
    public static function status(string $value): string
    {
        return Str::ucfirst(Str::replace('_', ' ', $value));
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
