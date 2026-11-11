<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationSource;
use App\Enums\RefundOperationStatus;
use App\Events\BookingRejected;
use App\Events\RefundCompleted;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\RefundOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RefundOutcomeService
{
    public function __construct(private RefundOperationService $refundOperationService) {}

    /**
     * Record a direct Stripe Refund response or a signature-verified Refund
     * webhook only after it is matched to the local payment operation.
     */
    public function recordStripeRefundObject(
        RefundOperation $operation,
        mixed $refund,
        RefundOperationEvidence $evidence,
        ?User $customer = null,
        ?string $rejectionReason = null,
    ): RefundOperation {
        $operation = RefundOperation::query()->with('payment')->findOrFail($operation->getKey());

        // A late or reordered provider event cannot reopen a terminal operation.
        if ($operation->status->isTerminal()) {
            return $operation;
        }

        $refundId = data_get($refund, 'id');
        $paymentIntent = data_get($refund, 'payment_intent');
        if (is_object($paymentIntent)) {
            $paymentIntent = data_get($paymentIntent, 'id');
        }
        $amount = data_get($refund, 'amount');
        $currency = data_get($refund, 'currency');
        $metadataOperationId = data_get($refund, 'metadata.refund_operation_id');
        $hasKnownRefundId = is_string($refundId)
            && $refundId !== ''
            && $operation->stripe_refund_id === $refundId;
        $metadataMatches = is_scalar($metadataOperationId)
            && (string) $metadataOperationId === (string) $operation->getKey();
        $metadataAbsentForKnownRefund = ($metadataOperationId === null || $metadataOperationId === '')
            && $hasKnownRefundId;
        $identityMatches = is_string($refundId)
            && $refundId !== ''
            && ($operation->stripe_refund_id === null || $operation->stripe_refund_id === $refundId)
            && ($metadataMatches || $metadataAbsentForKnownRefund);
        $paymentMatches = is_string($paymentIntent)
            && $paymentIntent !== ''
            && $paymentIntent === $operation->payment?->payment_intent_id;
        $amountMatches = is_int($amount) && $amount === (int) $operation->amount_minor;
        $currencyMatches = is_string($currency)
            && strtoupper($currency) === strtoupper((string) $operation->currency);

        if (! $identityMatches || ! $paymentMatches || ! $amountMatches || ! $currencyMatches) {
            return $this->record(
                $operation,
                RefundOperationStatus::Unknown,
                null,
                'Stripe refund response did not match the local operation; reconciliation is required.',
                $evidence,
                $customer,
                $rejectionReason,
            );
        }

        $status = RefundOperationStatus::fromStripe(data_get($refund, 'status'));

        return $this->record(
            $operation,
            $status,
            $refundId,
            $status === RefundOperationStatus::Unknown
                ? 'Stripe returned an unsupported refund status; reconciliation is required.'
                : null,
            $evidence,
            $customer,
            $rejectionReason,
        );
    }

    public function record(
        RefundOperation $operation,
        RefundOperationStatus $status,
        ?string $stripeRefundId,
        ?string $lastError,
        RefundOperationEvidence $evidence,
        ?User $customer = null,
        ?string $rejectionReason = null,
    ): RefundOperation {
        return DB::transaction(function () use ($operation, $status, $stripeRefundId, $lastError, $evidence, $customer, $rejectionReason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($operation->payment_id);
            $bookingQuery = Booking::query()->whereKey($payment->booking_id);

            if ($customer !== null) {
                $bookingQuery->where('user_id', $customer->getKey());
            }

            $booking = $bookingQuery->lockForUpdate()->firstOrFail();
            $previousStatus = RefundOperation::query()
                ->whereKey($operation->getKey())
                ->lockForUpdate()
                ->value('status');
            $operation = $this->refundOperationService->transition(
                $operation,
                $status,
                $evidence,
                $stripeRefundId,
                $lastError,
                providerChecked: true,
            );

            if ($status === RefundOperationStatus::Succeeded) {
                if ($payment->status !== PaymentStatus::Paid && $payment->status !== PaymentStatus::Refunded) {
                    throw new \DomainException('Payment state changed before the confirmed refund could be recorded.');
                }

                $payment->update(['status' => PaymentStatus::Refunded]);
                PaymentTransaction::query()->firstOrCreate(
                    ['refund_operation_id' => $operation->id],
                    [
                        'payment_id' => $payment->id,
                        'type' => 'refund',
                        'amount' => number_format($operation->amount_minor / 100, 2, '.', ''),
                        'currency' => $operation->currency,
                        'meta' => ['stripe_refund_id' => $stripeRefundId, 'status' => $status->value],
                    ],
                );

                $targetBookingStatus = $operation->source === RefundOperationSource::StaffReject
                    ? BookingStatus::Rejected
                    : BookingStatus::Cancelled;
                $timestampAttribute = $operation->source === RefundOperationSource::StaffReject
                    ? 'rejected_at'
                    : 'cancelled_at';

                if ($booking->status !== $targetBookingStatus) {
                    $allowedSourceStatus = $operation->source === RefundOperationSource::StaffReject
                        ? BookingStatus::PendingStaffApproval
                        : BookingStatus::Confirmed;

                    if ($booking->status !== $allowedSourceStatus) {
                        throw new \DomainException('Booking state changed before the confirmed refund could be recorded.');
                    }

                    BookingStatusService::changeStatus($booking, $targetBookingStatus, [
                        $timestampAttribute => now(),
                    ]);
                }

                if ($previousStatus !== RefundOperationStatus::Succeeded->value
                    && in_array($evidence, [RefundOperationEvidence::Webhook, RefundOperationEvidence::Reconciliation], true)) {
                    DB::afterCommit(function () use ($operation, $payment, $booking, $rejectionReason) {
                        $payment = $payment->fresh();
                        $booking = $booking->fresh();
                        event(new RefundCompleted($payment, $booking));

                        if ($operation->source === RefundOperationSource::StaffReject) {
                            event(new BookingRejected(
                                $booking,
                                blank($rejectionReason) ? null : trim($rejectionReason),
                            ));
                        }
                    });
                }
            }

            return $operation->fresh();
        }, 3);
    }
}
