<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Subscriptions\SubscribeStudentAction;
use App\DTOs\Subscriptions\SubscribeStudentData;
use App\Models\AccrualPeriod;
use App\Models\Course;
use App\Models\Engagement;
use App\Models\Enrolment;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The demo dataset: small, deterministic, and shaped around the five scenarios
 * the video walks through (F02).
 *
 * **Every subscription is created through `SubscribeStudentAction`.** Never a
 * raw insert — so the ledger is born consistent, the accrual schedule is real,
 * and `ledger:verify` at the end is a genuine check rather than a formality. A
 * seeder that wrote rows directly would be seeding a state the application
 * cannot produce.
 *
 * **It does not recognize anything.** `ledger:accrue` is run on camera, which
 * is where the rounding and zero-engagement scenarios actually land. What this
 * leaves behind is five terms, their schedules, their engagement rollups and a
 * deferred-revenue liability for each — the setup, not the punchline.
 *
 * Determinism is by construction rather than by luck: every amount and every
 * engagement weight the scenarios depend on is written out here, and the
 * generator is seeded so the names and slugs around them are stable too.
 */
final class DemoSeeder extends Seeder
{
    /**
     * The panel login, fixed so the README and the video can name it.
     */
    public const ADMIN_EMAIL = 'admin@revenue.test';

    public const ADMIN_PASSWORD = 'password';

    /**
     * Fixed so two runs from clean produce the same database, which is what
     * makes "run it twice and compare the balances" a real test.
     */
    private const SEED = 20260926;

    /** @var list<Instructor> */
    private array $instructors = [];

    /** @var list<User> */
    private array $students = [];

    /** @var array<int, list<int>> instructor id => course ids */
    private array $coursesByInstructor = [];

    public function run(): void
    {
        $this->seedRandomness();

        $this->call(PlanSeeder::class);

        $this->seedAdmin();
        $this->seedInstructors();
        $this->seedCourses();
        $this->seedStudents();

        $this->seedScenarios();
        $this->seedBackgroundTerms();

        Str::createRandomStringsNormally();

        $this->assertLedgerIsConsistent();
    }

    /**
     * The seeder checks its own work, and fails loudly if it is wrong.
     *
     * F02 asks the seeder to end by running `ledger:verify`. Printing the
     * result would make it a thing to notice; throwing makes it a thing that
     * cannot be ignored — a demo database whose ledger does not balance is
     * worse than no demo database, because every scenario recorded against it
     * would be recorded against a lie.
     *
     * @throws RuntimeException when the seeded ledger does not verify
     */
    private function assertLedgerIsConsistent(): void
    {
        if (Artisan::call('ledger:verify') !== 0) {
            throw new RuntimeException(
                'DemoSeeder produced a ledger that does not verify: '.Artisan::output()
            );
        }
    }

    /**
     * Seeds Faker, and routes `Str::random()` through it.
     *
     * Slugs and account references carry no money, but a demo whose URLs change
     * between runs is a demo you cannot script — and the acceptance criterion
     * is that two runs from clean are the same database.
     */
    private function seedRandomness(): void
    {
        app(Generator::class)->seed(self::SEED);

        Str::createRandomStringsUsing(
            static fn (int $length = 16): string => app(Generator::class)->regexify('[A-Za-z0-9]{'.$length.'}')
        );
    }

    private function seedAdmin(): void
    {
        User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Revenue Admin',
                'is_admin' => true,
                'password' => bcrypt(self::ADMIN_PASSWORD),
                'email_verified_at' => CarbonImmutable::now(),
            ],
        );
    }

    /**
     * Five instructors, named rather than faked, because the video refers to
     * them by name and a different name every run is a re-recorded take.
     *
     * The fifth is deliberately kept out of every scenario but S5: their whole
     * lifetime earnings have to stay under the payout minimum for the
     * carry-forward demo to work, and a single appearance elsewhere would push
     * them over it.
     */
    private function seedInstructors(): void
    {
        foreach ([
            'Amina Hassan',
            'Bilal Farouk',
            'Carmen Diaz',
            'Dalia Nour',
            'Emeka Obi',
        ] as $index => $name) {
            $this->instructors[] = Instructor::query()->updateOrCreate(
                ['email' => Str::slug($name).'@instructors.test'],
                [
                    'name' => $name,
                    'payout_account_ref' => 'acct_demo_'.($index + 1),
                ],
            );
        }
    }

    /**
     * Twelve courses, spread so the first four instructors each own enough to
     * be enrolled in independently.
     */
    private function seedCourses(): void
    {
        $titles = [
            'Foundations of Accounting', 'Double-Entry in Practice', 'Reading a Balance Sheet',
            'Egyptian Tax Essentials', 'Cash Flow for Founders', 'Payroll from Scratch',
            'Auditing Fundamentals', 'Financial Modelling', 'Cost Accounting',
            'Treasury Operations', 'Revenue Recognition', 'Closing the Books',
        ];

        foreach ($titles as $index => $title) {
            $instructor = $this->instructors[$index % 5];

            $course = Course::query()->updateOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'instructor_id' => $instructor->id,
                    'title' => $title,
                    'published_at' => CarbonImmutable::now()->subYear(),
                ],
            );

            $this->coursesByInstructor[$instructor->id][] = $course->id;
        }
    }

    private function seedStudents(): void
    {
        foreach (range(1, 20) as $number) {
            $this->students[] = User::query()->updateOrCreate(
                ['email' => "student{$number}@students.test"],
                [
                    'name' => app(Generator::class)->name(),
                    'is_admin' => false,
                    'password' => bcrypt(self::ADMIN_PASSWORD),
                    'email_verified_at' => CarbonImmutable::now(),
                ],
            );
        }
    }

    /**
     * The five curated terms. Each one exists to make a single decision
     * visible, and their engagement is written out rather than generated.
     */
    private function seedScenarios(): void
    {
        /**
         * S1 — rounding (D-5). Equal engagement across three instructors, so
         * every period's pool divides three ways with a remainder and the
         * lowest instructor id takes the extra piastre.
         */
        $this->subscribe(
            student: $this->students[0],
            planKey: 'annual',
            externalRef: 'ch_demo_s1',
            startedMonthsAgo: 11,
            engagement: [
                $this->instructors[0]->id => 3,
                $this->instructors[1]->id => 3,
                $this->instructors[2]->id => 3,
            ],
        );

        /**
         * S2 — zero engagement (D-3). Enrolled, and never opened anything: the
         * platform retains the whole period rather than inventing a beneficiary.
         */
        $this->subscribe(
            student: $this->students[1],
            planKey: 'monthly',
            externalRef: 'ch_demo_s2',
            startedMonthsAgo: 2,
            engagement: [],
        );

        /**
         * S3 — the refund demo (F09). Five months into an annual term, so seven
         * periods are still unearned and a pro-rata refund costs no instructor
         * anything.
         */
        $this->subscribe(
            student: $this->students[2],
            planKey: 'annual',
            externalRef: 'ch_demo_s3',
            startedMonthsAgo: 5,
            engagement: [
                $this->instructors[0]->id => 300,
                $this->instructors[3]->id => 100,
            ],
        );

        /** S4 — one instructor takes the whole pool, with nothing to round. */
        $this->subscribe(
            student: $this->students[3],
            planKey: 'quarterly',
            externalRef: 'ch_demo_s4',
            startedMonthsAgo: 4,
            engagement: [
                $this->instructors[1]->id => 240,
            ],
        );

        /**
         * S5 — carry-forward (D-7). Emeka appears here and nowhere else, with a
         * sliver of one month's engagement, so his lifetime earnings stay below
         * `minimum_payout_minor` and `payouts:run` keeps skipping him.
         */
        $this->subscribe(
            student: $this->students[4],
            planKey: 'monthly',
            externalRef: 'ch_demo_s5',
            startedMonthsAgo: 2,
            engagement: [
                $this->instructors[2]->id => 599,
                $this->instructors[4]->id => 1,
            ],
        );
    }

    /**
     * A handful of ordinary terms so the admin screen has something to page
     * through, with engagement generated the way F02 describes: instructors of
     * courses the student is actually enrolled in, and about one period in ten
     * left dormant.
     */
    private function seedBackgroundTerms(): void
    {
        $faker = app(Generator::class);
        $planKeys = ['monthly', 'quarterly', 'annual'];

        foreach (range(0, 7) as $index) {
            $student = $this->students[5 + $index];
            $planKey = $planKeys[$index % 3];

            /** Never Emeka: S5 depends on his lifetime total staying small. */
            /** @var list<int> $teaching */
            $teaching = $faker->randomElements([0, 1, 2, 3], $faker->numberBetween(1, 3));

            $engagement = [];

            foreach ($teaching as $position) {
                $engagement[$this->instructors[$position]->id] = $faker->numberBetween(1, 600);
            }

            $this->subscribe(
                student: $student,
                planKey: $planKey,
                externalRef: 'ch_demo_bg_'.($index + 1),
                startedMonthsAgo: $faker->numberBetween(1, 11),
                engagement: $engagement,
                dormantEveryNth: 10,
            );
        }
    }

    /**
     * Records one captured payment through the real entry point, enrols the
     * student with the instructors they will be credited to, and writes the
     * engagement rollup for every period of the term.
     *
     * @param array<int, int> $engagement instructor id => units per period
     */
    private function subscribe(
        User $student,
        string $planKey,
        string $externalRef,
        int $startedMonthsAgo,
        array $engagement,
        int $dormantEveryNth = 0,
    ): void {
        $plan = Plan::query()->where('key', $planKey)->firstOrFail();

        $this->enrol($student, array_keys($engagement));

        $outcome = app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
            userId: $student->id,
            planId: $plan->id,
            externalRef: $externalRef,
            amountMinor: $plan->price_minor,
            currency: $plan->currency,
            capturedAt: CarbonImmutable::now()->subMonths($startedMonthsAgo),
        ));

        if ($engagement === []) {
            return;
        }

        $rows = [];

        foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->orderBy('sequence')->get() as $period) {
            /** ~10% of periods have nobody watching anything (F02). */
            if ($dormantEveryNth > 0 && $period->sequence % $dormantEveryNth === 0) {
                continue;
            }

            foreach ($engagement as $instructorId => $units) {
                $rows[] = [
                    'subscription_id' => $outcome->subscriptionId,
                    'period_start' => $period->period_start->toDateString(),
                    'instructor_id' => $instructorId,
                    'units' => $units,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        /** One statement, and `insertOrIgnore`, so re-seeding writes nothing twice. */
        Engagement::query()->insertOrIgnore($rows);
    }

    /**
     * Engagement is only credible for courses the student is enrolled in, so
     * the enrolments come first and follow from the engagement plan.
     *
     * @param list<int> $instructorIds
     */
    private function enrol(User $student, array $instructorIds): void
    {
        foreach ($instructorIds as $instructorId) {
            foreach ($this->coursesByInstructor[$instructorId] ?? [] as $courseId) {
                Enrolment::query()->updateOrCreate(
                    ['user_id' => $student->id, 'course_id' => $courseId],
                    ['enrolled_at' => CarbonImmutable::now()->subYear()],
                );
            }
        }
    }
}
