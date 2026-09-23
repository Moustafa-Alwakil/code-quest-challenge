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
| Rules for App\Actions, App\DTOs, App\Services, App\Livewire, App\Jobs and
| App\Console\Commands are added as F03-F11 create those namespaces; Pest's
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
