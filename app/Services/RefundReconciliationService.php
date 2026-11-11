<?php

namespace App\Services;

use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationStatus;
use App\Models\RefundOperation;
use App\Services\stripe\StripeRefundService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RefundReconciliationService
{
    private const LOCK_SECONDS = 900;

    public function __construct(
        private StripeRefundService $stripeRefundService,
        private RefundOperationService $refundOperationService,
        private RefundOutcomeService $refundOutcomeService,
    ) {}

    public function reconcile(RefundOperation $operation): RefundOperation
    {
        if (! $this->isReconcileCandidate($operation->status)) {
            return $operation->fresh();
        }

        if (config('cache.stores.redis.driver') !== 'redis') {
            throw new RuntimeException('Refund reconciliation requires the configured Redis lock store.');
        }

        $lock = Cache::store('redis')->lock('refund-reconciliation:'.$operation->getKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $operation->fresh();
        }

        try {
            $operation = RefundOperation::query()->with('payment')->findOrFail($operation->getKey());
            if (! $this->isReconcileCandidate($operation->status)) {
                return $operation;
            }

            $paymentIntentId = $operation->payment?->payment_intent_id;
            if (! is_string($paymentIntentId) || $paymentIntentId === '') {
                return $this->recordUnresolved($operation, 'PaymentIntent is missing for this refund operation.');
            }

            try {
                if (filled($operation->stripe_refund_id)) {
                    $candidate = $this->stripeRefundService->retrieveRefund($operation->stripe_refund_id);
                    $matches = $this->matchesOperation($candidate, $operation, $paymentIntentId);
                    if (! $matches) {
                        return $this->recordUnresolved($operation, 'Retrieved Stripe refund did not match the local operation.');
                    }
                } else {
                    $taggedRefunds = array_values(array_filter(
                        $this->stripeRefundService->listRefundsForPaymentIntent($paymentIntentId),
                        fn ($refund) => (string) data_get($refund, 'metadata.refund_operation_id') === (string) $operation->getKey(),
                    ));

                    if (count($taggedRefunds) !== 1) {
                        $message = count($taggedRefunds) > 1
                            ? 'Multiple Stripe refunds identify the same refund operation.'
                            : 'No Stripe refund identifies this refund operation.';

                        return $this->recordUnresolved($operation, $message);
                    }

                    $candidate = $taggedRefunds[0];
                    if (! $this->matchesOperation($candidate, $operation, $paymentIntentId)) {
                        return $this->recordUnresolved($operation, 'Stripe refund metadata matched, but payment details did not.');
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Refund reconciliation could not confirm the Stripe result.', [
                    'refund_operation_id' => $operation->getKey(),
                    'exception' => class_basename($exception),
                ]);

                return $this->recordUnresolved($operation, class_basename($exception));
            }

            $status = RefundOperationStatus::fromStripe(data_get($candidate, 'status'));
            $refundId = data_get($candidate, 'id');
            if (! is_string($refundId) || $refundId === '') {
                return $this->recordUnresolved($operation, 'Matched Stripe refund did not contain a valid ID.');
            }

            return $this->refundOutcomeService->record(
                $operation,
                $status,
                $refundId,
                $status === RefundOperationStatus::Unknown ? 'Stripe returned an unrecognized refund status.' : null,
                RefundOperationEvidence::Reconciliation,
            );
        } finally {
            $lock->release();
        }
    }

    private function matchesOperation(mixed $refund, RefundOperation $operation, string $paymentIntentId): bool
    {
        $refundId = data_get($refund, 'id');
        $refundAmount = data_get($refund, 'amount');
        $refundPaymentIntent = data_get($refund, 'payment_intent');
        if (is_object($refundPaymentIntent)) {
            $refundPaymentIntent = data_get($refundPaymentIntent, 'id');
        }

        return is_string($refundId)
            && $refundId !== ''
            && ($operation->stripe_refund_id === null || $operation->stripe_refund_id === $refundId)
            && (string) data_get($refund, 'metadata.refund_operation_id') === (string) $operation->getKey()
            && (string) $refundPaymentIntent === $paymentIntentId
            && is_int($refundAmount)
            && $refundAmount === (int) $operation->amount_minor
            && strtoupper((string) data_get($refund, 'currency')) === strtoupper((string) $operation->currency);
    }

    private function recordUnresolved(RefundOperation $operation, string $reason): RefundOperation
    {
        return $this->refundOperationService->transition(
            $operation,
            $operation->status,
            RefundOperationEvidence::Reconciliation,
            lastError: $reason,
            providerChecked: true,
        );
    }

    private function isReconcileCandidate(RefundOperationStatus $status): bool
    {
        return in_array($status, [
            RefundOperationStatus::Processing,
            RefundOperationStatus::Unknown,
            RefundOperationStatus::Pending,
            RefundOperationStatus::RequiresAction,
        ], true);
    }
}
