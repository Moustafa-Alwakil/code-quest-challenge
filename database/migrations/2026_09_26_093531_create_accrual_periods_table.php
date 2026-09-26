<?php

declare(strict_types=1);

use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The accrual schedule: when each slice of a term's price is earned (D-1).
     *
     * Written once, at purchase, and never regenerated — only F09 may cancel or
     * truncate a period. Σ `gross_minor` per subscription equals `price_minor`
     * exactly (invariant I8), because the split goes through
     * `Allocator::largestRemainder()` over each period's day count.
     *
     * Periods are half-open `[period_start, period_end)`, so consecutive
     * periods share a date and no day is counted twice. Both UNIQUE indexes
     * make re-running the scheduler an `insertOrIgnore` no-op.
     */
    public function up(): void
    {
        Schema::create('accrual_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Subscription::class)->constrained()->cascadeOnDelete();

            /** 1...interval_months, in date order. */
            $table->unsignedTinyInteger('sequence');

            $table->date('period_start');

            /** Exclusive: this is the next period's start, and the term's end for the last one. */
            $table->date('period_end');

            /** period_end - period_start, and the weight the price is split by. */
            $table->unsignedSmallInteger('days');

            $table->bigInteger('gross_minor');

            /**
             * The instructor pool and the platform's cut of `gross_minor`, set
             * at recognition (F05). Null while the period is still scheduled:
             * a zero would claim a split had been computed and come to nothing.
             */
            $table->bigInteger('pool_minor')->nullable();
            $table->bigInteger('platform_minor')->nullable();

            /** scheduled | recognized | cancelled. */
            $table->string('status', 16);

            $table->timestamp('recognized_at')->nullable();

            /**
             * MySQL maintains these, because the rows are written by a multi-row
             * `insertOrIgnore` and updated by F05's conditional `UPDATE` —
             * neither of which goes through a model.
             */
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['subscription_id', 'period_start'], 'accrual_periods_start_unique');
            $table->unique(['subscription_id', 'sequence'], 'accrual_periods_sequence_unique');

            /** The recognition sweep (F05): `status = scheduled AND period_end <= ?`. */
            $table->index(['status', 'period_end'], 'accrual_periods_recognition_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accrual_periods');
    }
};
