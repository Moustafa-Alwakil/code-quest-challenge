<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\CurrencyMismatchException;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * An immutable amount of money in integer minor units (D-4).
 *
 * Deterministic and I/O-free: no config, no clock, no database. `strict_types`
 * means a float passed by accident is a TypeError, not a silent truncation.
 *
 * String handling goes through Illuminate\Support\Str and number formatting
 * through Illuminate\Support\Number; native functions are used only where
 * those have no equivalent (named capture groups, sprintf, intdiv, abs).
 */
final readonly class Money
{
    /**
     * Minor units per major unit. EGP, like most currencies here, has 100.
     */
    private const SUBUNITS = 100;

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    /**
     * Build from a database BIGINT or any already-integral amount.
     */
    public static function of(int $minor, string $currency): self
    {
        return new self($minor, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    /**
     * Parse a human-entered decimal string — "123.45" becomes 12345.
     *
     * A string, never a float: `floatval('0.29') * 100` is 28.999999999999996,
     * and that is exactly the class of bug this object exists to prevent.
     */
    public static function fromString(string $amount, string $currency): self
    {
        $trimmed = Str::trim($amount);

        // Native preg_match: the parse needs named capture groups, which Str::match does not expose.
        if (preg_match('/^(?<sign>[+-]?)(?<major>\d+)(?:\.(?<fraction>\d{1,2}))?$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException("Cannot parse '{$amount}' as a decimal amount; expected digits with at most two decimal places.");
        }

        $fraction = Str::padRight($matches['fraction'] ?? '', 2, '0');
        $minor = (int) $matches['major'] * self::SUBUNITS + (int) $fraction;

        return new self($matches['sign'] === '-' ? -$minor : $minor, self::normalizeCurrency($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /**
     * Display only. Nothing inside the domain calls this — amounts travel as
     * integers right up to the UI edge.
     */
    public function format(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return sprintf(
            '%s%s %s.%02d',
            $sign,
            $this->currency,
            Number::format(intdiv($absolute, self::SUBUNITS), 0),
            $absolute % self::SUBUNITS,
        );
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $normalized = Str::upper(Str::trim($currency));

        if (! Str::isMatch('/^[A-Z]{3}$/', $normalized)) {
            throw new InvalidArgumentException("'{$currency}' is not a three-letter currency code.");
        }

        return $normalized;
    }
}
