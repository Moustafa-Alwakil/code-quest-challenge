<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A paid term: who bought what, for how long, at what price (F04, D-1).
     *
     * `price_minor` and `currency` are snapshotted from the plan at purchase, so
     * a later price change cannot rewrite money that has already been taken.
     * `status` is cosmetic — every piastre is driven by `accrual_periods`, never
     * by this column — which is why no money path reads it.
     *
     * Term dates are DATEs in UTC: a purchase at 23:00 counts from that date,
     * and the whole accrual schedule is then date arithmetic with no clock in
     * it (R10). Cairo-local business days are a documented limitation.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Plan::class)->constrained()->restrictOnDelete();

            /** active | refunded | expired — a string plus a PHP cast, so a new case is a code change, not an ALTER TABLE. */
            $table->string('status', 16);

            /** = the payment's captured_at, as a UTC date: the anchor every period boundary is computed from. */
            $table->date('term_start');

            /** Exclusive, like every period_end: term_start + interval_months, no-overflow. */
            $table->date('term_end');

            /** Σ of the periods' days. 366 at most today; smallint leaves room for a longer plan. */
            $table->unsignedSmallInteger('term_days');

            /** Signed BIGINT minor units, snapshotted at purchase (D-4). */
            $table->bigInteger('price_minor');
            $table->char('currency', 3);

            /** Set by a refund (F09); null for a subscription that is simply running or expired. */
            $table->timestamp('canceled_at')->nullable();

            $table->timestamps();

            /** The daily expiry sweep: `status = active AND term_end <= ?`. */
            $table->index(['status', 'term_end'], 'subscriptions_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
