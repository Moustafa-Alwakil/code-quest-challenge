<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of each accrual period a subscription spent with each
     * instructor — the weights recognition divides the pool by (D-2, F02).
     *
     * A pre-aggregated rollup, not raw view events: in production a nightly job
     * would fold events into this table, and here it is seeded. Recognition
     * reads one small row set per subscription-period, which is what keeps the
     * allocator's input bounded however many events produced it.
     *
     * Keyed by `(subscription_id, period_start)` rather than by
     * `accrual_period_id`, because engagement is recorded against a calendar
     * window that exists whether or not a period row has been scheduled for it.
     * That pair is `accrual_periods`' own composite unique key, so the join is
     * exact without a foreign key to a column that is not its primary one.
     */
    public function up(): void
    {
        Schema::create('subscription_period_engagement', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Subscription::class)->constrained()->cascadeOnDelete();

            /** The period's inclusive start — half of `accrual_periods`' natural key. */
            $table->date('period_start');

            $table->foreignIdFor(Instructor::class)->constrained()->restrictOnDelete();

            /** Consumption minutes. The allocator's weight, never money. */
            $table->unsignedInteger('units');

            /**
             * MySQL maintains this: the rows arrive by bulk `insertOrIgnore`
             * from a seeder or a rollup job, which fires no model events.
             */
            $table->timestamp('created_at')->useCurrent();

            /** One rollup row per instructor per period: recording it twice is a no-op. */
            $table->unique(
                ['subscription_id', 'period_start', 'instructor_id'],
                'engagement_period_instructor_unique',
            );

            $table->index('instructor_id', 'engagement_instructor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_period_engagement');
    }
};
