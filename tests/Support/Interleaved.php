<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Two MySQL sessions against the one test database, interleaved by hand (R11).
 *
 * `pcntl_fork` would make the race real but non-deterministic. Two named
 * connections driven step by step make it deterministic *and* real: session B's
 * statement genuinely waits on a lock session A holds, and the low
 * `innodb_lock_wait_timeout` turns that wait into an observable error instead
 * of a hang.
 *
 * The application code under test reaches for `DB::` and for Eloquent, both of
 * which resolve the *default* connection, so running a step "on" a session
 * means swapping the default for the duration of the step.
 */
final class Interleaved
{
    public const SESSION_A = 'ledger_a';

    public const SESSION_B = 'ledger_b';

    /**
     * How long session B waits before reporting the lock it cannot take.
     *
     * Long enough that a slow machine does not report a timeout that never
     * happened, short enough that a genuine deadlock fails the suite quickly.
     */
    private const LOCK_WAIT_SECONDS = 3;

    /**
     * Clones the test connection twice, so both sessions speak to the same
     * database but hold separate transactions.
     */
    public static function open(): void
    {
        /** @var array<string, mixed> $base */
        $base = config('database.connections.mysql');

        foreach ([self::SESSION_A, self::SESSION_B] as $session) {
            config()->set("database.connections.{$session}", $base);
            DB::purge($session);
            DB::connection($session)->statement(
                'SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_SECONDS
            );
        }
    }

    /**
     * Rolls back anything still open, so a failing test cannot leave a lock
     * behind that blocks the next test's truncation.
     */
    public static function close(): void
    {
        foreach ([self::SESSION_A, self::SESSION_B] as $session) {
            try {
                $connection = DB::connection($session);

                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // The session was never opened, or is already gone: nothing to unwind.
            }

            DB::purge($session);
        }
    }

    public static function session(string $name): Connection
    {
        return DB::connection($name);
    }

    /**
     * Runs one step as the given session, with the default connection swapped
     * so `DB::` and Eloquent inside the application land on it too.
     *
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn $step
     * @return TReturn
     */
    public static function as(string $session, Closure $step): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($session);

        try {
            return $step(DB::connection($session));
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /**
     * Runs a step that is expected to block, and returns the lock-wait error.
     *
     * Returns null when the step completed instead — which is the interesting
     * failure: it means nothing serialized the two sessions.
     *
     * PDOException, not QueryException, because the type depends on how deep the
     * blocked statement was: Laravel wraps a concurrency error raised inside a
     * *nested* transaction (an Action's `DB::transaction` within a session
     * transaction opened here) in `Illuminate\Database\DeadlockException`, which
     * extends PDOException rather than QueryException. QueryException extends
     * PDOException too, so both arrive here.
     */
    public static function expectBlocked(string $session, Closure $step): ?PDOException
    {
        return self::as($session, function (Connection $connection) use ($step): ?PDOException {
            try {
                $step($connection);

                return null;
            } catch (PDOException $blocked) {
                return $blocked;
            } finally {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
        });
    }
}
