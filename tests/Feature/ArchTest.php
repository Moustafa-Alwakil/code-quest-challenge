<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| These make the layering contract in .ai/rules and docs/features/README.md
| (R13-R16) enforceable rather than advisory: a violation is a red test, not a
| code-review opinion. When one fails, the code is wrong — relaxing the rule is
| an architecture decision that goes through the architect and is recorded as a
| numbered R-n refinement.
|
| Rules for App\Actions, App\DTOs, App\Services, App\Jobs and
| App\Console\Commands are added as F03-F10 create those namespaces; Pest's
| arch() errors on a namespace that holds no classes.
|
| App\Support is ordinary Laravel code: it uses Illuminate\Support\Str and
| Number in preference to the native string and number functions. What still
| holds is that it performs no I/O — no database, no config, no clock — so its
| behaviour stays deterministic and property-testable.
|
*/

arch('support declares strict types')
    ->expect('App\Support')
    ->toUseStrictTypes();

arch('support classes are final')
    ->expect('App\Support')
    ->toBeFinal();

arch('support does not reach for state')
    ->expect('App\Support')
    ->not->toUse([
        'config',
        'now',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Cache',
        'Illuminate\Support\Facades\Date',
        'App\Models',
    ]);

arch('money never goes near a float')
    ->expect('App\Support')
    ->not->toUse(['floatval', 'doubleval', 'round', 'floor', 'ceil']);

arch('exceptions are named as exceptions')
    ->expect('App\Exceptions')
    ->toHaveSuffix('Exception')
    ->toBeFinal();

arch('debugging helpers never ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

/*
|--------------------------------------------------------------------------
| Layering (R16)
|--------------------------------------------------------------------------
|
| Added as F03 creates App\Actions, App\DTOs, App\Services and
| App\Console\Commands, and extended by F05 for App\Jobs. App\Livewire and
| App\Filament get their rules when those namespaces gain classes — arch()
| errors on an empty namespace.
|
*/

arch('actions are final invokable use cases')
    ->expect('App\Actions')
    ->toBeFinal()
    ->toHaveSuffix('Action')
    ->toHaveMethod('__invoke');

arch('actions do not query')
    ->expect('App\Actions')
    ->not->toUse([
        'App\Models',
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Database\Query\Builder',
        'Illuminate\Support\Facades\Schema',
    ]);

arch('dtos are readonly contracts')
    ->expect('App\DTOs')
    ->toBeReadonly()
    ->toBeFinal()
    ->not->toUse(['App\Models', 'Illuminate']);

/**
 * A job is an entry point like a command or a component (R15): it rebuilds a
 * DTO from scalars and invokes an Action. Reaching for a Service or a model
 * from `handle()` would put the use case in the queue payload's class rather
 * than in the layer the rest of the system shares.
 */
arch('jobs are queued entry points')
    ->expect('App\Jobs')
    ->toBeFinal()
    ->toHaveSuffix('Job')
    ->toImplement('Illuminate\\Contracts\\Queue\\ShouldQueue')
    ->not->toUse([
        'App\Services',
        'App\Models',
        'Illuminate\Support\Facades\DB',
    ]);

/**
 * A serialized payload goes stale when a deploy lands mid-queue, so a job
 * carries ids, scalars and the instant its dispatch resolved — never a DTO, a
 * model or a Carbon (R15, R27). This reads the constructor rather than the
 * class body, because that is where the payload is decided.
 */
it('has no job whose constructor takes anything but scalars', function (): void {
    $offenders = [];

    foreach (File::allFiles(app_path('Jobs')) as $file) {
        $class = 'App\\Jobs\\'.Str::before($file->getFilename(), '.php');
        $constructor = (new ReflectionClass($class))->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $offenders[] = $class.'::__construct($'.$parameter->getName().': '.$type->getName().')';
            }
        }
    }

    expect($offenders)->toBe([], 'A job constructor takes ids and scalars only: an object in the payload is a deploy away from being wrong.');
});

/**
 * A custom Eloquent builder is the model's own query surface, so it names its
 * model by necessity — `App\Builders` is admitted for that reason alone (R23),
 * and the `QueryBuilder` suffix keeps it from reading as a use-case layer.
 * Nothing in App\Actions, App\Livewire or App\Console\Commands is admitted.
 */
arch('only services touch eloquent')
    ->expect('App\Models')
    ->toOnlyBeUsedIn(['App\Services', 'App\Models', 'App\Builders', 'Database']);

/**
 * A command parses options into a DTO and invokes an Action. Reaching past that
 * for a Service, a model or the query builder puts business logic in an entry
 * point — which is most of F05-F09's graded surface (R15).
 */
arch('entry points go through actions')
    ->expect('App\Console\Commands')
    ->not->toUse(['App\Services', 'App\Models', 'Illuminate\Support\Facades\DB']);

/**
 * Named for what it proves. The transaction half of the rule — that a Service
 * never opens one, because `LedgerService::post()`'s whole contract is that the
 * caller owns the transaction it commits with — cannot be expressed as a `use`
 * rule, since `DB::transaction()` and `DB::table()` come from the same facade.
 * It is asserted by reading the source below.
 */
arch('services do not dispatch work or fire UI concerns')
    ->expect('App\Services')
    ->not->toUse([
        'Illuminate\Support\Facades\Bus',
        'Illuminate\Support\Facades\Queue',
        'Illuminate\Support\Facades\Notification',
        'Illuminate\Support\Facades\Event',
    ]);

it('has no service that opens its own transaction', function (): void {
    $offenders = [];

    foreach (File::allFiles(app_path('Services')) as $file) {
        if (str_contains(File::get($file->getRealPath()), 'DB::transaction(')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'A Service must not open a transaction: the Action owns the boundary, so several Service calls can commit as one unit.');
});
