<?php

namespace App\Services\stripe;

use App\Enums\BookingStatus;
use App\Enums\CheckoutAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\CheckoutAttempt;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Throwable;

class StripeCheckoutService
{
    private const IDEMPOTENCY_RETRY_WINDOW_HOURS = 23;

    public function canStartCheckout(Booking $booking): bool
    {
        return $this->checkoutUnavailableReason($booking) === null;
    }

    public function checkoutUnavailableReason(Booking $booking): ?string
    {
        if ($booking->status !== BookingStatus::PendingPayment) {
            return 'booking_state';
        }
        if ($booking->start_time === null) {
            return 'missing_start_time';
        }
        if (! $booking->start_time->isFuture()) {
            return 'booking_started';
        }
        if ($booking->expires_at !== null && ! $booking->expires_at->isFuture()) {
            return 'booking_expired';
        }

        // Do not render a payable action when this environment has not been migrated
        // for durable checkout attempts; createCheckoutSession requires that table.
        if (! Schema::hasTable('checkout_attempts')) {
            return 'checkout_unavailable';
        }

        $payment = $booking->payment;
        if (! $payment) {
            return null;
        }

        if ($payment->provider !== 'stripe' || in_array($payment->status, [
            PaymentStatus::Paid,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Disputed,
        ], true)) {
            return $payment->provider !== 'stripe' ? 'different_provider' : 'payment_final';
        }

        $activeAttempt = CheckoutAttempt::query()
            ->where('payment_id', $payment->id)
            ->whereNotNull('active_key')
            ->latest('id')
            ->first();

        if ($activeAttempt) {
            $usable = $activeAttempt->status === CheckoutAttemptStatus::Open
                && is_string($activeAttempt->stripe_session_id)
                && $activeAttempt->stripe_session_id !== ''
                && $activeAttempt->stripe_session_status === 'open'
                && $activeAttempt->stripe_payment_status === 'unpaid'
                && $activeAttempt->expires_at !== null
                && $activeAttempt->expires_at->isFuture();

            return $usable ? null : 'checkout_attempt_unavailable';
        }

        $eligible = in_array($payment->status, [
            PaymentStatus::Pending,
            PaymentStatus::Failed,
        ], true) || ($payment->status === PaymentStatus::Processing && (bool) $payment->provider_reference);

        return $eligible ? null : 'payment_ineligible';
    }

    public function isBookingEligibleForCheckout(Booking $booking): bool
    {
        return $booking->status === BookingStatus::PendingPayment
            && $booking->start_time !== null
            && $booking->start_time->isFuture()
            && ($booking->expires_at === null || $booking->expires_at->isFuture());
    }

    public function createCheckoutSession(
        Booking $booking,
        string $requestKey,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
    ): array {
        $requestKey = trim($requestKey);
        if (strlen($requestKey) < 16 || strlen($requestKey) > 255) {
            throw new \InvalidArgumentException('A stable checkout request key is required.');
        }

        $requestKeyHash = hash('sha256', $requestKey);
        $plan = DB::transaction(function () use ($booking, $requestKeyHash) {
            // All checkout code locks Booking, Payment, then CheckoutAttempt.
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            if (! $this->isBookingEligibleForCheckout($lockedBooking)) {
                throw new \DomainException('Booking is not eligible for payment.');
            }

            $payment = Payment::query()
                ->where('booking_id', $lockedBooking->id)
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->provider !== 'stripe') {
                throw new \DomainException('Payment belongs to a different provider.');
            }

            if ($payment && in_array($payment->status, [
                PaymentStatus::Paid,
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
                PaymentStatus::Disputed,
            ], true)) {
                throw new \DomainException('Payment is not eligible for another Checkout session.');
            }

            if (! $payment) {
                $payment = Payment::query()->create([
                    'booking_id' => $lockedBooking->id,
                    'provider' => 'stripe',
                    'amount' => $lockedBooking->total_amount,
                    'currency' => $lockedBooking->currency ?: 'USD',
                    'status' => PaymentStatus::Pending,
                ]);
            }

            if ($this->amountInMinorUnits($payment->getRawOriginal('amount'))
                    !== $this->amountInMinorUnits($lockedBooking->getRawOriginal('total_amount'))
                || strtolower((string) $payment->currency) !== strtolower((string) $lockedBooking->currency)) {
                throw new \DomainException('Payment amount or currency does not match its booking.');
            }

            $fingerprint = $this->requestFingerprint($lockedBooking, $payment);
            $attempts = CheckoutAttempt::query()
                ->where('payment_id', $payment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($attempts as $attempt) {
                if (! in_array($requestKeyHash, $attempt->request_key_hashes ?? [], true)) {
                    continue;
                }
                if (! hash_equals($attempt->request_fingerprint, $fingerprint)) {
                    throw new \DomainException('Idempotency key was reused with different checkout data.');
                }

                return [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'action' => $this->actionFor($attempt),
                    'legacy_reconcile' => false,
                ];
            }

            $activeAttempt = $attempts->first(fn (CheckoutAttempt $attempt) => $attempt->active_key !== null);
            if ($activeAttempt) {
                if (! hash_equals($activeAttempt->request_fingerprint, $fingerprint)) {
                    throw new \DomainException('Active checkout attempt no longer matches the payment data.');
                }

                return [
                    'payment_id' => $payment->id,
                    'attempt_id' => $activeAttempt->id,
                    'action' => $this->actionFor($activeAttempt),
                    'legacy_reconcile' => false,
                ];
            }

            // A pre-migration payment can have a Session ID but no attempt row.
            // Record and inspect it before deciding whether a replacement is safe.
            if ($payment->provider_reference) {
                $legacyUuid = (string) Str::uuid();
                $legacy = CheckoutAttempt::query()->create([
                    'uuid' => $legacyUuid,
                    'payment_id' => $payment->id,
                    'request_key_hash' => hash('sha256', 'legacy:'.$payment->provider_reference),
                    'request_key_hashes' => [],
                    'request_fingerprint' => $fingerprint,
                    'stripe_idempotency_key' => 'legacy-'.$legacyUuid,
                    'stripe_session_id' => $payment->provider_reference,
                    'active_key' => 'checkout-payment-'.$payment->id,
                    'status' => CheckoutAttemptStatus::Unknown,
                    'amount' => $payment->amount,
                    'currency' => strtolower($payment->currency),
                    'is_legacy' => true,
                ]);

                return [
                    'payment_id' => $payment->id,
                    'attempt_id' => $legacy->id,
                    'action' => 'retrieve',
                    'legacy_reconcile' => true,
                ];
            }

            if ($payment->status === PaymentStatus::Processing) {
                throw new \DomainException('Payment is already being processed.');
            }

            $uuid = (string) Str::uuid();
            $attempt = CheckoutAttempt::query()->create([
                'uuid' => $uuid,
                'payment_id' => $payment->id,
                'request_key_hash' => $requestKeyHash,
                'request_key_hashes' => [$requestKeyHash],
                'request_fingerprint' => $fingerprint,
                'stripe_idempotency_key' => 'checkout-attempt-'.$uuid,
                'active_key' => 'checkout-payment-'.$payment->id,
                'status' => CheckoutAttemptStatus::Creating,
                'amount' => $payment->amount,
                'currency' => strtolower($payment->currency),
            ]);
            $payment->update(['status' => PaymentStatus::Processing]);

            return [
                'payment_id' => $payment->id,
                'attempt_id' => $attempt->id,
                'action' => 'create',
                'legacy_reconcile' => false,
            ];
        }, 3);

        $payment = Payment::query()->findOrFail($plan['payment_id']);
        $attempt = CheckoutAttempt::query()->findOrFail($plan['attempt_id']);

        // Eligibility may have changed after the short reservation transaction.
        // Never retrieve or create a provider session for a booking that is no longer payable.
        $currentBooking = Booking::query()->findOrFail($booking->id);
        if (! $this->isBookingEligibleForCheckout($currentBooking)) {
            return $this->result($payment, $attempt, null);
        }

        if ($plan['action'] === 'terminal' || $plan['action'] === 'wait') {
            return $this->result($payment, $attempt, null);
        }

        if ($plan['action'] === 'retrieve') {
            try {
                $session = $this->retrieveSession((string) $attempt->stripe_session_id);
            } catch (Throwable $exception) {
                $attempt = $this->markUnknown($attempt, $exception);
                return $this->result($payment->fresh(), $attempt, null);
            }

            $attempt = $this->saveSessionSnapshot($attempt, $payment, $session, $requestKeyHash);
            if ($attempt->status === CheckoutAttemptStatus::Expired
                && ! in_array($requestKeyHash, $attempt->request_key_hashes ?? [], true)) {
                return $this->createCheckoutSession($booking, $requestKey, $successUrl, $cancelUrl);
            }

            return $this->result($payment->fresh(), $attempt, $this->usableUrl($attempt, $session));
        }

        if ($plan['action'] === 'reconcile') {
            try {
                $matches = $this->findSessionsByAttempt($attempt);
            } catch (Throwable $exception) {
                $attempt = $this->markUnknown($attempt, $exception);
                return $this->result($payment->fresh(), $attempt, null);
            }

            if ($matches['ambiguous']) {
                $attempt = $this->markUnknown($attempt, null, 'multiple_sessions_for_attempt');
                return $this->result($payment->fresh(), $attempt, null);
            }
            if (! $matches['session']) {
                $attempt = $this->markUnknown($attempt, null, 'session_not_found_during_reconciliation');
                return $this->result($payment->fresh(), $attempt, null);
            }

            $attempt = $this->saveSessionSnapshot($attempt, $payment, $matches['session'], $requestKeyHash);
            return $this->result($payment->fresh(), $attempt, $this->usableUrl($attempt, $matches['session']));
        }

        if ($plan['action'] === 'create' || $plan['action'] === 'retry_create') {
            if ($attempt->created_at?->lt(now()->subHours(self::IDEMPOTENCY_RETRY_WINDOW_HOURS))) {
                $attempt = $this->markUnknown($attempt, null, 'idempotency_window_elapsed');
                return $this->result($payment->fresh(), $attempt, null);
            }

            try {
                $bookingForSession = Booking::query()->findOrFail($booking->id);
                $session = $this->createSession($bookingForSession, $payment, $attempt, $successUrl, $cancelUrl);
            } catch (Throwable $exception) {
                $attempt = $this->recordCreateFailure($attempt, $payment, $exception);
                return $this->result($payment->fresh(), $attempt, null);
            }

            try {
                $attempt = $this->saveSessionSnapshot($attempt, $payment, $session, $requestKeyHash);
            } catch (Throwable $exception) {
                // The attempt and Stripe key were committed before the API call.
                // A later retry must use that same key; metadata lets a webhook recover it.
                $attempt = $this->markUnknown($attempt, $exception, 'local_persist_failed');
                return $this->result($payment->fresh(), $attempt, null);
            }

            return $this->result($payment->fresh(), $attempt, $this->usableUrl($attempt, $session));
        }

        return $this->result($payment, $attempt, null);
    }

    protected function createSession(
        Booking $booking,
        Payment $payment,
        CheckoutAttempt $attempt,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
    ): object {
        Stripe::setApiKey(config('services.stripe.secret'));

        return Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($attempt->currency),
                    'product_data' => ['name' => 'Room Booking #'.$booking->id],
                    'unit_amount' => $this->amountInMinorUnits($attempt->amount),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $successUrl ?? config('app.url').'/payment/success',
            'cancel_url' => $cancelUrl ?? config('app.url').'/payment/cancel',
            'client_reference_id' => $attempt->uuid,
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'payment_id' => (string) $payment->id,
                'checkout_attempt_id' => $attempt->uuid,
            ],
        ], ['idempotency_key' => $attempt->stripe_idempotency_key]);
    }

    protected function retrieveSession(string $sessionId): object
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        return Session::retrieve($sessionId);
    }

    public function expireCheckoutSession(string $sessionId): object
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $session = Session::retrieve($sessionId);

        return data_get($session, 'status') === 'open'
            ? Session::expire($sessionId)
            : $session;
    }

    protected function findSessionsByAttempt(CheckoutAttempt $attempt): array
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $matches = [];
        $startingAfter = null;
        $createdAt = $attempt->created_at?->timestamp ?? now()->subDay()->timestamp;
        $pagesRead = 0;
        $maximumPages = 20;

        do {
            if ($pagesRead >= $maximumPages) {
                return ['session' => null, 'ambiguous' => true];
            }

            $parameters = [
                'limit' => 100,
                'created' => ['gte' => $createdAt],
            ];
            if ($startingAfter !== null) {
                $parameters['starting_after'] = $startingAfter;
            }

            $page = Session::all($parameters);
            $pagesRead++;
            foreach ($page->data ?? [] as $session) {
                if (data_get($session, 'metadata.checkout_attempt_id') === $attempt->uuid) {
                    $matches[] = $session;
                    if (count($matches) > 1) {
                        return ['session' => null, 'ambiguous' => true];
                    }
                }
            }

            $last = collect($page->data ?? [])->last();
            $startingAfter = $last ? data_get($last, 'id') : null;
            $hasMore = (bool) ($page->has_more ?? false);
        } while ($hasMore && $startingAfter !== null);

        return ['session' => $matches[0] ?? null, 'ambiguous' => false];
    }

    private function actionFor(CheckoutAttempt $attempt): string
    {
        if (in_array($attempt->status, [CheckoutAttemptStatus::Expired, CheckoutAttemptStatus::Failed, CheckoutAttemptStatus::Succeeded], true)) {
            return 'terminal';
        }
        if ($attempt->status === CheckoutAttemptStatus::Complete) {
            return 'wait';
        }
        if ($attempt->stripe_session_id) {
            return 'retrieve';
        }
        if (in_array($attempt->status, [CheckoutAttemptStatus::Creating, CheckoutAttemptStatus::Unknown], true)) {
            if ($attempt->created_at?->gte(now()->subHours(self::IDEMPOTENCY_RETRY_WINDOW_HOURS))) {
                return 'retry_create';
            }

            return 'reconcile';
        }

        return 'wait';
    }

    private function saveSessionSnapshot(
        CheckoutAttempt $attempt,
        Payment $payment,
        object $session,
        ?string $requestKeyHash = null,
    ): CheckoutAttempt {
        $sessionId = data_get($session, 'id');
        $sessionStatus = data_get($session, 'status');
        $paymentStatus = data_get($session, 'payment_status');
        $paymentIntent = data_get($session, 'payment_intent');
        if (is_object($paymentIntent)) {
            $paymentIntent = data_get($paymentIntent, 'id');
        }

        $metadata = data_get($session, 'metadata', []);
        $attemptMetadata = data_get($metadata, 'checkout_attempt_id');
        $isValid = is_string($sessionId)
            && $sessionId !== ''
            && ($attempt->stripe_session_id === null || $attempt->stripe_session_id === $sessionId)
            && $this->sessionModeMatchesConfiguredKey(data_get($session, 'livemode'))
            && data_get($session, 'mode') === 'payment'
            && (string) data_get($metadata, 'booking_id') === (string) $payment->booking_id
            && (string) data_get($metadata, 'payment_id') === (string) $payment->id
            && ($attempt->is_legacy ? ($attemptMetadata === null || $attemptMetadata === $attempt->uuid) : $attemptMetadata === $attempt->uuid)
            && data_get($session, 'amount_total') === $this->amountInMinorUnits($attempt->amount)
            && strtolower((string) data_get($session, 'currency')) === strtolower($attempt->currency);

        if (! $isValid) {
            return $this->markUnknown($attempt, null, 'session_data_mismatch', $requestKeyHash);
        }

        $newStatus = match (true) {
            $sessionStatus === 'open'
                && $paymentStatus === 'unpaid'
                && is_string(data_get($session, 'url'))
                && data_get($session, 'url') !== ''
                && is_numeric(data_get($session, 'expires_at'))
                && (int) data_get($session, 'expires_at') > now()->timestamp => CheckoutAttemptStatus::Open,
            $sessionStatus === 'complete' => CheckoutAttemptStatus::Complete,
            $sessionStatus === 'expired' && $paymentStatus === 'unpaid' && empty($paymentIntent) => CheckoutAttemptStatus::Expired,
            default => CheckoutAttemptStatus::Unknown,
        };

        return DB::transaction(function () use ($attempt, $payment, $session, $sessionId, $sessionStatus, $paymentStatus, $paymentIntent, $newStatus, $requestKeyHash) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedAttempt = CheckoutAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            $allowed = $this->canTransition($lockedAttempt->status, $newStatus);
            if (! $allowed) {
                return $lockedAttempt;
            }

            $keys = $lockedAttempt->request_key_hashes ?? [];
            if ($requestKeyHash !== null && $newStatus !== CheckoutAttemptStatus::Expired && ! in_array($requestKeyHash, $keys, true)) {
                $keys[] = $requestKeyHash;
            }

            $expiresAt = data_get($session, 'expires_at');
            $lockedAttempt->update([
                'request_key_hashes' => array_values(array_unique($keys)),
                'stripe_session_id' => $sessionId,
                'stripe_payment_intent_id' => is_string($paymentIntent) ? $paymentIntent : null,
                'status' => $newStatus,
                'stripe_session_status' => is_string($sessionStatus) ? $sessionStatus : null,
                'stripe_payment_status' => is_string($paymentStatus) ? $paymentStatus : null,
                'expires_at' => is_numeric($expiresAt) ? now()->setTimestamp((int) $expiresAt) : null,
                'last_checked_at' => now(),
                'last_error_code' => $newStatus === CheckoutAttemptStatus::Unknown ? 'unresolved_session_state' : null,
                'active_key' => $newStatus->holdsPaymentSlot() ? 'checkout-payment-'.$lockedPayment->id : null,
            ]);

            if ($newStatus === CheckoutAttemptStatus::Expired) {
                if ($lockedPayment->provider_reference === $sessionId) {
                    $lockedPayment->provider_reference = null;
                }
                if (in_array($lockedPayment->status, [PaymentStatus::Processing, PaymentStatus::Failed], true)) {
                    $lockedPayment->status = PaymentStatus::Pending;
                }
                $lockedPayment->save();
            } elseif (in_array($newStatus, [CheckoutAttemptStatus::Open, CheckoutAttemptStatus::Complete], true)) {
                $lockedPayment->provider_reference = $sessionId;
                if ($newStatus === CheckoutAttemptStatus::Complete && $lockedPayment->status === PaymentStatus::Pending) {
                    $lockedPayment->status = PaymentStatus::Processing;
                } elseif ($newStatus === CheckoutAttemptStatus::Open && $lockedPayment->status === PaymentStatus::Processing) {
                    $lockedPayment->status = PaymentStatus::Pending;
                }
                $lockedPayment->save();
            } elseif ($newStatus === CheckoutAttemptStatus::Unknown
                && ! in_array($lockedPayment->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed], true)) {
                $lockedPayment->update(['status' => PaymentStatus::Processing]);
            }

            return $lockedAttempt->fresh();
        }, 3);
    }

    private function markUnknown(
        CheckoutAttempt $attempt,
        ?Throwable $exception,
        string $errorCode = 'provider_result_unknown',
        ?string $requestKeyHash = null,
    ): CheckoutAttempt {
        return DB::transaction(function () use ($attempt, $exception, $errorCode, $requestKeyHash) {
            Booking::query()->lockForUpdate()->findOrFail(
                Payment::query()->whereKey($attempt->payment_id)->value('booking_id')
            );
            $payment = Payment::query()->lockForUpdate()->findOrFail($attempt->payment_id);
            $locked = CheckoutAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (in_array($locked->status, [
                CheckoutAttemptStatus::Expired,
                CheckoutAttemptStatus::Failed,
                CheckoutAttemptStatus::Succeeded,
            ], true) || (in_array($locked->status, [
                CheckoutAttemptStatus::Open,
                CheckoutAttemptStatus::Complete,
            ], true) && $errorCode !== 'session_data_mismatch')) {
                return $locked;
            }

            $keys = $locked->request_key_hashes ?? [];
            if ($requestKeyHash !== null && ! in_array($requestKeyHash, $keys, true)) {
                $keys[] = $requestKeyHash;
            }
            $locked->update([
                'request_key_hashes' => array_values(array_unique($keys)),
                'status' => CheckoutAttemptStatus::Unknown,
                'active_key' => 'checkout-payment-'.$payment->id,
                'last_checked_at' => now(),
                'last_error_code' => $exception ? class_basename($exception) : $errorCode,
            ]);
            if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Disputed], true)) {
                $payment->update(['status' => PaymentStatus::Processing]);
            }

            return $locked->fresh();
        }, 3);
    }

    private function recordCreateFailure(CheckoutAttempt $attempt, Payment $payment, Throwable $exception): CheckoutAttempt
    {
        $httpStatus = method_exists($exception, 'getHttpStatus') ? $exception->getHttpStatus() : null;
        $definitive = is_int($httpStatus) && $httpStatus >= 400 && $httpStatus < 500
            && ! in_array($httpStatus, [409, 429], true);

        if (! $definitive) {
            return $this->markUnknown($attempt, $exception);
        }

        return DB::transaction(function () use ($attempt, $payment, $exception) {
            Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedAttempt = CheckoutAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (in_array($lockedAttempt->status, [
                CheckoutAttemptStatus::Open,
                CheckoutAttemptStatus::Complete,
                CheckoutAttemptStatus::Expired,
                CheckoutAttemptStatus::Succeeded,
            ], true) || $lockedAttempt->stripe_session_id !== null) {
                return $lockedAttempt;
            }
            if ($lockedAttempt->status !== CheckoutAttemptStatus::Succeeded) {
                $lockedAttempt->update([
                    'status' => CheckoutAttemptStatus::Failed,
                    'active_key' => null,
                    'last_error_code' => class_basename($exception),
                ]);
            }
            if ($lockedPayment->status === PaymentStatus::Processing && ! $lockedPayment->payment_intent_id) {
                $lockedPayment->update(['status' => PaymentStatus::Pending]);
            }

            return $lockedAttempt->fresh();
        }, 3);
    }

    private function canTransition(CheckoutAttemptStatus $from, CheckoutAttemptStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }
        if ($from === CheckoutAttemptStatus::Succeeded) {
            return false;
        }
        return match ($from) {
            CheckoutAttemptStatus::Creating => in_array($to, [CheckoutAttemptStatus::Open, CheckoutAttemptStatus::Complete, CheckoutAttemptStatus::Expired, CheckoutAttemptStatus::Unknown, CheckoutAttemptStatus::Failed], true),
            CheckoutAttemptStatus::Unknown => in_array($to, [CheckoutAttemptStatus::Open, CheckoutAttemptStatus::Complete, CheckoutAttemptStatus::Expired, CheckoutAttemptStatus::Unknown, CheckoutAttemptStatus::Failed], true),
            CheckoutAttemptStatus::Open => in_array($to, [CheckoutAttemptStatus::Complete, CheckoutAttemptStatus::Expired, CheckoutAttemptStatus::Unknown], true),
            CheckoutAttemptStatus::Complete => $to === CheckoutAttemptStatus::Unknown,
            CheckoutAttemptStatus::Expired,
            CheckoutAttemptStatus::Failed,
            CheckoutAttemptStatus::Succeeded => false,
        };
    }

    private function requestFingerprint(Booking $booking, Payment $payment): string
    {
        return hash('sha256', implode('|', [
            $booking->id,
            $payment->id,
            (string) $payment->getRawOriginal('amount'),
            strtolower((string) $payment->currency),
        ]));
    }

    private function sessionModeMatchesConfiguredKey(mixed $liveMode): bool
    {
        $secret = config('services.stripe.secret');
        if (! is_string($secret)) {
            return false;
        }

        $expectedLiveMode = str_starts_with($secret, 'sk_live_');
        if (! $expectedLiveMode && ! str_starts_with($secret, 'sk_test_')) {
            return false;
        }

        return $liveMode === $expectedLiveMode;
    }

    private function amountInMinorUnits(mixed $amount): int
    {
        $amount = (string) $amount;
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
            throw new \UnexpectedValueException('Invalid local payment amount.');
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function usableUrl(CheckoutAttempt $attempt, object $session): ?string
    {
        $bookingId = Payment::query()->whereKey($attempt->payment_id)->value('booking_id');
        $booking = $bookingId ? Booking::query()->find($bookingId) : null;

        if ($attempt->status !== CheckoutAttemptStatus::Open
            || ! $booking
            || ! $this->canStartCheckout($booking)
            || $attempt->stripe_session_status !== 'open'
            || $attempt->stripe_payment_status !== 'unpaid'
            || $attempt->expires_at === null
            || ! $attempt->expires_at->isFuture()
            || data_get($session, 'status') !== 'open'
            || data_get($session, 'payment_status') !== 'unpaid'
            || ! is_numeric(data_get($session, 'expires_at'))
            || (int) data_get($session, 'expires_at') <= now()->timestamp) {
            return null;
        }

        $url = data_get($session, 'url');
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https'
            ? $url
            : null;
    }

    private function result(Payment $payment, CheckoutAttempt $attempt, ?string $url): array
    {
        return [
            'url' => $url,
            'payment' => $payment->fresh(),
            'attempt' => $attempt->fresh(),
            'status' => $attempt->status->value,
        ];
    }
}
