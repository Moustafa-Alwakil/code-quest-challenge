<?php

declare(strict_types=1);

use App\Enums\InstructorStatus;
use App\Models\Course;
use App\Models\Enrolment;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\QueryException;

it('creates an active instructor', function (): void {
    $instructor = Instructor::factory()->create();

    expect($instructor->status)->toBe(InstructorStatus::Active)
        ->and($instructor->payout_account_ref)->toStartWith('acct_');
});

it('creates a suspended instructor', function (): void {
    expect(Instructor::factory()->suspended()->create()->status)
        ->toBe(InstructorStatus::Suspended);
});

it('refuses two instructors with the same email', function (): void {
    Instructor::factory()->create(['email' => 'taken@example.com']);

    expect(fn () => Instructor::factory()->create(['email' => 'taken@example.com']))
        ->toThrow(QueryException::class);
});

it('scopes instructors to the active ones', function (): void {
    Instructor::factory()->count(2)->create();
    Instructor::factory()->suspended()->create();

    expect(Instructor::active()->count())->toBe(2);
});

it('creates a course belonging to an instructor', function (): void {
    $course = Course::factory()->create();

    expect($course->instructor)->toBeInstanceOf(Instructor::class)
        ->and($course->instructor->courses->pluck('id'))->toContain($course->id);
});

it('refuses two courses with the same slug', function (): void {
    Course::factory()->create(['slug' => 'laravel-basics']);

    expect(fn () => Course::factory()->create(['slug' => 'laravel-basics']))
        ->toThrow(QueryException::class);
});

it('scopes courses to the published ones', function (): void {
    Course::factory()->count(2)->create();
    Course::factory()->unpublished()->create();
    Course::factory()->create(['published_at' => now()->addDay()]);

    expect(Course::published()->count())->toBe(2);
});

it('creates each seeded plan shape', function (string $state, int $months, int $priceMinor): void {
    $plan = Plan::factory()->{$state}()->create();

    expect($plan->key)->toBe($state)
        ->and($plan->interval_months)->toBe($months)
        ->and($plan->price_minor)->toBe($priceMinor)
        ->and($plan->currency)->toBe(config('revenue.currency'))
        ->and($plan->is_active)->toBeTrue();
})->with([
    ['monthly', 1, 30_000],
    ['quarterly', 3, 80_000],
    ['annual', 12, 300_000],
]);

it('refuses two plans with the same key', function (): void {
    Plan::factory()->monthly()->create();

    expect(fn () => Plan::factory()->monthly()->create())
        ->toThrow(QueryException::class);
});

it('scopes plans to the active ones', function (): void {
    Plan::factory()->monthly()->create();
    Plan::factory()->annual()->inactive()->create();

    expect(Plan::active()->count())->toBe(1);
});

it('keeps the plan price as an integer, never a float', function (): void {
    $plan = Plan::factory()->annual()->create();

    expect($plan->refresh()->price_minor)->toBeInt()->toBe(300_000);
});

it('enrols a student in a course', function (): void {
    $enrolment = Enrolment::factory()->create();

    expect($enrolment->user)->toBeInstanceOf(User::class)
        ->and($enrolment->course)->toBeInstanceOf(Course::class)
        ->and($enrolment->user->enrolments->pluck('id'))->toContain($enrolment->id);
});

it('refuses to enrol the same student in the same course twice', function (): void {
    $enrolment = Enrolment::factory()->create();

    expect(fn () => Enrolment::factory()->create([
        'user_id' => $enrolment->user_id,
        'course_id' => $enrolment->course_id,
    ]))->toThrow(QueryException::class);
});

it('lets the same student enrol in two different courses', function (): void {
    $student = User::factory()->student()->create();

    Enrolment::factory()->count(2)->sequence(
        ['course_id' => Course::factory()],
        ['course_id' => Course::factory()],
    )->create(['user_id' => $student->id]);

    expect($student->enrolments()->count())->toBe(2);
});

it('separates admin users from students', function (): void {
    expect(User::factory()->admin()->create()->is_admin)->toBeTrue()
        ->and(User::factory()->student()->create()->is_admin)->toBeFalse()
        ->and(User::factory()->create()->is_admin)->toBeFalse();
});
