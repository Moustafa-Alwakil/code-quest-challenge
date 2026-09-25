# Layering — responsibilities, examples, anti-patterns

## Entry points

Four kinds, one contract. Each of them: receives input, validates it, authorizes it, builds a DTO,
invokes an Action, handles the result in whatever way suits its medium.

### Livewire component

None are planned: the student flow (D‑11) was withdrawn (R25), and the only UI is the read-only
Filament screen. If a component is ever added, it follows the same contract as a command below —
validate, authorize, build the DTO, invoke the Action, handle the result — and keeps only UI
concerns (loading state, notifications, redirects, error wording) for itself.

### Artisan command

```php
public function handle(RunPayoutsAction $action): int
{
    $lock = Cache::lock("payouts:run:{$this->runKey()}", 600);

    if (! $lock->get()) {
        $this->info('A run is already in progress.');

        return self::SUCCESS;      // an optimization, not a guard
    }

    try {
        $summary = $action(RunPayoutsData::fromCommand(
            runKey: $this->runKey(),
            minimumMinor: (int) $this->option('min-amount'),
            dryRun: (bool) $this->option('dry-run'),
        ));
    } finally {
        $lock->release();
    }

    $this->table(['Instructor', 'Amount'], $summary->rows());

    return self::SUCCESS;
}
```

### Queued job

Scalar ids on the constructor, DTO rebuilt in `handle()`. A serialized DTO goes stale when a
deploy lands while the job is still queued.

```php
final class ProcessPayoutItemJob implements ShouldQueue, ShouldBeUnique
{
    public int $tries = 5;

    public function __construct(public readonly int $payoutItemId) {}

    public function uniqueId(): string
    {
        return (string) $this->payoutItemId;
    }

    public function handle(SubmitPayoutItemAction $action): void
    {
        $action(new SubmitPayoutItemData(payoutItemId: $this->payoutItemId));
    }

    public function failed(?Throwable $e): void
    {
        // terminal review state — never back to a state that could re-send the money
        app(FlagPayoutItemForReviewAction::class)(
            new FlagPayoutItemData($this->payoutItemId, $e?->getMessage())
        );
    }
}
```

Dispatch with `afterCommit()` so a job can never observe uncommitted state.

### Filament

Read-only resources. If an operator action ever moves money, it invokes an Action with a DTO,
exactly like a command.

## DTO

```php
final readonly class RecognizePeriodData
{
    public function __construct(
        public int $accrualPeriodId,
        public int $platformShareBps,
        public int $holdDays,
    ) {}

    public static function fromConfig(int $accrualPeriodId): self
    {
        return new self(
            accrualPeriodId: $accrualPeriodId,
            platformShareBps: (int) config('revenue.platform_share_bps'),
            holdDays: (int) config('revenue.hold_days'),
        );
    }
}
```

Note what the DTO does here: it pulls the policy dials out of `config/revenue.php` at the boundary,
so the Action is a pure function of its input and a test can vary the share without touching config.

**Not allowed inside a DTO:** an Eloquent model, a `Request`, a Livewire component, a `Collection`
of models, an `Illuminate` import of any kind.

## Action

```php
final class ReserveInstructorBalanceAction
{
    public function __construct(
        private readonly InstructorBalanceService $balances,
        private readonly PayoutRunService $runs,
        private readonly LedgerService $ledger,
    ) {}

    public function __invoke(ReserveBalanceData $data): ?PayoutItem
    {
        return DB::transaction(function () use ($data): ?PayoutItem {
            $balance = $this->balances->lockForUpdate($data->instructorId);

            if ($balance === null || $balance->available_minor < $data->minimumMinor) {
                return null;
            }

            $item = $this->runs->createItem(
                runId: $data->payoutRunId,
                instructorId: $data->instructorId,
                amountMinor: $balance->available_minor,
            );

            if ($item === null) {
                return null;   // unique (run, instructor) hit — already reserved
            }

            $this->ledger->postPayoutReserved($item);
            $this->balances->moveAvailableToReserved($data->instructorId, $item->amount_minor);

            return $item;
        });
    }
}
```

`DB::transaction` is the one `DB` call an Action may make. Everything inside it is a Service call.

**Anti-patterns:** a second public method; a `__invoke` that branches into three unrelated use
cases; `Model::query()` anywhere; an HTTP call inside the transaction closure.

## Service

```php
final class InstructorBalanceService
{
    public function lockForUpdate(int $instructorId): ?InstructorBalance
    {
        return InstructorBalance::query()
            ->where('instructor_id', $instructorId)
            ->lockForUpdate()
            ->first();
    }

    public function moveAvailableToReserved(int $instructorId, int $amountMinor): void
    {
        InstructorBalance::query()
            ->where('instructor_id', $instructorId)
            ->update([
                'available_minor' => DB::raw("available_minor - {$amountMinor}"),
                'reserved_minor'  => DB::raw("reserved_minor + {$amountMinor}"),
            ]);
    }
}
```

Atomic increments, never read-modify-write in PHP. Keyset pagination, never `OFFSET`. Chunked
`insertOrIgnore`, never a loop of `save()`.

**Anti-patterns:** opening a transaction (the Action owns it); dispatching a job; sending a
notification; taking a Livewire component or `Request` as a parameter; a method that exists only to
forward one call to `Model::create()`.

Services are grouped per aggregate. The expected set is roughly:
`AccrualService`, `LedgerService`, `InstructorBalanceService`, `PayoutRunService`,
`SubscriptionService`, `RefundService`. If you are about to create the seventh, check whether a
method on an existing one is the honest answer.

## Model

Relationships, `casts()`, scopes, genuine invariants. Money columns cast to `int`.

`LedgerEntry` is append-only — no `update()` path, no `delete()` path, no mutator that rewrites an
amount. Corrections are new, opposite entries.
