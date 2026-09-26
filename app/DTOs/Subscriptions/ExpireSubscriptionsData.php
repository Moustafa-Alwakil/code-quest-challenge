<?php

declare(strict_types=1);

namespace App\DTOs\Subscriptions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What an expiry sweep was asked to close off (F04).
 *
 * The console's strings are parsed here, once. A future `--as-of` is allowed:
 * the sweep only moves a cosmetic status and touches no money, which is exactly
 * why F05's recognition run — which does — refuses one.
 */
final readonly class ExpireSubscriptionsData
{
    /**
     * Rows per conditional UPDATE. Bounded so one sweep never locks an
     * unbounded number of rows in a single transaction.
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    private function __construct(
        public CarbonImmutable $asOf,
        public int $chunkSize,
    ) {}

    /**
     * @param string|null $asOf  the raw `--as-of=` option; today when absent
     * @param string|null $chunk the raw `--chunk=` option
     *
     * @throws InvalidArgumentException on an unparseable date or a chunk below 1
     */
    public static function fromCommand(?string $asOf = null, ?string $chunk = null): self
    {
        $date = $asOf === null || mb_trim($asOf) === ''
            ? CarbonImmutable::now()->startOfDay()
            : self::parseDate($asOf);

        $chunkSize = self::DEFAULT_CHUNK_SIZE;

        if ($chunk !== null && mb_trim($chunk) !== '') {
            if (ctype_digit($chunk) === false || (int) $chunk < 1) {
                throw new InvalidArgumentException("--chunk must be a positive integer, got '{$chunk}'.");
            }

            $chunkSize = (int) $chunk;
        }

        return new self($date, $chunkSize);
    }

    private static function parseDate(string $asOf): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(mb_trim($asOf))->startOfDay();
        } catch (Throwable $invalid) {
            throw new InvalidArgumentException("--as-of must be a date, got '{$asOf}'.", previous: $invalid);
        }
    }
}
