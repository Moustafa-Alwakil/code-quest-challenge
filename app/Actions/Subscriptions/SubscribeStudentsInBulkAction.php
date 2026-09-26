<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Services\AccrualService;
use App\Services\LedgerService;
use App\Services\SubscriptionService;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Subscriptions\BulkTerm;
use Illuminate\Support\Facades\DB;

/**
 * The batch variant of `SubscribeStudentAction`, for bulk ingestion (F02's
 * `ScaleSeeder`).
 *
 * It writes exactly what the single-term action writes — a subscription, its
 * payment, a `payment_received` posting and a full accrual schedule — and it
 * writes it through the same Services and the same columns. What it does not do
 * is one transaction per term: at fifty thousand terms that is fifty thousand
 * round trips, and a scale seeder nobody can wait for teaches nothing about
 * scale.
 *
 * **Not a replacement for the real entry point.** It exists so a benchmark can
 * be built; a payment arriving from a gateway still goes through
 * `SubscribeStudentAction`, where the replay check and the unique-index race
 * are what make it safe. This trades that safety for throughput and says so:
 * it inserts rather than `insertOrIgnore`s, and the caller is responsible for
 * handing it terms that do not exist yet.
 */
final class SubscribeStudentsInBulkAction
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private AccrualService $accrual,
        private LedgerService $ledger,
    ) {}

    /**
     * @param  list<BulkTerm> $terms one chunk; the caller decides how big
     * @return int            terms written
     */
    public function __invoke(array $terms): int
    {
        if ($terms === []) {
            return 0;
        }

        return DB::transaction(function () use ($terms): int {
            $firstId = $this->subscriptions->insertTermsInBulk($terms);

            $this->accrual->insertSchedulesInBulk($this->scheduleRows($terms, $firstId));

            /**
             * The liability every term is born with. No balance deltas: nothing
             * has been recognized, so no instructor's snapshot moves — which is
             * exactly the case `postMany()` exists for.
             */
            $this->ledger->postMany($this->postings($terms, $firstId));

            return count($terms);
        });
    }

    /**
     * @param  list<BulkTerm>             $terms
     * @return list<array<string, mixed>>
     */
    private function scheduleRows(array $terms, int $firstId): array
    {
        $rows = [];

        foreach ($terms as $offset => $term) {
            $subscriptionId = $firstId + $offset;

            foreach ($term->schedule->periods as $period) {
                $rows[] = [
                    'subscription_id' => $subscriptionId,
                    'sequence' => $period->sequence,
                    'period_start' => $period->periodStart->toDateString(),
                    'period_end' => $period->periodEnd->toDateString(),
                    'days' => $period->days,
                    'gross_minor' => $period->gross->minor,
                    'status' => AccrualPeriodStatus::SCHEDULED->value,
                ];
            }
        }

        return $rows;
    }

    /**
     * One `payment_received` transaction per term, keyed by the payment's id.
     *
     * The payment ids follow the subscription ids for the same reason and by
     * the same guarantee — one multi-row insert, consecutive autoincrement —
     * so the reference each posting is keyed by is known without reading back.
     *
     * @param  list<BulkTerm>          $terms
     * @return list<LedgerTransaction>
     */
    private function postings(array $terms, int $firstId): array
    {
        $firstPaymentId = $this->firstPaymentIdFor($firstId);

        $transactions = [];

        foreach ($terms as $offset => $term) {
            $transactions[] = LedgerTransaction::of(
                LedgerEntryType::PAYMENT_RECEIVED,
                'payment',
                $firstPaymentId + $offset,
                LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, $term->price),
                LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, $firstId + $offset, $term->price),
            );
        }

        return $transactions;
    }

    /**
     * Read once per chunk rather than derived, because the payments were
     * written by a second statement and their run of ids starts wherever that
     * statement began — which this run may not be the first to have touched.
     */
    private function firstPaymentIdFor(int $firstSubscriptionId): int
    {
        $id = DB::table('payments')->where('subscription_id', $firstSubscriptionId)->value('id');

        return is_numeric($id) ? (int) $id : 0;
    }
}
