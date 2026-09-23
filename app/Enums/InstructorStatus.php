<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an instructor is currently teaching.
 *
 * Suspension does not affect money already earned: engagement for a suspended
 * instructor still counts, and their balance still pays out (F02 rules).
 */
enum InstructorStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
