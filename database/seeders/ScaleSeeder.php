<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Subscriptions\SubscribeStudentsInBulkAction;
use App\Models\Course;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Accrual\AccrualSchedule;
use App\Support\Money;
use App\Support\Subscriptions\BulkTerm;
use Carbon\CarbonImmutable;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The dataset the scale claims are measured against (F02, PLAN §12).
 *
 * The brief says "tens of millions of records"; this is what makes that
 * concrete enough to time. Defaults to 50 000 terms with roughly a million
 * engagement rows, which is the shape of the problem without being the size of
 * a production database.
 *
 * **Everything is written in chunks, never per row.** A factory loop over
 * 50 000 terms takes hours; this takes minutes, and the difference is entirely
 * in doing one statement per thousand rows rather than one per row. That is the
 * same rule `.ai/rules/services.md` states for the application itself, applied
 * where it is most visible.
 *
 * It writes the terms through `SubscribeStudentsInBulkAction`, so payments,
 * ledger postings and accrual schedules are the real ones, and finishes by
 * verifying the ledger it produced — at this size an error is not something
 * anyone would spot by reading.
 *
 * Sized from `config/scale.php`, so a laptop, CI and a demo machine can differ
 * without editing code: SCALE_SUBSCRIPTIONS, SCALE_INSTRUCTORS, SCALE_STUDENTS
 * and SCALE_CHUNK.
 */
final class ScaleSeeder extends Seeder
{
    private const SEED = 20260926;

    public function run(): void
    {
        $faker = app(Generator::class);
        $faker->seed(self::SEED);

        $this->assertNothingToCollideWith();

        $plans = Plan::query()->get();

        if ($plans->isEmpty()) {
            $this->call(PlanSeeder::class);
            $plans = Plan::query()->get();
        }

        $startedAt = microtime(true);

        $instructorIds = $this->seedInstructors($faker);
        $studentIds = $this->seedStudents($faker);

        $this->report('catalog', $startedAt);

        $termsAt = microtime(true);
        $written = $this->seedTerms($faker, array_values($plans->all()), $studentIds);
        $this->report("{$written} terms", $termsAt);

        $engagementAt = microtime(true);
        $rows = $this->seedEngagement($faker, $instructorIds);
        $this->report("{$rows} engagement rows", $engagementAt);

        $verifyAt = microtime(true);

        if (Artisan::call('ledger:verify') !== 0) {
            throw new RuntimeException('ScaleSeeder produced a ledger that does not verify: '.Artisan::output());
        }

        $this->report('ledger:verify', $verifyAt);
        $this->report('total', $startedAt);
    }

    /**
     * @return int<1, max>
     */
    private static function chunk(): int
    {
        return self::setting('chunk');
    }

    /**
     * @return int<1, max>
     */
    private static function setting(string $key): int
    {
        $value = config("scale.{$key}");

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    /**
     * The bulk path derives ids from a run of consecutive autoincrements, so it
     * cannot be layered on top of terms that already exist.
     *
     * Refusing is the honest answer. Silently appending would produce a second
     * set of terms whose payments collide on `external_ref`, and the failure
     * would surface thousands of rows later as an integrity error nobody could
     * place.
     */
    private function assertNothingToCollideWith(): void
    {
        if (Subscription::query()->exists()) {
            throw new RuntimeException(
                'ScaleSeeder writes in bulk and needs an empty subscriptions table. '
                .'Run `php artisan migrate:fresh` first, then seed.'
            );
        }
    }

    /**
     * @return list<int>
     */
    private function seedInstructors(Generator $faker): array
    {
        $count = self::setting('instructors');
        $rows = [];

        foreach (range(1, $count) as $number) {
            $rows[] = [
                'name' => $faker->name(),
                'email' => "scale.instructor{$number}@instructors.test",
                'payout_account_ref' => "acct_scale_{$number}",
                'status' => 'active',
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ];
        }

        foreach (array_chunk($rows, self::chunk()) as $chunk) {
            DB::table('instructors')->insertOrIgnore($chunk);
        }

        /** One course each, so engagement has something coherent to hang off. */
        /** @var list<int> $instructorIds */
        $instructorIds = Instructor::query()->orderBy('id')->pluck('id')->all();
        $courses = [];

        foreach ($instructorIds as $index => $instructorId) {
            $courses[] = [
                'instructor_id' => $instructorId,
                'title' => "Scale Course {$index}",
                'slug' => "scale-course-{$index}",
                'published_at' => CarbonImmutable::now()->subYear(),
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ];
        }

        foreach (array_chunk($courses, self::chunk()) as $chunk) {
            DB::table('courses')->insertOrIgnore($chunk);
        }

        return $instructorIds;
    }

    /**
     * @return list<int>
     */
    private function seedStudents(Generator $faker): array
    {
        $count = self::setting('students');
        $password = bcrypt('password');
        $rows = [];

        foreach (range(1, $count) as $number) {
            $rows[] = [
                'name' => $faker->name(),
                'email' => "scale.student{$number}@students.test",
                'is_admin' => false,
                'password' => $password,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ];
        }

        foreach (array_chunk($rows, self::chunk()) as $chunk) {
            DB::table('users')->insertOrIgnore($chunk);
        }

        /** @var list<int> $ids */
        $ids = User::query()->where('is_admin', false)->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * Terms spread over the past year, so periods close on every day of it
     * rather than all at once — which is the point PLAN §12 makes about
     * recognition load being naturally flat.
     *
     * @param  list<Plan> $plans
     * @param  list<int>  $studentIds
     * @return int        terms written
     */
    private function seedTerms(Generator $faker, array $plans, array $studentIds): int
    {
        $target = self::setting('subscriptions');
        $subscribe = app(SubscribeStudentsInBulkAction::class);

        $written = 0;
        $chunk = [];

        foreach (range(1, $target) as $number) {
            $plan = $plans[$number % count($plans)];
            $capturedAt = CarbonImmutable::now()->subDays($faker->numberBetween(1, 365));
            $price = Money::of($plan->price_minor, $plan->currency);

            $chunk[] = new BulkTerm(
                userId: $studentIds[$number % count($studentIds)],
                planId: $plan->id,
                externalRef: "ch_scale_{$number}",
                price: $price,
                capturedAt: $capturedAt,
                schedule: AccrualSchedule::forTerm($capturedAt, $plan->interval_months, $price),
            );

            if (count($chunk) < self::chunk()) {
                continue;
            }

            $written += $subscribe($chunk);
            $chunk = [];
        }

        return $written + $subscribe($chunk);
    }

    /**
     * One to six instructors per subscription-period, weighted 1-600 minutes,
     * with about a tenth of periods left dormant (F02).
     *
     * Streamed by keyset over the periods rather than loaded: a million rows
     * built in memory is how a seeder runs the machine out of it.
     *
     * @param  list<int> $instructorIds
     * @return int       engagement rows written
     */
    private function seedEngagement(Generator $faker, array $instructorIds): int
    {
        $written = 0;
        $buffer = [];
        $lastId = 0;
        $instructorCount = count($instructorIds);

        while (true) {
            $periods = DB::table('accrual_periods')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::chunk())
                ->get(['id', 'subscription_id', 'period_start']);

            if ($periods->isEmpty()) {
                break;
            }

            foreach ($periods as $period) {
                $lastId = is_numeric($period->id) ? (int) $period->id : $lastId;

                /** ~10% dormant: the D-3 case has to exist at scale too. */
                if ($faker->numberBetween(1, 10) === 1) {
                    continue;
                }

                $teaching = $faker->numberBetween(1, 6);
                $offset = $faker->numberBetween(0, $instructorCount - 1);

                foreach (range(0, $teaching - 1) as $step) {
                    $buffer[] = [
                        'subscription_id' => $period->subscription_id,
                        'period_start' => $period->period_start,
                        'instructor_id' => $instructorIds[($offset + $step) % $instructorCount],
                        'units' => $faker->numberBetween(1, 600),
                    ];
                }
            }

            if (count($buffer) >= self::chunk()) {
                $written += $this->flushEngagement($buffer);
                $buffer = [];
            }
        }

        return $written + $this->flushEngagement($buffer);
    }

    /**
     * @param list<array<string, mixed>> $buffer
     */
    private function flushEngagement(array $buffer): int
    {
        $written = 0;

        foreach (array_chunk($buffer, self::chunk()) as $chunk) {
            $written += Engagement::query()->insertOrIgnore($chunk);
        }

        return $written;
    }

    /**
     * The timings are the point of this seeder, so they go to the console that
     * invoked it rather than to stdout — captured when a test runs it, printed
     * when a person does.
     */
    private function report(string $what, float $startedAt): void
    {
        $seconds = round(microtime(true) - $startedAt, 2);

        $this->command->getOutput()->writeln("<info>ScaleSeeder: {$what} in {$seconds}s</info>");
    }
}
