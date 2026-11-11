<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationSource;
use App\Enums\RefundOperationStatus;
use App\Models\Payment;
use App\Models\RefundOperation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundOperationService
{
    private const ACTIVE_STATUSES = [
        RefundOperationStatus::Requested,
        RefundOperationStatus::Processing,
        RefundOperationStatus::Unknown,
        RefundOperationStatus::Pending,
        RefundOperationStatus::RequiresAction,
    ];

    public function findExistingRequest(
        Payment $payment,
        RefundOperationSource $source,
        ?User $requester,
        string $requestKey,
    ): ?RefundOperation {
        $requestKey = trim($requestKey);

        if ($requestKey === '') {
            return null;
        }

        $operation = RefundOperation::query()
            ->where('idempotency_key', 'refund-op-'.hash('sha256', $requestKey))
            ->lockForUpdate()
            ->first();

        if ($operation) {
            $this->assertSameRequest($operation, $payment, $source, $requester);
        }

        return $operation;
    }

    /**
     * Reserve one full-refund operation without contacting Stripe.
     */
    public function reserve(
        Payment $payment,
        RefundOperationSource $source,
        ?User $requester,
        string $requestKey,
    ): RefundOperation {
        $requestKey = trim($requestKey);

        if ($requestKey === '') {
            throw new \InvalidArgumentException('A refund request key is required.');
        }

        $paymentId = (int) $payment->getKey();
        // A request key is globally bound to its payment/source/requester tuple.
        $idempotencyKey = 'refund-op-'.hash('sha256', $requestKey);
        $activeKey = $this->activeKeyFor($paymentId);

        try {
            return DB::transaction(function () use ($paymentId, $source, $requester, $idempotencyKey, $activeKey) {
                // Payment is the serialization point for every refund attempt on this payment.
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

                $existingRequest = RefundOperation::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingRequest) {
                    $this->assertSameRequest($existingRequest, $lockedPayment, $source, $requester);

                    return $existingRequest;
                }

                $this->assertEligiblePayment($lockedPayment);

                $previousSuccess = RefundOperation::query()
                    ->where('payment_id', $lockedPayment->id)
                    ->where('status', RefundOperationStatus::Succeeded->value)
                    ->exists();

                if ($previousSuccess) {
                    throw new \DomainException('A full refund has already succeeded for this payment.');
                }

                $activeOperation = RefundOperation::query()
                    ->where('payment_id', $lockedPayment->id)
                    ->where(function ($query) use ($activeKey) {
                        $query->where('active_key', $activeKey)
                            ->orWhereIn('status', array_map(
                                static fn (RefundOperationStatus $status) => $status->value,
                                self::ACTIVE_STATUSES,
                            ));
                    })
                    ->lockForUpdate()
                    ->first();

                if ($activeOperation) {
                    if (! $this->isActive($activeOperation->status)) {
                        throw new \LogicException('A terminal refund operation still holds the active payment key.');
                    }

                    throw new \DomainException('A refund operation is already active for this payment.');
                }

                return RefundOperation::query()->create([
                    'payment_id' => $lockedPayment->id,
                    'source' => $source,
                    'requested_by' => $requester?->getKey(),
                    'status' => RefundOperationStatus::Requested,
                    'amount_minor' => $this->amountInMinorUnits($lockedPayment),
                    'currency' => strtoupper($lockedPayment->currency),
                    'idempotency_key' => $idempotencyKey,
                    'active_key' => $activeKey,
                    'attempt_count' => 0,
                    'requested_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isRefundUniqueConflict($exception)) {
                throw $exception;
            }

            return $this->resolveUniqueConflict($paymentId, $source, $requester, $idempotencyKey, $activeKey, $exception);
        }
    }

    /**
     * Record a Stripe outcome. This method only updates the operation; it never
     * mutates Payment, Booking, or PaymentTransaction.
     */
    public function transition(
        RefundOperation $operation,
        RefundOperationStatus $newStatus,
        ?RefundOperationEvidence $evidence = null,
        ?string $stripeRefundId = null,
        ?string $lastError = null,
        bool $providerChecked = false,
    ): RefundOperation
    {
        return DB::transaction(function () use ($operation, $newStatus, $evidence, $stripeRefundId, $lastError, $providerChecked) {
            // Keep the lock order identical to reserve(): payment, then operation.
            $payment = Payment::query()->lockForUpdate()->findOrFail($operation->payment_id);
            $lockedOperation = RefundOperation::query()
                ->where('payment_id', $payment->id)
                ->lockForUpdate()
                ->findOrFail($operation->id);

            $currentStatus = $lockedOperation->status;

            if ($currentStatus === $newStatus) {
                if ($currentStatus->isTerminal()) {
                    return $lockedOperation;
                }

                if ($stripeRefundId !== null
                    && $lockedOperation->stripe_refund_id !== null
                    && $lockedOperation->stripe_refund_id !== $stripeRefundId) {
                    throw new \DomainException('A refund operation cannot be associated with a different Stripe refund ID.');
                }

                $attributes = [];
                if ($providerChecked) {
                    $attributes['last_checked_at'] = now();
                    $attributes['last_error'] = $lastError;
                }
                if ($stripeRefundId !== null && $lockedOperation->stripe_refund_id === null) {
                    $attributes['stripe_refund_id'] = $stripeRefundId;
                }

                if ($attributes !== []) {
                    $lockedOperation->update($attributes);
                    return $lockedOperation->fresh();
                }

                return $lockedOperation;
            }

            if (! $this->isActive($currentStatus)) {
                throw new \DomainException('A terminal refund operation cannot transition to a different status.');
            }

            if (! $currentStatus->canTransitionTo($newStatus)) {
                throw new \DomainException("Invalid refund operation transition: {$currentStatus->value} -> {$newStatus->value}.");
            }

            if ($newStatus->isTerminal()) {
                if ($evidence === null) {
                    throw new \DomainException('A terminal refund status requires confirmed outcome evidence.');
                }

                if ($evidence === RefundOperationEvidence::NotSubmitted
                    && ($currentStatus !== RefundOperationStatus::Requested
                        || ! in_array($newStatus, [RefundOperationStatus::Failed, RefundOperationStatus::Canceled], true))) {
                    throw new \DomainException('Not-submitted evidence is valid only for a Requested operation ending Failed or Canceled.');
                }
            } elseif ($evidence === RefundOperationEvidence::NotSubmitted) {
                throw new \DomainException('Not-submitted evidence cannot confirm a non-terminal transition.');
            }

            if ($this->isActive($newStatus)) {
                $otherActiveOperation = RefundOperation::query()
                    ->where('payment_id', $payment->id)
                    ->where('id', '!=', $lockedOperation->id)
                    ->where(function ($query) {
                        $query->whereNotNull('active_key')
                            ->orWhereIn('status', array_map(
                                static fn (RefundOperationStatus $status) => $status->value,
                                self::ACTIVE_STATUSES,
                            ));
                    })
                    ->lockForUpdate()
                    ->first();

                if ($otherActiveOperation) {
                    throw new \DomainException('Another refund operation is already active for this payment.');
                }

                $attributes = [
                    'status' => $newStatus,
                    'active_key' => $this->activeKeyFor($payment->id),
                    'completed_at' => null,
                ];

                if ($providerChecked) {
                    $attributes['last_checked_at'] = now();
                    $attributes['last_error'] = $lastError;
                }

                if ($stripeRefundId !== null) {
                    $attributes['stripe_refund_id'] = $stripeRefundId;
                }

                $lockedOperation->update($attributes);
            } else {
                $attributes = [
                    'status' => $newStatus,
                    'active_key' => null,
                    'last_checked_at' => now(),
                    'completed_at' => now(),
                ];

                if ($providerChecked) {
                    $attributes['last_error'] = $lastError;
                }

                if ($stripeRefundId !== null) {
                    $attributes['stripe_refund_id'] = $stripeRefundId;
                }

                $lockedOperation->update($attributes);
            }

            return $lockedOperation->fresh();
        }, 3);
    }

    private function assertEligiblePayment(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new \DomainException('Only a paid payment can be reserved for a full refund.');
        }

        if (strtolower((string) $payment->provider) !== 'stripe' || blank($payment->payment_intent_id)) {
            throw new \DomainException('The payment is missing the Stripe payment intent required for refund.');
        }

        if (strtoupper((string) $payment->currency) !== 'USD') {
            throw new \DomainException('Refund reservation currently supports the USD payment currency only.');
        }
    }

    private function assertSameRequest(
        RefundOperation $operation,
        Payment $payment,
        RefundOperationSource $source,
        ?User $requester,
    ): void {
        if ((int) $operation->payment_id !== (int) $payment->id
            || $operation->source !== $source
            || (int) ($operation->requested_by ?? 0) !== (int) ($requester?->getKey() ?? 0)) {
            throw new \DomainException('The refund request key was already used for a different request.');
        }
    }

    private function amountInMinorUnits(Payment $payment): int
    {
        $amount = (string) $payment->getRawOriginal('amount');

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new \DomainException('The payment amount cannot be represented in USD minor units.');
        }

        $minorUnits = ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');

        if ($minorUnits <= 0) {
            throw new \DomainException('The payment amount must be greater than zero.');
        }

        return $minorUnits;
    }

    private function activeKeyFor(int $paymentId): string
    {
        return 'payment-refund:'.$paymentId;
    }

    private function isActive(RefundOperationStatus $status): bool
    {
        return ! $status->isTerminal();
    }

    private function isRefundUniqueConflict(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        $vendorCode = (int) ($errorInfo[1] ?? $exception->getPrevious()?->errorInfo[1] ?? 0);

        if ($vendorCode !== 1062) {
            return false;
        }

        $message = $exception->getMessage();

        return Str::contains($message, [
            'refund_operations_idempotency_key_unique',
            'refund_operations_active_key_unique',
        ]);
    }

    private function resolveUniqueConflict(
        int $paymentId,
        RefundOperationSource $source,
        ?User $requester,
        string $idempotencyKey,
        string $activeKey,
        QueryException $exception,
    ): RefundOperation {
        $existingRequest = RefundOperation::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existingRequest) {
            $payment = Payment::query()->findOrFail($paymentId);
            $this->assertSameRequest($existingRequest, $payment, $source, $requester);

            return $existingRequest;
        }

        $activeOperation = RefundOperation::query()
            ->where('payment_id', $paymentId)
            ->where('active_key', $activeKey)
            ->first();

        if ($activeOperation && $this->isActive($activeOperation->status)) {
            throw new \DomainException('A refund operation is already active for this payment.', previous: $exception);
        }

        throw $exception;
    }
}
