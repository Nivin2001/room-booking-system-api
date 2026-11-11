<?php

namespace App\Services\stripe;

use App\Enums\BookingStatus;
use App\Enums\CheckoutAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationStatus;
use App\Events\PaymentSucceeded;
use App\Models\Booking;
use App\Models\CheckoutAttempt;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\RefundOperation;
use App\Models\WebhookLog;
use App\Services\BookingStatusService;
use App\Services\RefundOutcomeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookService
{
    private readonly StripeCheckoutService $stripeCheckoutService;

    public function __construct(?StripeCheckoutService $stripeCheckoutService = null)
    {
        $this->stripeCheckoutService = $stripeCheckoutService ?? app(StripeCheckoutService::class);
    }

    public function handle(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('services.stripe.webhook_secret')
            );
        } catch (\Exception) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        return $this->processEvent($event, json_decode($request->getContent(), true) ?: []);
    }

    public function processEvent(object $event, array $payload = [])
    {
        try {
            WebhookLog::create([
                'provider' => 'stripe',
                'event_id' => $event->id,
                'event_type' => $event->type,
                'payload' => $this->safeWebhookPayload($event, $payload),
                'attempts' => 0,
            ]);
        } catch (QueryException) {
            // The unique event ID is the concurrency-safe idempotency boundary.
        }

        try {
            return DB::transaction(function () use ($event) {
                $log = WebhookLog::query()->where('event_id', $event->id)->lockForUpdate()->firstOrFail();

                if ($log->processed_at) {
                    return response()->json(['message' => 'Duplicate webhook event ignored', 'event_id' => $event->id]);
                }

                $response = match ($event->type) {
                    'checkout.session.completed' => $this->handleCheckoutCompleted($event),
                    'refund.created', 'refund.updated', 'refund.failed' => $this->handleRefundEvent($event),
                    default => response()->json(['message' => 'Event ignored']),
                };

                if ($response->getStatusCode() < 400) {
                    $log->update(['processed_at' => now(), 'attempts' => 0, 'last_error' => null]);
                }

                return $response;
            }, 3);
        } catch (\Throwable $exception) {
            Log::warning('Webhook processing failed', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'exception' => class_basename($exception),
            ]);
            WebhookLog::where('event_id', $event->id)->increment('attempts');
            WebhookLog::where('event_id', $event->id)->update(['last_error' => class_basename($exception)]);

            return response()->json(['error' => 'processing_failed'], 500);
        }
    }

    private function safeWebhookPayload(object $event, array $payload): array
    {
        $refund = data_get($payload, 'data.object', data_get($event, 'data.object', []));
        if ($refund === [] || $refund === null) {
            $refund = data_get($event, 'data.object', []);
        }
        $eventType = (string) ($event->type ?? data_get($payload, 'type', 'unknown'));
        $safeObject = [
            'id' => $this->safeWebhookIdentifier(data_get($refund, 'id')),
            'object' => $this->safeWebhookString(data_get($refund, 'object')),
        ];

        if ($eventType === 'checkout.session.completed') {
            $safeObject += [
                'status' => $this->safeWebhookString(data_get($refund, 'status')),
                'payment_status' => $this->safeWebhookString(data_get($refund, 'payment_status')),
                'mode' => $this->safeWebhookString(data_get($refund, 'mode')),
                'livemode' => is_bool(data_get($refund, 'livemode')) ? data_get($refund, 'livemode') : null,
                'amount_total' => is_int(data_get($refund, 'amount_total')) ? data_get($refund, 'amount_total') : null,
                'currency' => $this->safeWebhookString(data_get($refund, 'currency')),
                'payment_intent' => $this->safeWebhookIdentifier(data_get($refund, 'payment_intent')),
                'expires_at' => is_int(data_get($refund, 'expires_at')) ? data_get($refund, 'expires_at') : null,
                'metadata' => $this->safeWebhookMetadata($refund, [
                    'booking_id',
                    'payment_id',
                    'checkout_attempt_id',
                ]),
            ];
        } elseif (in_array($eventType, ['refund.created', 'refund.updated', 'refund.failed'], true)) {
            $safeObject += [
                'status' => $this->safeWebhookString(data_get($refund, 'status')),
                'payment_intent' => $this->safeWebhookIdentifier(data_get($refund, 'payment_intent')),
                'amount' => is_int(data_get($refund, 'amount')) ? data_get($refund, 'amount') : null,
                'currency' => $this->safeWebhookString(data_get($refund, 'currency')),
                'reason' => $this->safeWebhookString(data_get($refund, 'reason')),
                'failure_reason' => $this->safeWebhookString(data_get($refund, 'failure_reason')),
                'metadata' => $this->safeWebhookMetadata($refund, ['refund_operation_id']),
            ];
        }

        $safeObject = array_filter($safeObject, static fn (mixed $value) => $value !== null);
        $safePayload = [
            'id' => $this->safeWebhookIdentifier($event->id ?? data_get($payload, 'id')),
            'type' => $this->safeWebhookString($eventType),
            'created' => is_int($event->created ?? data_get($payload, 'created'))
                ? ($event->created ?? data_get($payload, 'created'))
                : null,
            'livemode' => is_bool($event->livemode ?? data_get($payload, 'livemode'))
                ? ($event->livemode ?? data_get($payload, 'livemode'))
                : null,
            'api_version' => $this->safeWebhookString($event->api_version ?? data_get($payload, 'api_version')),
            'data' => ['object' => $safeObject],
        ];

        return array_filter($safePayload, static fn (mixed $value) => $value !== null);
    }

    private function safeWebhookMetadata(mixed $object, array $allowedKeys): array
    {
        $metadata = [];

        foreach ($allowedKeys as $key) {
            $value = data_get($object, 'metadata.'.$key);
            if (is_string($value) && $value !== '' && strlen($value) <= 255) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function safeWebhookIdentifier(mixed $value): ?string
    {
        if (is_object($value)) {
            $value = data_get($value, 'id');
        }

        return is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
    }

    private function safeWebhookString(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 255 ? $value : null;
    }

    /**
     * Stripe signs these events, but the event is still only a candidate result:
     * it must match the local operation, PaymentIntent and amount before it can
     * change any financial or booking state.
     */
    private function handleRefundEvent(object $event)
    {
        $refund = data_get($event, 'data.object');
        $refundId = data_get($refund, 'id');
        $metadataOperationId = data_get($refund, 'metadata.refund_operation_id');

        if (! is_string($refundId) || $refundId === '') {
            return response()->json(['message' => 'Refund event has no refund ID; reconciliation will determine the outcome.']);
        }

        $operationQuery = RefundOperation::query()->with('payment');
        if (is_scalar($metadataOperationId) && ctype_digit((string) $metadataOperationId)) {
            $operation = $operationQuery->find((int) $metadataOperationId);
        } elseif ($metadataOperationId === null || $metadataOperationId === '') {
            $operation = $operationQuery->where('stripe_refund_id', $refundId)->first();
        } else {
            $operation = null;
        }

        if (! $operation) {
            Log::notice('Stripe refund webhook did not identify a local operation.', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return response()->json(['message' => 'Refund event does not match a known operation.']);
        }

        if ($operation->status->isTerminal()) {
            return response()->json(['message' => 'Refund operation is already final.']);
        }

        $paymentIntentId = data_get($refund, 'payment_intent');
        if (is_object($paymentIntentId)) {
            $paymentIntentId = data_get($paymentIntentId, 'id');
        }

        if (! is_string($paymentIntentId)
            || $paymentIntentId === ''
            || $paymentIntentId !== $operation->payment?->payment_intent_id) {
            Log::warning('Stripe refund webhook did not match the local PaymentIntent.', [
                'event_id' => $event->id,
                'refund_operation_id' => $operation->getKey(),
            ]);

            return response()->json(['message' => 'Refund event requires reconciliation.']);
        }

        $updated = app(RefundOutcomeService::class)->recordStripeRefundObject(
            $operation,
            $refund,
            RefundOperationEvidence::Webhook,
        );

        return response()->json([
            'message' => 'Refund outcome checked against the local operation.',
            'refund_operation_id' => $updated->getKey(),
            'status' => $updated->status->value,
        ]);
    }

    private function handleCheckoutCompleted(object $event)
    {
        $session = data_get($event, 'data.object');
        $paymentId = data_get($session, 'metadata.payment_id');
        $bookingId = data_get($session, 'metadata.booking_id');
        $paymentIntentId = data_get($session, 'payment_intent');
        if (is_object($paymentIntentId)) {
            $paymentIntentId = data_get($paymentIntentId, 'id');
        }

        // Validate the signed provider snapshot before taking any write action.
        if (! is_string(data_get($session, 'id')) || data_get($session, 'id') === ''
            || ! is_scalar($paymentId) || ! ctype_digit((string) $paymentId)
            || ! is_scalar($bookingId) || ! ctype_digit((string) $bookingId)
            || ! is_string($paymentIntentId) || $paymentIntentId === ''
            || data_get($session, 'mode') !== 'payment'
            || data_get($session, 'payment_status') !== 'paid'
            || ! is_int(data_get($session, 'amount_total'))
            || ! is_string(data_get($session, 'currency')) || data_get($session, 'currency') === '') {
            return response()->json(['error' => 'Invalid or incomplete checkout session'], 422);
        }

        // Match refund flows: lock payment before booking to avoid lock inversion.
        $payment = Payment::query()->lockForUpdate()->find((int) $paymentId);
        $booking = Booking::query()->lockForUpdate()->find((int) $bookingId);

        if (! $payment || ! $booking
            || (int) $payment->booking_id !== (int) $booking->id
            || (int) $payment->booking_id !== (int) $bookingId
            || $payment->provider !== 'stripe'
            || strtoupper((string) $payment->currency) !== strtoupper(data_get($session, 'currency'))
            || $this->amountInMinorUnits($payment->getRawOriginal('amount')) !== data_get($session, 'amount_total')
            || $this->amountInMinorUnits($booking->getRawOriginal('total_amount')) !== data_get($session, 'amount_total')
            || ($payment->payment_intent_id !== null && $payment->payment_intent_id !== $paymentIntentId)) {
            return response()->json(['error' => 'Checkout session does not match the local payment'], 422);
        }

        $attemptUuid = data_get($session, 'metadata.checkout_attempt_id');
        $attempt = null;
        if (is_string($attemptUuid) && $attemptUuid !== '') {
            $attempt = CheckoutAttempt::query()->where('uuid', $attemptUuid)->lockForUpdate()->first();
            if (! $attempt || (int) $attempt->payment_id !== (int) $payment->id
                || ($attempt->stripe_session_id !== null && $attempt->stripe_session_id !== data_get($session, 'id'))
                || $this->amountInMinorUnits($attempt->getRawOriginal('amount')) !== data_get($session, 'amount_total')
                || strtolower((string) $attempt->currency) !== strtolower((string) data_get($session, 'currency'))
                || ! hash_equals($attempt->request_fingerprint, hash('sha256', implode('|', [
                    $booking->id,
                    $payment->id,
                    (string) $payment->getRawOriginal('amount'),
                    strtolower((string) $payment->currency),
                ])))) {
                return response()->json(['error' => 'Checkout attempt does not match the local payment'], 422);
            }
        } else {
            // Backward-compatible path for Sessions created before CheckoutAttempt records existed.
            $attempt = CheckoutAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('stripe_session_id', data_get($session, 'id'))
                ->lockForUpdate()
                ->first();

            if (! $attempt && $payment->provider_reference !== data_get($session, 'id')) {
                return response()->json(['error' => 'Checkout session is not associated with a known attempt'], 422);
            }
        }

        if ($attempt && $attempt->status === CheckoutAttemptStatus::Succeeded
            && $attempt->stripe_session_id === data_get($session, 'id')) {
            return response()->json(['message' => 'Checkout attempt already processed']);
        }

        $existingTransaction = PaymentTransaction::query()->where('stripe_event_id', $event->id)->first();
        if ($existingTransaction && ((int) $existingTransaction->payment_id !== (int) $payment->id
            || ! in_array($existingTransaction->type, ['payment', 'payment_after_refund', 'duplicate_payment', 'payment_conflict'], true))) {
            return response()->json(['error' => 'Stripe event is already associated with a different transaction'], 409);
        }

        // A historical attempt may prove a real charge even after provider_reference
        // points at a newer Session. Preserve that evidence without overwriting a
        // different paid/refunded state.
        if (in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed], true)) {
            $this->recordCheckoutEvidence($event, $payment, $attempt, $paymentIntentId, 'payment_after_refund');
            $this->markAttemptSucceeded($attempt, $session, $paymentIntentId);
            $this->scheduleSupersededSessionExpiry($payment->id, (string) data_get($session, 'id'));
            Log::warning('Stripe Checkout payment evidence arrived for a non-payable local payment.', [
                'payment_id' => $payment->id,
                'event_id' => $event->id,
                'payment_status' => $payment->status->value,
            ]);

            return response()->json(['message' => 'Payment evidence recorded for reconciliation']);
        }

        if ($payment->status === PaymentStatus::Paid) {
            if ($payment->payment_intent_id !== $paymentIntentId) {
                $this->recordCheckoutEvidence($event, $payment, $attempt, $paymentIntentId, 'duplicate_payment');
                $this->markAttemptSucceeded($attempt, $session, $paymentIntentId);
                $this->scheduleSupersededSessionExpiry($payment->id, (string) data_get($session, 'id'));
                Log::critical('Multiple Stripe PaymentIntents reported paid for one local payment.', [
                    'payment_id' => $payment->id,
                    'event_id' => $event->id,
                ]);

                return response()->json(['message' => 'Additional payment evidence recorded for reconciliation']);
            }

            $this->recordCheckoutEvidence($event, $payment, $attempt, $paymentIntentId, 'payment');
            $this->markAttemptSucceeded($attempt, $session, $paymentIntentId);
            $this->scheduleSupersededSessionExpiry($payment->id, (string) data_get($session, 'id'));
            return response()->json(['message' => 'Payment already processed']);
        }

        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Failed], true)) {
            return response()->json(['error' => 'Payment is not eligible for checkout completion'], 422);
        }

        if ($booking->status !== BookingStatus::PendingPayment
            || ! $booking->status->canTransitionTo(BookingStatus::PendingStaffApproval)) {
            $this->recordCheckoutEvidence($event, $payment, $attempt, $paymentIntentId, 'payment_conflict');
            $this->markAttemptSucceeded($attempt, $session, $paymentIntentId);
            $this->scheduleSupersededSessionExpiry($payment->id, (string) data_get($session, 'id'));
            Log::critical('Paid Checkout Session conflicts with the current booking state.', [
                'payment_id' => $payment->id,
                'booking_id' => $booking->id,
                'event_id' => $event->id,
            ]);

            return response()->json(['message' => 'Payment evidence recorded for booking reconciliation']);
        }

        // All validation and state-transition checks precede the first write. Any
        // unexpected failure below bubbles out and rolls the whole event transaction back.
        BookingStatusService::changeStatus($booking, BookingStatus::PendingStaffApproval);
        $payment->update([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'payment_intent_id' => $paymentIntentId,
            'provider_reference' => data_get($session, 'id'),
        ]);
        PaymentTransaction::firstOrCreate(['stripe_event_id' => $event->id], [
            'payment_id' => $payment->id,
            'type' => 'payment',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'meta' => ['payment_intent_id' => $paymentIntentId, 'checkout_attempt_id' => $attempt?->uuid],
        ]);

        $this->markAttemptSucceeded($attempt, $session, $paymentIntentId);
        $this->scheduleSupersededSessionExpiry($payment->id, (string) data_get($session, 'id'));

        DB::afterCommit(fn () => event(new PaymentSucceeded($booking->fresh(), $payment->fresh())));

        return response()->json(['success' => true]);
    }

    private function recordCheckoutEvidence(object $event, Payment $payment, ?CheckoutAttempt $attempt, string $paymentIntentId, string $type): void
    {
        PaymentTransaction::firstOrCreate(['stripe_event_id' => $event->id], [
            'payment_id' => $payment->id,
            'type' => $type,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'meta' => [
                'payment_intent_id' => $paymentIntentId,
                'checkout_attempt_id' => $attempt?->uuid,
            ],
        ]);
    }

    private function markAttemptSucceeded(?CheckoutAttempt $attempt, object $session, string $paymentIntentId): void
    {
        if (! $attempt) {
            return;
        }

        $attempt->update([
            'stripe_session_id' => data_get($session, 'id'),
            'stripe_payment_intent_id' => $paymentIntentId,
            'status' => CheckoutAttemptStatus::Succeeded,
            'stripe_session_status' => data_get($session, 'status', 'complete'),
            'stripe_payment_status' => data_get($session, 'payment_status', 'paid'),
            'active_key' => null,
            'last_checked_at' => now(),
            'last_error_code' => null,
        ]);
    }

    private function scheduleSupersededSessionExpiry(int $paymentId, string $successfulSessionId): void
    {
        DB::afterCommit(function () use ($paymentId, $successfulSessionId): void {
            $sessions = CheckoutAttempt::query()
                ->where('payment_id', $paymentId)
                ->where('status', CheckoutAttemptStatus::Open->value)
                ->whereNotNull('stripe_session_id')
                ->where('stripe_session_id', '!=', $successfulSessionId)
                ->pluck('stripe_session_id');

            foreach ($sessions as $sessionId) {
                try {
                    $remote = $this->stripeCheckoutService->expireCheckoutSession($sessionId);
                    $paymentIntent = data_get($remote, 'payment_intent');
                    if (is_object($paymentIntent)) {
                        $paymentIntent = data_get($paymentIntent, 'id');
                    }

                    if (data_get($remote, 'status') !== 'expired'
                        || data_get($remote, 'payment_status') !== 'unpaid'
                        || ! empty($paymentIntent)) {
                        Log::critical('Superseded Checkout Session could not be confirmed expired after another attempt was paid.', [
                            'payment_id' => $paymentId,
                            'successful_session_id' => $successfulSessionId,
                        ]);
                        continue;
                    }

                    $attempt = CheckoutAttempt::query()->where('stripe_session_id', $sessionId)->first();
                    if (! $attempt) {
                        continue;
                    }

                    DB::transaction(function () use ($attempt, $paymentId, $sessionId, $remote): void {
                        $payment = Payment::query()->findOrFail($paymentId);
                        Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
                        Payment::query()->lockForUpdate()->findOrFail($paymentId);
                        $lockedAttempt = CheckoutAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                        if ($lockedAttempt->status !== CheckoutAttemptStatus::Open
                            || $lockedAttempt->stripe_session_id !== $sessionId) {
                            return;
                        }

                        $lockedAttempt->update([
                            'status' => CheckoutAttemptStatus::Expired,
                            'stripe_session_status' => 'expired',
                            'stripe_payment_status' => 'unpaid',
                            'active_key' => null,
                            'last_checked_at' => now(),
                            'last_error_code' => null,
                        ]);
                    }, 3);
                } catch (Throwable $exception) {
                    Log::critical('Unable to expire a superseded Stripe Checkout Session.', [
                        'payment_id' => $paymentId,
                        'exception' => class_basename($exception),
                    ]);
                }
            }
        });
    }

    private function amountInMinorUnits(mixed $amount): ?int
    {
        $amount = (string) $amount;
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            return null;
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }
}
