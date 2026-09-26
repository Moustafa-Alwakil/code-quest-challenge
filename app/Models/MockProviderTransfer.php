<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A row in the mock provider's own database (F07, R8).
 *
 * Not part of this application's domain — it is the *other side* of the
 * integration, persisted so a retry from a different worker process meets the
 * same dedup a real provider would give it. No factory: rows here are written
 * only by the provider itself, because a hand-made one would model a provider
 * state no provider could be in.
 *
 * @property int                  $id
 * @property string               $idempotency_key
 * @property string               $account_ref
 * @property int                  $amount_minor
 * @property string               $currency
 * @property TransferStatus       $status
 * @property string|null          $provider_reference
 * @property string|null          $failure_code
 * @property int                  $confirm_after_checks
 * @property int                  $status_checks
 * @property int                  $transfer_executions
 * @property int                  $transfer_calls
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable      $created_at
 * @property CarbonImmutable      $updated_at
 */
final class MockProviderTransfer extends Model
{
    protected $fillable = [
        'idempotency_key',
        'account_ref',
        'amount_minor',
        'currency',
        'status',
        'provider_reference',
        'failure_code',
        'confirm_after_checks',
        'status_checks',
        'transfer_executions',
        'transfer_calls',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => TransferStatus::class,
            'confirm_after_checks' => 'integer',
            'status_checks' => 'integer',
            'transfer_executions' => 'integer',
            'transfer_calls' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
