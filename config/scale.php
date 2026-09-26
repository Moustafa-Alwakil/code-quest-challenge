<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Scale seeder sizing
    |--------------------------------------------------------------------------
    |
    | Only `ScaleSeeder` reads these, and they are here rather than as constants
    | so a laptop, CI and a demo machine can differ without editing code. They
    | are not policy: nothing about what a piastre is worth changes with them,
    | which is why they are not in config/revenue.php next to the dials that do.
    |
    */

    /**
     * Terms to write. The brief's "tens of millions of records" is reached
     * through the engagement rows these imply, not through the terms
     * themselves: 50 000 annual terms is 600 000 periods and around a million
     * engagement rows.
     */
    'subscriptions' => (int) env('SCALE_SUBSCRIPTIONS', 50_000),

    'instructors' => (int) env('SCALE_INSTRUCTORS', 500),

    'students' => (int) env('SCALE_STUDENTS', 20_000),

    /**
     * Rows per statement. Comfortably under MySQL's placeholder limit at these
     * row widths, and small enough that each transaction stays short.
     */
    'chunk' => (int) env('SCALE_CHUNK', 1_000),

];
