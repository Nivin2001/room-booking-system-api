<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\BookingStatus;
use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationSource;
use App\Enums\RefundOperationStatus;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\PaymentTransaction;
use App\Models\RefundOperation;
use App\Models\Space;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\BookingApprovalService;
use App\Services\RefundOperationService;
use App\Services\RefundReconciliationService;
use App\Services\BookingService;
use App\Services\stripe\StripeRefundService;
use App\Services\stripe\StripeWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class RefundOperationServiceMySqlTest extends LaravelTestCase
{
    private bool $transactionStarted = false;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app->configurationIsCached()) {
            throw new RuntimeException('Config cache prevents a reliable isolated test connection.');
        }

        $mysql = config('database.connections.mysql');
        if (! is_array($mysql) || empty($mysql['port'])) {
            throw new RuntimeException('The configured MySQL connection or its port is unavailable.');
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => 'room_booking_refund_test',
            'database.connections.mysql.url' => null,
        ]);
        DB::purge('mysql');

        if (DB::connection('mysql')->getDriverName() !== 'mysql'
            || DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db !== 'room_booking_refund_test') {
            throw new RuntimeException('The active test connection is not room_booking_refund_test.');
        }

        foreach (['users', 'spaces', 'bookings', 'payments', 'payment_transactions', 'refund_operations'] as $table) {
            if (! Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException("Required test table is missing: {$table}.");
            }
        }

        DB::connection('mysql')->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }

        DB::disconnect('mysql');

        parent::tearDown();
    }

    public function test_public_space_pages_show_real_available_spaces_without_authentication(): void
    {
        [$payment] = $this->makePaidPayment();
        $booking = Booking::query()->findOrFail($payment->booking_id);
        $space = Space::query()->findOrFail($booking->space_id);

        $listing = $this->get(route('spaces.index'));
        $details = $this->get(route('spaces.show', $space));

        $listing->assertOk()->assertSee($space->title);
        $details->assertOk()->assertSee($space->title)->assertSee($space->location);

        $space->update(['status' => 'unavailable']);
        $this->get(route('spaces.index'))->assertDontSee($space->title);
        $this->get(route('spaces.show', $space))->assertNotFound();
    }

    public function test_valid_checkout_event_completes_payment_atomically_and_duplicate_is_idempotent(): void
    {
        [$payment, $booking] = $this->makePendingCheckout();
        $event = $this->checkoutCompletedEvent($payment, $booking, 'evt_checkout_success_'.$payment->id);
        $event->data->object->customer_details = (object) [
            'email' => 'checkout.private@example.test',
            'name' => 'Checkout Private Name',
            'phone' => '+15555550999',
        ];
        $event->data->object->customer_email = 'checkout.private@example.test';
        $event->data->object->metadata->customer_email = 'checkout.private@example.test';
        $service = new StripeWebhookService;

        $first = $service->processEvent($event, []);
        $second = $service->processEvent($event, []);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('pi_checkout_'.$payment->id, $payment->fresh()->payment_intent_id);
        $this->assertSame(BookingStatus::PendingStaffApproval, $booking->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'payment')->count());
        $webhookLog = WebhookLog::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertNotNull($webhookLog->processed_at);
        $this->assertSame('cs_checkout_'.$payment->id, $webhookLog->payload['data']['object']['id']);
        $this->assertSame((string) $payment->id, $webhookLog->payload['data']['object']['metadata']['payment_id']);
        $this->assertStringNotContainsString('checkout.private@example.test', json_encode($webhookLog->payload));
        $this->assertStringNotContainsString('Checkout Private Name', json_encode($webhookLog->payload));
        $this->assertStringNotContainsString('+15555550999', json_encode($webhookLog->payload));
    }

    public function test_stale_checkout_success_cannot_change_a_refunded_payment_back_to_paid(): void
    {
        [$payment, $booking] = $this->makePendingCheckout();
        $payment->update(['status' => PaymentStatus::Refunded]);
        $booking->update(['status' => BookingStatus::Cancelled]);
        $event = $this->checkoutCompletedEvent($payment, $booking, 'evt_checkout_after_refund_'.$payment->id);

        $response = (new StripeWebhookService)->processEvent($event, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'payment')->count());
        $this->assertNotNull(WebhookLog::query()->where('event_id', $event->id)->firstOrFail()->processed_at);
    }

    public function test_checkout_identity_and_required_fields_are_validated_before_any_write(): void
    {
        [$payment, $booking] = $this->makePendingCheckout();
        $invalidCases = [
            'session id' => fn ($session) => $session->id = 'cs_someone_else',
            'payment id' => fn ($session) => $session->metadata->payment_id = '999999',
            'booking id' => fn ($session) => $session->metadata->booking_id = '999999',
            'payment intent' => fn ($session) => $session->payment_intent = 'pi_someone_else',
            'amount' => fn ($session) => $session->amount_total = 1251,
            'currency' => fn ($session) => $session->currency = 'eur',
            'mode' => fn ($session) => $session->mode = 'subscription',
            'missing mode' => function ($session) { unset($session->mode); },
            'missing amount' => function ($session) { unset($session->amount_total); },
            'missing currency' => function ($session) { unset($session->currency); },
            'missing payment intent' => function ($session) { unset($session->payment_intent); },
        ];

        foreach ($invalidCases as $label => $mutate) {
            if ($label === 'payment intent') {
                $payment->update(['payment_intent_id' => 'pi_checkout_'.$payment->id]);
            }

            $event = $this->checkoutCompletedEvent($payment, $booking, 'evt_checkout_invalid_'.$payment->id.'_'.str_replace(' ', '_', $label));
            $mutate($event->data->object);

            $response = (new StripeWebhookService)->processEvent($event, []);

            $this->assertSame(422, $response->getStatusCode(), $label);
            $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status, $label);
            $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status, $label);
            $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->count(), $label);
            $this->assertNull(WebhookLog::query()->where('event_id', $event->id)->firstOrFail()->processed_at, $label);
        }
    }

    public function test_ineligible_booking_transition_records_conflict_evidence_and_acknowledges_event(): void
    {
        [$payment, $booking] = $this->makePendingCheckout();
        $booking->update(['status' => BookingStatus::Confirmed]);
        $event = $this->checkoutCompletedEvent($payment, $booking, 'evt_checkout_invalid_transition_'.$payment->id);

        $response = (new StripeWebhookService)->processEvent($event, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        $transaction = PaymentTransaction::query()->where('stripe_event_id', $event->id)->firstOrFail();
        $this->assertSame($payment->id, $transaction->payment_id);
        $this->assertSame('payment_conflict', $transaction->type);
        $this->assertSame('pi_checkout_'.$payment->id, $transaction->meta['payment_intent_id']);

        $log = WebhookLog::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertNotNull($log->processed_at);

        $duplicateResponse = (new StripeWebhookService)->processEvent($event, []);

        $this->assertSame(200, $duplicateResponse->getStatusCode());
        $this->assertSame(1, PaymentTransaction::query()->where('stripe_event_id', $event->id)->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_booking_transition_exception_rolls_back_all_checkout_writes(): void
    {
        [$payment, $booking] = $this->makePendingCheckout();
        $event = $this->checkoutCompletedEvent($payment, $booking, 'evt_checkout_transition_failure_'.$payment->id);
        Booking::updating(function (Booking $model): void {
            if ($model->status === BookingStatus::PendingStaffApproval) {
                throw new \DomainException('Synthetic booking transition failure.');
            }
        });

        $response = (new StripeWebhookService)->processEvent($event, []);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
        $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->count());
        $this->assertNull(WebhookLog::query()->where('event_id', $event->id)->firstOrFail()->processed_at);
    }

    public function test_reservation_is_idempotent_and_does_not_create_payment_side_effects(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;

        $first = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'request-1');
        $again = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'request-1');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, RefundOperation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame('payment-refund:'.$payment->id, $first->active_key);
        $this->assertSame(1250, $first->amount_minor);
        $this->assertSame('USD', $first->currency);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->count());
    }

    public function test_an_active_operation_blocks_a_new_request(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'request-active');

        try {
            $service->reserve($payment, RefundOperationSource::StaffReject, $requester, 'request-distinct');
            $this->fail('A second active refund reservation was unexpectedly accepted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('already active', $exception->getMessage());
        }

        $this->assertSame(1, RefundOperation::query()->where('payment_id', $payment->id)->count());
    }

    #[DataProvider('activeStatuses')]
    public function test_active_statuses_retain_the_active_key_and_block_another_request(RefundOperationStatus $status): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $operation = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'state-'.$status->value);
        $operation = $this->moveToActiveStatus($service, $operation, $status);

        $this->assertSame($status, $operation->status);
        $this->assertSame('payment-refund:'.$payment->id, $operation->active_key);

        try {
            $service->reserve($payment, RefundOperationSource::StaffReject, $requester, 'different-'.$status->value);
            $this->fail("A second reservation was accepted while status was {$status->value}.");
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('already active', $exception->getMessage());
        }
    }

    public static function activeStatuses(): array
    {
        return [
            'requested' => [RefundOperationStatus::Requested],
            'processing' => [RefundOperationStatus::Processing],
            'unknown' => [RefundOperationStatus::Unknown],
            'pending' => [RefundOperationStatus::Pending],
            'requires_action' => [RefundOperationStatus::RequiresAction],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_statuses_release_active_key_and_preserve_history(RefundOperationStatus $status, bool $allowsNewRequest): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $operation = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'terminal-'.$status->value);
        $operation = $service->transition(
            $service->transition($operation, RefundOperationStatus::Processing),
            $status,
            RefundOperationEvidence::ProviderResponse,
        );

        $this->assertNull($operation->active_key);
        $this->assertNotNull($operation->completed_at);

        if ($allowsNewRequest) {
            $next = $service->reserve($payment, RefundOperationSource::StaffReject, $requester, 'new-'.$status->value);
            $this->assertNotSame($operation->id, $next->id);
            $this->assertSame(2, RefundOperation::query()->where('payment_id', $payment->id)->count());
        } else {
            $this->expectException(\DomainException::class);
            $service->reserve($payment, RefundOperationSource::StaffReject, $requester, 'new-'.$status->value);
        }
    }

    public static function terminalStatuses(): array
    {
        return [
            'succeeded' => [RefundOperationStatus::Succeeded, false],
            'failed' => [RefundOperationStatus::Failed, true],
            'canceled' => [RefundOperationStatus::Canceled, true],
        ];
    }

    public function test_terminal_operations_cannot_be_reopened(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $operation = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'terminal-immutable');
        $operation = $service->transition(
            $service->transition($operation, RefundOperationStatus::Processing),
            RefundOperationStatus::Failed,
            RefundOperationEvidence::ProviderResponse,
        );

        $this->expectException(\DomainException::class);
        $service->transition($operation, RefundOperationStatus::Processing);
    }

    public function test_request_key_cannot_be_reused_with_conflicting_source_or_requester(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        [, $otherRequester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'bound-request-key');

        foreach ([
            fn () => $service->reserve($payment, RefundOperationSource::StaffReject, $requester, 'bound-request-key'),
            fn () => $service->reserve($payment, RefundOperationSource::CustomerCancel, $otherRequester, 'bound-request-key'),
            fn () => $service->reserve($this->makePaidPayment()[0], RefundOperationSource::CustomerCancel, $requester, 'bound-request-key'),
        ] as $conflictingRequest) {
            try {
                $conflictingRequest();
                $this->fail('A request key was accepted with conflicting request attributes.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('different request', $exception->getMessage());
            }
        }
    }

    public function test_unknown_remains_active_until_a_confirmed_outcome_is_recorded(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $operation = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'unknown-outcome');
        $operation = $service->transition($operation, RefundOperationStatus::Processing);
        $operation = $service->transition($operation, RefundOperationStatus::Unknown);

        foreach ([RefundOperationStatus::Failed, RefundOperationStatus::Canceled] as $terminal) {
            try {
                $service->transition($operation, $terminal);
                $this->fail('Unknown was finalized without confirmed outcome evidence.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('requires confirmed', $exception->getMessage());
            }
        }

        $operation->refresh();
        $this->assertSame(RefundOperationStatus::Unknown, $operation->status);
        $this->assertSame('payment-refund:'.$payment->id, $operation->active_key);
        $this->expectException(\DomainException::class);
        $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'unknown-retry');
    }

    public function test_confirmed_failure_allows_a_new_attempt_but_full_success_does_not(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $failed = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'confirmed-failure');
        $failed = $service->transition($failed, RefundOperationStatus::Processing);
        $failed = $service->transition($failed, RefundOperationStatus::Failed, RefundOperationEvidence::ProviderResponse);

        $retry = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'confirmed-failure-retry');
        $this->assertNotSame($failed->id, $retry->id);

        $retry = $service->transition($retry, RefundOperationStatus::Processing);
        $success = $service->transition($retry, RefundOperationStatus::Succeeded, RefundOperationEvidence::Webhook);
        $this->assertSame(RefundOperationStatus::Succeeded, $success->status);

        $this->expectException(\DomainException::class);
        $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'after-full-success');
    }

    public function test_confirmed_not_submitted_result_allows_retry_from_requested(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $operation = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'not-submitted');
        $operation = $service->transition(
            $operation,
            RefundOperationStatus::Failed,
            RefundOperationEvidence::NotSubmitted,
        );

        $this->assertSame(RefundOperationStatus::Failed, $operation->status);
        $this->assertNull($operation->active_key);
        $this->assertNotNull($operation->last_checked_at);

        $retry = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'not-submitted-retry');
        $this->assertNotSame($operation->id, $retry->id);
    }

    public function test_retry_eligibility_does_not_treat_last_checked_at_as_provider_evidence(): void
    {
        [$payment, $requester] = $this->makePaidPayment();
        $service = new RefundOperationService;
        $failed = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'timestamp-not-evidence');
        $failed = $service->transition($failed, RefundOperationStatus::Processing);
        $failed = $service->transition($failed, RefundOperationStatus::Failed, RefundOperationEvidence::ProviderResponse);
        $failed->update(['last_checked_at' => null]);

        $retry = $service->reserve($payment, RefundOperationSource::CustomerCancel, $requester, 'timestamp-not-evidence-retry');

        $this->assertSame(RefundOperationStatus::Failed, $failed->fresh()->status);
        $this->assertNull($failed->fresh()->last_checked_at);
        $this->assertNotSame($failed->id, $retry->id);
    }

    public function test_transition_graph_allows_only_declared_forward_transitions(): void
    {
        $allowed = [
            RefundOperationStatus::Requested->value => ['processing', 'failed', 'canceled'],
            RefundOperationStatus::Processing->value => ['unknown', 'pending', 'requires_action', 'succeeded', 'failed', 'canceled'],
            RefundOperationStatus::Unknown->value => ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'],
            RefundOperationStatus::Pending->value => ['unknown', 'requires_action', 'succeeded', 'failed', 'canceled'],
            RefundOperationStatus::RequiresAction->value => ['processing', 'pending', 'unknown', 'succeeded', 'failed', 'canceled'],
            RefundOperationStatus::Succeeded->value => [],
            RefundOperationStatus::Failed->value => [],
            RefundOperationStatus::Canceled->value => [],
        ];

        foreach (RefundOperationStatus::cases() as $current) {
            foreach (RefundOperationStatus::cases() as $next) {
                $this->assertSame(
                    in_array($next->value, $allowed[$current->value], true),
                    $current->canTransitionTo($next),
                    $current->value.' -> '.$next->value,
                );
            }
        }
    }

    private function moveToActiveStatus(RefundOperationService $service, RefundOperation $operation, RefundOperationStatus $target): RefundOperation
    {
        if ($target === RefundOperationStatus::Requested) {
            return $operation;
        }

        $operation = $service->transition($operation, RefundOperationStatus::Processing);

        return $target === RefundOperationStatus::Processing
            ? $operation
            : $service->transition($operation, $target);
    }

    public function test_customer_cancellation_refunds_a_paid_booking_and_is_idempotent(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $stripeCalls = 0;
        $baselineTransactionLevel = DB::connection('mysql')->transactionLevel();
        $this->fakeStripeRefundService(function (Payment $receivedPayment, RefundOperation $operation) use ($payment, &$stripeCalls, $baselineTransactionLevel) {
            $stripeCalls++;
            $this->assertSame($payment->id, $receivedPayment->id);
            $this->assertSame(RefundOperationStatus::Processing, $operation->fresh()->status);
            $this->assertNotSame('refund-payment-'.$payment->id, $operation->idempotency_key);
            $this->assertSame($baselineTransactionLevel, DB::connection('mysql')->transactionLevel(), 'The Stripe call must happen after the service transaction commits.');

            return (object) ['id' => 're_customer_success_'.$payment->id, 'status' => 'succeeded'];
        });

        $service = app(BookingService::class);
        $booking = $service->cancelBooking($customer, $payment->booking_id, 'customer-cancel-'.$payment->id);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(RefundOperationStatus::Succeeded, $operation->status);
        $this->assertSame('re_customer_success_'.$payment->id, $operation->stripe_refund_id);
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());

        $replayed = $service->cancelBooking($customer, $payment->booking_id, 'customer-cancel-'.$payment->id);
        $this->assertSame(BookingStatus::Cancelled, $replayed->status);
        $this->assertSame($operation->id, $replayed->getAttribute('refund_operation_id'));
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        $this->assertSame(1, $stripeCalls);

        $this->expectException(\DomainException::class);
        $service->cancelBooking($customer, $payment->booking_id, 'different-cancel-key-'.$payment->id);
    }

    public function test_confirmed_stripe_failure_does_not_change_payment_or_booking(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $this->fakeStripeRefundService(fn () => (object) [
            'id' => 're_customer_failed_'.$payment->id,
            'status' => 'failed',
        ]);

        $booking = app(BookingService::class)->cancelBooking($customer, $payment->booking_id, 'customer-fail-'.$payment->id);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('failed', $booking->getAttribute('refund_operation_status'));
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(RefundOperationStatus::Failed, $operation->status);
        $this->assertNull($operation->active_key);
        $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'refund')->count());
    }

    public function test_new_idempotency_key_can_retry_after_a_confirmed_failure(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $results = [
            (object) ['id' => 're_customer_retry_fail_'.$payment->id, 'status' => 'failed'],
            (object) ['id' => 're_customer_retry_success_'.$payment->id, 'status' => 'succeeded'],
        ];
        $this->fakeStripeRefundService(function () use (&$results) {
            return array_shift($results);
        });
        $service = app(BookingService::class);

        $first = $service->cancelBooking($customer, $payment->booking_id, 'customer-failed-attempt-'.$payment->id);
        $second = $service->cancelBooking($customer, $payment->booking_id, 'customer-retry-attempt-'.$payment->id);
        $operations = RefundOperation::query()->where('payment_id', $payment->id)->orderBy('id')->get();

        $this->assertSame(2, $operations->count());
        $this->assertSame(RefundOperationStatus::Failed, $operations[0]->status);
        $this->assertSame(RefundOperationStatus::Succeeded, $operations[1]->status);
        $this->assertSame(BookingStatus::Confirmed, $first->status);
        $this->assertSame(BookingStatus::Cancelled, $second->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'refund')->count());
    }

    public function test_timeout_keeps_refund_unknown_and_replay_does_not_submit_another_refund(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $stripeCalls = 0;
        Log::shouldReceive('warning')->once();
        $this->fakeStripeRefundService(function () use (&$stripeCalls) {
            $stripeCalls++;
            throw new RuntimeException('Simulated provider timeout.');
        });

        $service = app(BookingService::class);
        $key = 'customer-timeout-'.$payment->id;
        $booking = $service->cancelBooking($customer, $payment->booking_id, $key);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('unknown', $booking->getAttribute('refund_operation_status'));
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(RefundOperationStatus::Unknown, $operation->status);
        $this->assertSame('payment-refund:'.$payment->id, $operation->active_key);
        $this->assertNotNull($operation->last_checked_at);
        $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'refund')->count());

        $replayed = $service->cancelBooking($customer, $payment->booking_id, $key);
        $this->assertSame($operation->id, $replayed->getAttribute('refund_operation_id'));
        $this->assertSame(1, $stripeCalls);
    }

    public function test_pending_stripe_refund_is_not_treated_as_success(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $this->fakeStripeRefundService(function (Payment $receivedPayment, RefundOperation $operation) use ($payment) {
            $this->assertSame(PaymentStatus::Paid, $receivedPayment->fresh()->status);
            $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
            $this->assertSame(RefundOperationStatus::Processing, $operation->fresh()->status);

            return (object) ['id' => 're_customer_pending_'.$payment->id, 'status' => 'pending'];
        });

        $booking = app(BookingService::class)->cancelBooking($customer, $payment->booking_id, 'customer-pending-'.$payment->id);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(RefundOperationStatus::Pending, $operation->status);
        $this->assertSame('payment-refund:'.$payment->id, $operation->active_key);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_customer_cannot_cancel_pending_staff_approval_booking(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        $this->fakeStripeRefundService(fn () => throw new RuntimeException('Stripe must not be called.'));

        try {
            app(BookingService::class)->cancelBooking($customer, $payment->booking_id, 'blocked-cancel-'.$payment->id);
            $this->fail('Customer cancellation was allowed during staff approval.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('not allowed', $exception->getMessage());
        }

        $this->assertSame(0, RefundOperation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(BookingStatus::PendingStaffApproval, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_unpaid_pending_booking_cancellation_keeps_existing_behavior_without_refund(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $payment->update(['status' => PaymentStatus::Pending]);
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingPayment->value]);
        $this->fakeStripeRefundService(fn () => throw new RuntimeException('Stripe must not be called.'));

        $booking = app(BookingService::class)->cancelBooking($customer, $payment->booking_id);

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, RefundOperation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'refund')->count());
    }

    public function test_staff_rejection_uses_refund_operation_and_replays_success_without_duplicate_transaction(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        $staff = $this->makeStaffForBooking($payment->booking_id);
        Auth::login($staff);
        Event::fake();
        $this->mockBookingStateLock((int) $payment->booking_id, 2);
        $key = 'staff-reject-success-'.$payment->id;
        $stripeCalls = 0;
        $this->fakeStripeRefundService(function (Payment $receivedPayment, RefundOperation $operation) use ($payment, $staff, $key, &$stripeCalls) {
            $stripeCalls++;
            $this->assertSame($payment->id, $receivedPayment->id);
            $this->assertSame(RefundOperationSource::StaffReject, $operation->source);
            $this->assertSame($staff->id, $operation->requested_by);
            $this->assertSame('refund-op-'.hash('sha256', $key), $operation->idempotency_key);
            $this->assertSame(RefundOperationStatus::Processing, $operation->fresh()->status);

            return (object) ['id' => 're_staff_success_'.$payment->id, 'status' => 'succeeded'];
        });

        $service = app(BookingApprovalService::class);
        $first = $service->reject($payment->booking_id, $key);
        $again = $service->reject($payment->booking_id, $key);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::Rejected, $first->status);
        $this->assertSame(BookingStatus::Rejected, $again->status);
        $this->assertSame(RefundOperationStatus::Succeeded, $operation->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(1, RefundOperation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        $this->assertSame(1, $stripeCalls);
        Event::assertDispatchedTimes(\App\Events\BookingRejected::class, 1);
    }

    public function test_confirmed_staff_refund_failure_leaves_booking_pending_approval_and_does_not_record_refund(): void
    {
        [$payment] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        Auth::login($this->makeStaffForBooking($payment->booking_id));
        $this->mockBookingStateLock((int) $payment->booking_id);
        $this->fakeStripeRefundService(fn () => (object) [
            'id' => 're_staff_failed_'.$payment->id,
            'status' => 'failed',
        ]);

        $booking = app(BookingApprovalService::class)->reject($payment->booking_id, 'staff-reject-failed-'.$payment->id);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::PendingStaffApproval, $booking->status);
        $this->assertSame(RefundOperationStatus::Failed, $operation->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_unknown_staff_refund_replay_does_not_submit_a_second_refund(): void
    {
        [$payment] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        Auth::login($this->makeStaffForBooking($payment->booking_id));
        $this->mockBookingStateLock((int) $payment->booking_id, 2);
        $stripeCalls = 0;
        $this->fakeStripeRefundService(function () use (&$stripeCalls) {
            $stripeCalls++;
            throw new RuntimeException('Simulated refund timeout.');
        });
        $service = app(BookingApprovalService::class);
        $key = 'staff-reject-timeout-'.$payment->id;

        $first = $service->reject($payment->booking_id, $key);
        $second = $service->reject($payment->booking_id, $key);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::PendingStaffApproval, $first->status);
        $this->assertSame(BookingStatus::PendingStaffApproval, $second->status);
        $this->assertSame(RefundOperationStatus::Unknown, $operation->status);
        $this->assertSame('payment-refund:'.$payment->id, $operation->active_key);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        $this->assertSame(1, $stripeCalls);
    }

    public function test_pending_and_requires_action_staff_refunds_do_not_reject_booking_or_mark_payment_refunded(): void
    {
        foreach (['pending', 'requires_action'] as $stripeStatus) {
            [$payment] = $this->makePaidPayment();
            Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
            Auth::login($this->makeStaffForBooking($payment->booking_id));
            $this->mockBookingStateLock((int) $payment->booking_id);
            $this->fakeStripeRefundService(fn () => (object) [
                'id' => 're_staff_'.$stripeStatus.'_'.$payment->id,
                'status' => $stripeStatus,
            ]);

            $booking = app(BookingApprovalService::class)->reject($payment->booking_id, 'staff-'.$stripeStatus.'-'.$payment->id);
            $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();
            $expectedStatus = $stripeStatus === 'pending' ? RefundOperationStatus::Pending : RefundOperationStatus::RequiresAction;

            $this->assertSame(BookingStatus::PendingStaffApproval, $booking->status);
            $this->assertSame($expectedStatus, $operation->status);
            $this->assertNotNull($operation->active_key);
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        }
    }

    public function test_conflicting_stripe_refund_response_stays_unknown_and_does_not_change_financial_state(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $this->fakeStripeRefundService(fn (Payment $receivedPayment, RefundOperation $operation) => (object) [
            'id' => 're_mismatched_'.$payment->id,
            'status' => 'succeeded',
            'payment_intent' => 'pi_not_the_local_payment',
            'amount' => $operation->amount_minor,
            'currency' => strtolower($operation->currency),
            'metadata' => (object) ['refund_operation_id' => (string) $operation->id],
        ]);

        $booking = app(BookingService::class)->cancelBooking($customer, $payment->booking_id, 'mismatched-response-'.$payment->id);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(RefundOperationStatus::Unknown, $operation->status);
        $this->assertSame('payment-refund:'.$payment->id, $operation->active_key);
        $this->assertNull($operation->stripe_refund_id);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_signed_refund_webhook_is_verified_deduplicated_and_does_not_regress_on_late_event(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        $staff = $this->makeStaffForBooking($payment->booking_id);
        $operationService = app(RefundOperationService::class);
        $operation = $operationService->reserve($payment, RefundOperationSource::StaffReject, $staff, 'webhook-refund-'.$payment->id);
        $operation = $operationService->transition($operation, RefundOperationStatus::Processing);
        $refund = $this->stripeRefundCandidate($operation, $payment, 're_webhook_'.$payment->id, 'succeeded');
        $event = $this->refundWebhookPayload('evt_refund_success_'.$payment->id, 'refund.updated', $refund);
        $event['data']['object']['customer_email'] = 'refund.private@example.test';
        $event['data']['object']['billing_details'] = ['name' => 'Refund Private Name'];
        $event['data']['object']['metadata']->customer_phone = '+15555550888';
        $secret = 'whsec_local_refund_webhook_test';
        config(['services.stripe.webhook_secret' => $secret]);
        $service = app(StripeWebhookService::class);

        $first = $service->handle($this->signedRefundWebhookRequest($event, $secret));
        $duplicate = $service->handle($this->signedRefundWebhookRequest($event, $secret));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $duplicate->getStatusCode());
        $this->assertSame(RefundOperationStatus::Succeeded, $operation->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Rejected, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        $this->assertSame(1, WebhookLog::query()->where('event_id', $event['id'])->count());
        $storedPayload = WebhookLog::query()->where('event_id', $event['id'])->firstOrFail()->payload;
        $this->assertSame('re_webhook_'.$payment->id, $storedPayload['data']['object']['id']);
        $this->assertSame((string) $operation->id, $storedPayload['data']['object']['metadata']['refund_operation_id']);
        $this->assertStringNotContainsString('refund.private@example.test', json_encode($storedPayload));
        $this->assertStringNotContainsString('Refund Private Name', json_encode($storedPayload));
        $this->assertStringNotContainsString('+15555550888', json_encode($storedPayload));

        $lateRefund = $this->stripeRefundCandidate($operation->fresh(), $payment->fresh(), 're_webhook_'.$payment->id, 'pending');
        $lateEvent = $this->refundWebhookPayload('evt_refund_late_pending_'.$payment->id, 'refund.updated', $lateRefund);
        $late = $service->handle($this->signedRefundWebhookRequest($lateEvent, $secret));

        $this->assertSame(200, $late->getStatusCode());
        $this->assertSame(RefundOperationStatus::Succeeded, $operation->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Rejected, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_invalid_refund_webhook_signature_cannot_change_local_state(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        $staff = $this->makeStaffForBooking($payment->booking_id);
        $operationService = app(RefundOperationService::class);
        $operation = $operationService->reserve($payment, RefundOperationSource::StaffReject, $staff, 'invalid-signature-'.$payment->id);
        $operation = $operationService->transition($operation, RefundOperationStatus::Processing);
        $refund = $this->stripeRefundCandidate($operation, $payment, 're_invalid_signature_'.$payment->id, 'succeeded');
        $event = $this->refundWebhookPayload('evt_refund_bad_sig_'.$payment->id, 'refund.updated', $refund);
        config(['services.stripe.webhook_secret' => 'whsec_expected_test_value']);
        $request = $this->signedRefundWebhookRequest($event, 'whsec_wrong_test_value');

        $response = app(StripeWebhookService::class)->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(RefundOperationStatus::Processing, $operation->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::PendingStaffApproval, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(0, WebhookLog::query()->where('event_id', $event['id'])->count());
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_reconciliation_command_scans_only_active_provider_outcomes(): void
    {
        [, , $target] = $this->makeProcessingRefundOperation();
        $seen = [];
        $this->mock(RefundReconciliationService::class)
            ->shouldReceive('reconcile')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (RefundOperation $operation) use (&$seen) {
                $seen[] = (int) $operation->id;

                return $operation;
            });

        $exitCode = Artisan::call('refunds:reconcile', ['--limit' => 1000]);

        $this->assertSame(0, $exitCode);
        $this->assertContains((int) $target->id, $seen);
        $this->assertStringContainsString('unresolved', Artisan::output());
    }

    public function test_reconciliation_during_an_in_flight_refund_only_looks_up_and_never_submits_another_refund(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        $stripeSubmissions = 0;
        $lookups = 0;

        $this->fakeStripeRefundService(function (Payment $receivedPayment, RefundOperation $operation) use ($payment, &$stripeSubmissions, &$lookups) {
            $stripeSubmissions++;
            $this->assertSame($payment->id, $receivedPayment->id);
            $this->assertSame(RefundOperationStatus::Processing, $operation->fresh()->status);

            $this->mockRedisReconciliationLock((int) $operation->id, true);
            $this->fakeStripeRefundLookup(
                fn () => throw new RuntimeException('No refund ID should exist before the provider response.'),
                function (string $paymentIntentId) use ($payment, &$lookups): array {
                    $lookups++;
                    $this->assertSame($payment->payment_intent_id, $paymentIntentId);

                    return [];
                },
            );

            $interleaved = app(RefundReconciliationService::class)->reconcile($operation);
            $this->assertSame(RefundOperationStatus::Processing, $interleaved->status);

            return (object) [
                'id' => 're_inflight_'.$payment->id,
                'status' => 'succeeded',
                'payment_intent' => $payment->payment_intent_id,
                'amount' => $operation->amount_minor,
                'currency' => strtolower($operation->currency),
                'metadata' => (object) ['refund_operation_id' => (string) $operation->id],
            ];
        });

        $booking = app(BookingService::class)->cancelBooking($customer, $payment->booking_id, 'inflight-reconcile-'.$payment->id);
        $operation = RefundOperation::query()->where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame(1, $stripeSubmissions);
        $this->assertSame(1, $lookups);
        $this->assertSame(RefundOperationStatus::Succeeded, $operation->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_customer_cancellation_interleaved_with_staff_rejection_cannot_reserve_a_second_refund(): void
    {
        [$payment, $customer] = $this->makePaidPayment();
        Booking::query()->whereKey($payment->booking_id)->update(['status' => BookingStatus::PendingStaffApproval->value]);
        Auth::login($this->makeStaffForBooking($payment->booking_id));
        $this->mockBookingStateLock((int) $payment->booking_id);
        $stripeCalls = 0;
        $this->fakeStripeRefundService(function () use ($payment, $customer, &$stripeCalls) {
            $stripeCalls++;
            try {
                app(BookingService::class)->cancelBooking($customer, $payment->booking_id, 'racing-customer-cancel-'.$payment->id);
                $this->fail('Customer cancellation must remain blocked during staff approval.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('not allowed', $exception->getMessage());
            }

            return (object) ['id' => 're_staff_race_'.$payment->id, 'status' => 'succeeded'];
        });

        $booking = app(BookingApprovalService::class)->reject($payment->booking_id, 'staff-race-'.$payment->id);
        $operations = RefundOperation::query()->where('payment_id', $payment->id)->get();

        $this->assertSame(BookingStatus::Rejected, $booking->status);
        $this->assertCount(1, $operations);
        $this->assertSame(RefundOperationSource::StaffReject, $operations->first()->source);
        $this->assertSame(1, $stripeCalls);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'refund')->count());
    }

    public function test_reconciliation_records_a_matching_success_atomically_and_is_repeatable(): void
    {
        [$payment, $customer, $operation] = $this->makeProcessingRefundOperation();
        $originalIdempotencyKey = $operation->idempotency_key;
        $stripeListCalls = 0;
        $this->mockRedisReconciliationLock((int) $operation->id, true);
        $this->fakeStripeRefundLookup(
            fn () => throw new RuntimeException('ID lookup should not be used.'),
            function (string $paymentIntentId) use ($operation, $payment, &$stripeListCalls) {
                $stripeListCalls++;
                $this->assertSame($payment->payment_intent_id, $paymentIntentId);

                return [$this->stripeRefundCandidate($operation, $payment, 're_reconcile_success_'.$payment->id, 'succeeded')];
            },
        );

        $service = app(RefundReconciliationService::class);
        $reconciled = $service->reconcile($operation);

        $this->assertSame(RefundOperationStatus::Succeeded, $reconciled->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Cancelled, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        $this->assertSame($originalIdempotencyKey, $reconciled->idempotency_key);

        $again = $service->reconcile($reconciled);
        $this->assertSame(RefundOperationStatus::Succeeded, $again->status);
        $this->assertSame(1, $stripeListCalls);
        $this->assertSame(1, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_reconciliation_keeps_pending_refund_active_without_payment_side_effects(): void
    {
        [$payment, , $operation] = $this->makeProcessingRefundOperation();
        $this->mockRedisReconciliationLock((int) $operation->id, true);
        $this->fakeStripeRefundLookup(
            fn () => null,
            fn () => [$this->stripeRefundCandidate($operation, $payment, 're_reconcile_pending_'.$payment->id, 'pending')],
        );

        $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

        $this->assertSame(RefundOperationStatus::Pending, $reconciled->status);
        $this->assertSame('payment-refund:'.$payment->id, $reconciled->active_key);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_reconciliation_keeps_requires_action_refund_active_without_payment_side_effects(): void
    {
        [$payment, , $operation] = $this->makeProcessingRefundOperation();
        $this->mockRedisReconciliationLock((int) $operation->id, true);
        $this->fakeStripeRefundLookup(
            fn () => null,
            fn () => [$this->stripeRefundCandidate($operation, $payment, 're_reconcile_action_'.$payment->id, 'requires_action')],
        );

        $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

        $this->assertSame(RefundOperationStatus::RequiresAction, $reconciled->status);
        $this->assertSame('payment-refund:'.$payment->id, $reconciled->active_key);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_reconciliation_applies_only_confirmed_failed_or_canceled_refund_results(): void
    {
        foreach (['failed' => RefundOperationStatus::Failed, 'canceled' => RefundOperationStatus::Canceled] as $stripeStatus => $expectedStatus) {
            [$payment, , $operation] = $this->makeProcessingRefundOperation();
            $this->mockRedisReconciliationLock((int) $operation->id, true);
            $this->fakeStripeRefundLookup(
                fn () => null,
                fn () => [$this->stripeRefundCandidate($operation, $payment, 're_reconcile_'.$stripeStatus.'_'.$payment->id, $stripeStatus)],
            );

            $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

            $this->assertSame($expectedStatus, $reconciled->status);
            $this->assertNull($reconciled->active_key);
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
            $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        }
    }

    public function test_unmatched_or_conflicting_refunds_leave_local_payment_and_booking_unchanged(): void
    {
        foreach (['amount', 'currency', 'payment_intent', 'metadata'] as $field) {
            [$payment, , $operation] = $this->makeProcessingRefundOperation();
            $candidate = $this->stripeRefundCandidate($operation, $payment, 're_reconcile_mismatch_'.$field.'_'.$payment->id, 'succeeded');
            match ($field) {
                'amount' => $candidate->amount++,
                'currency' => $candidate->currency = 'eur',
                'payment_intent' => $candidate->payment_intent = 'pi_other',
                'metadata' => $candidate->metadata->refund_operation_id = 'another-operation',
            };
            $this->mockRedisReconciliationLock((int) $operation->id, true);
            $this->fakeStripeRefundLookup(fn () => null, fn () => [$candidate]);

            $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

            $this->assertSame(RefundOperationStatus::Processing, $reconciled->status, "Mismatch in {$field} must remain unresolved.");
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
            $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
            $this->assertNull($reconciled->stripe_refund_id);
        }
    }

    public function test_reconciliation_does_not_guess_when_no_or_multiple_refunds_match(): void
    {
        foreach ([[], 'multiple'] as $resultKind) {
            [$payment, , $operation] = $this->makeProcessingRefundOperation();
            $refunds = $resultKind === []
                ? []
                : [
                    $this->stripeRefundCandidate($operation, $payment, 're_reconcile_first_'.$payment->id, 'succeeded'),
                    $this->stripeRefundCandidate($operation, $payment, 're_reconcile_second_'.$payment->id, 'succeeded'),
                ];
            $this->mockRedisReconciliationLock((int) $operation->id, true);
            $this->fakeStripeRefundLookup(fn () => null, fn () => $refunds);

            $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

            $this->assertSame(RefundOperationStatus::Processing, $reconciled->status);
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
            $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
        }
    }

    public function test_reconciliation_retrieves_by_known_id_and_saves_same_status_evidence(): void
    {
        [$payment, , $operation] = $this->makeProcessingRefundOperation();
        $operation = app(RefundOperationService::class)->transition(
            $operation,
            RefundOperationStatus::Unknown,
            RefundOperationEvidence::ProviderResponse,
        );
        $candidate = $this->stripeRefundCandidate($operation, $payment, 're_known_'.$payment->id, 'future_status');
        $operation->update(['stripe_refund_id' => $candidate->id]);
        $operation->refresh();
        $this->mockRedisReconciliationLock((int) $operation->id, true);
        $this->fakeStripeRefundLookup(
            fn (string $id) => $id === $candidate->id ? $candidate : null,
            fn () => throw new RuntimeException('List call should not be used when a refund ID exists.'),
        );

        $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

        $this->assertSame(RefundOperationStatus::Unknown, $reconciled->status);
        $this->assertSame($candidate->id, $reconciled->stripe_refund_id);
        $this->assertNotNull($reconciled->last_checked_at);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
    }

    public function test_stripe_lookup_failure_keeps_operation_unresolved_and_payment_unchanged(): void
    {
        [$payment, , $operation] = $this->makeProcessingRefundOperation();
        $this->mockRedisReconciliationLock((int) $operation->id, true);
        Log::shouldReceive('warning')->once();
        $this->fakeStripeRefundLookup(
            fn () => null,
            fn () => throw new RuntimeException('Simulated lookup timeout.'),
        );

        $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

        $this->assertSame(RefundOperationStatus::Processing, $reconciled->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
        $this->assertSame(0, PaymentTransaction::query()->where('refund_operation_id', $operation->id)->count());
    }

    public function test_a_busy_reconciliation_lock_does_not_query_stripe_or_mutate_local_state(): void
    {
        [$payment, , $operation] = $this->makeProcessingRefundOperation();
        $this->mockRedisReconciliationLock((int) $operation->id, false);
        $this->fakeStripeRefundLookup(
            fn () => throw new RuntimeException('Stripe must not be queried while another reconciler owns the lock.'),
            fn () => throw new RuntimeException('Stripe must not be queried while another reconciler owns the lock.'),
        );

        $reconciled = app(RefundReconciliationService::class)->reconcile($operation);

        $this->assertSame(RefundOperationStatus::Processing, $reconciled->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($payment->booking_id)->status);
    }

    public function test_transition_can_attach_new_provider_data_without_changing_an_active_status(): void
    {
        [$payment, , $operation] = $this->makeProcessingRefundOperation();
        $operation = app(RefundOperationService::class)->transition(
            $operation,
            RefundOperationStatus::Unknown,
            RefundOperationEvidence::ProviderResponse,
        );

        $updated = app(RefundOperationService::class)->transition(
            $operation,
            RefundOperationStatus::Unknown,
            RefundOperationEvidence::Reconciliation,
            're_same_status_'.$payment->id,
            providerChecked: true,
        );

        $this->assertSame(RefundOperationStatus::Unknown, $updated->status);
        $this->assertSame('re_same_status_'.$payment->id, $updated->stripe_refund_id);
        $this->assertSame('payment-refund:'.$payment->id, $updated->active_key);
    }

    private function fakeStripeRefundLookup(callable $retrieve, callable $list): void
    {
        $fake = new class($retrieve, $list) extends StripeRefundService {
            public function __construct(private $retrieveCallback, private $listCallback) {}

            public function retrieveRefund(string $refundId)
            {
                return ($this->retrieveCallback)($refundId);
            }

            public function listRefundsForPaymentIntent(string $paymentIntentId): array
            {
                return ($this->listCallback)($paymentIntentId);
            }
        };

        $this->app->instance(StripeRefundService::class, $fake);
    }

    private function mockRedisReconciliationLock(int $operationId, bool $acquired): void
    {
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturn($acquired);
        if ($acquired) {
            $lock->shouldReceive('release')->once()->andReturnTrue();
        }

        $store = \Mockery::mock();
        $store->shouldReceive('lock')
            ->once()
            ->with('refund-reconciliation:'.$operationId, 900)
            ->andReturn($lock);
        Cache::shouldReceive('store')->once()->with('redis')->andReturn($store);
    }

    private function mockBookingStateLock(int $bookingId, int $times = 1): void
    {
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->times($times)->andReturn(true);
        $lock->shouldReceive('release')->times($times)->andReturnTrue();

        Cache::shouldReceive('lock')
            ->times($times)
            ->with('booking:'.$bookingId.':state', 10)
            ->andReturn($lock);
    }

    private function makeStaffForBooking(int $bookingId): User
    {
        $booking = Booking::query()->findOrFail($bookingId);
        $token = bin2hex(random_bytes(6));
        $staffId = DB::table('users')->insertGetId([
            'name' => 'Refund Staff '.$token,
            'email' => 'refund-staff-'.$token.'@example.test',
            'password' => 'not-a-real-login',
            'role' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('spaces')->where('id', $booking->space_id)->update(['staff_id' => $staffId]);

        return User::query()->findOrFail($staffId);
    }

    private function makeProcessingRefundOperation(): array
    {
        [$payment, $customer] = $this->makePaidPayment();
        $service = app(RefundOperationService::class);
        $operation = $service->reserve($payment, RefundOperationSource::CustomerCancel, $customer, 'reconcile-'.$payment->id.'-'.bin2hex(random_bytes(4)));
        $operation = $service->transition($operation, RefundOperationStatus::Processing);

        return [$payment, $customer, $operation];
    }

    private function stripeRefundCandidate(RefundOperation $operation, Payment $payment, string $refundId, string $status): object
    {
        return (object) [
            'id' => $refundId,
            'status' => $status,
            'amount' => $operation->amount_minor,
            'currency' => strtolower($operation->currency),
            'payment_intent' => $payment->payment_intent_id,
            'metadata' => (object) ['refund_operation_id' => (string) $operation->getKey()],
        ];
    }

    private function refundWebhookPayload(string $eventId, string $eventType, object $refund): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2025-09-30.clover',
            'created' => time(),
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => null,
            'type' => $eventType,
            'data' => ['object' => (array) $refund],
        ];
    }

    private function signedRefundWebhookRequest(array $event, string $secret): Request
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return Request::create(
            '/api/v1/stripe/webhook',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
            ],
            $body,
        );
    }

    private function fakeStripeRefundService(callable $callback): void
    {
        $fake = new class($callback) extends StripeRefundService {
            private $callback;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function refund(Payment $payment, RefundOperation $operation)
            {
                $refund = ($this->callback)($payment, $operation);
                if (! is_object($refund)) {
                    return $refund;
                }

                $defaults = [
                    'payment_intent' => $payment->payment_intent_id,
                    'amount' => (int) $operation->amount_minor,
                    'currency' => strtolower((string) $operation->currency),
                    'metadata' => (object) ['refund_operation_id' => (string) $operation->id],
                ];
                foreach ($defaults as $field => $value) {
                    if (! property_exists($refund, $field)) {
                        $refund->{$field} = $value;
                    }
                }

                return $refund;
            }
        };

        $this->app->instance(StripeRefundService::class, $fake);
    }

    public function test_two_independent_mysql_connections_cannot_reserve_simultaneously(): void
    {
        if (! function_exists('proc_open')) {
            $this->fail('proc_open is unavailable; the required independent-connection concurrency test cannot run.');
        }

        [$payment, $requester] = $this->makePaidPayment();
        $paymentId = (int) $payment->id;
        $requesterId = (int) $requester->id;

        // Publish only this synthetic fixture so independent child processes can see it.
        DB::connection('mysql')->commit();
        $this->transactionStarted = false;

        $connection = DB::connection('mysql');
        $workers = [];

        try {
            $workers['holder'] = $this->startReserveWorker(
                'holder', $paymentId, $requesterId, 'race-holder-'.bin2hex(random_bytes(6)), RefundOperationSource::CustomerCancel,
            );
            $workers['contender'] = $this->startReserveWorker(
                'contender', $paymentId, $requesterId, 'race-contender-'.bin2hex(random_bytes(6)), RefundOperationSource::StaffReject,
            );

            foreach ($workers as $worker) {
                stream_set_timeout($worker['stderr'], 10);
                $ready = trim((string) fgets($worker['stderr']));
                if ($ready !== 'READY') {
                    $this->fail('Independent PHP worker failed readiness; no concurrency result can be claimed.');
                }
            }

            fwrite($workers['holder']['stdin'], "HOLD\n");
            fflush($workers['holder']['stdin']);
            $holderSignal = trim((string) fgets($workers['holder']['stderr']));
            $this->assertMatchesRegularExpression('/^HOLDING:\d+$/', $holderSignal);
            $holderConnectionId = (int) substr($holderSignal, strlen('HOLDING:'));

            fwrite($workers['contender']['stdin'], "GO\n");
            fflush($workers['contender']['stdin']);
            $contenderSignal = trim((string) fgets($workers['contender']['stderr']));
            $this->assertMatchesRegularExpression('/^ATTEMPTING:\d+$/', $contenderSignal);
            $contenderConnectionId = (int) substr($contenderSignal, strlen('ATTEMPTING:'));

            $waiterObserved = false;
            $holderState = null;
            $contenderState = null;
            $contenderStillRunning = true;
            $contenderProcessState = null;
            // Allow Windows/MySQL startup and optimizer scheduling variance while
            // still requiring an observed InnoDB LOCK WAIT before claiming success.
            $deadline = microtime(true) + 15;
            do {
                $waiter = $connection->selectOne(
                    "SELECT trx_mysql_thread_id AS connection_id FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id = ?",
                    [$contenderConnectionId],
                );
                $holderState = $connection->selectOne(
                    'SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?',
                    [$holderConnectionId],
                )?->trx_state;
                $contenderState = $connection->selectOne(
                    'SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?',
                    [$contenderConnectionId],
                )?->trx_state;
                $contenderStillRunning = proc_get_status($workers['contender']['process'])['running'];
                $contenderProcessState = $connection->selectOne(
                    'SELECT COMMAND AS command_name, STATE AS process_state FROM information_schema.PROCESSLIST WHERE ID = ?',
                    [$contenderConnectionId],
                );

                if ($waiter) {
                    $waiterObserved = true;
                    break;
                }

                usleep(50_000);
            } while (microtime(true) < $deadline);

            $this->assertTrue($waiterObserved, 'MySQL lock wait was not observed (holder='.$holderState.', contender='.$contenderState.', process='.($contenderProcessState->command_name ?? 'missing').'/'.($contenderProcessState->process_state ?? 'none').', contender_running='.($contenderStillRunning ? 'yes' : 'no').').');
            $this->assertNotSame($holderConnectionId, $contenderConnectionId);
            $this->assertSame('RUNNING', $holderState, 'The holder connection must retain its open locking transaction.');
            $this->assertSame('LOCK WAIT', $contenderState, 'The contender connection must be waiting on the MySQL lock.');

            fwrite($workers['holder']['stdin'], "RESERVE\n");
            fflush($workers['holder']['stdin']);

            $holderResult = trim((string) fgets($workers['holder']['stderr']));
            $contenderResult = trim((string) fgets($workers['contender']['stderr']));

            $this->assertStringStartsWith('CREATED:', $holderResult);
            $this->assertSame('BLOCKED', $contenderResult);
            $this->assertSame(1, RefundOperation::query()->where('payment_id', $paymentId)->whereNotNull('active_key')->count());
            $this->assertSame(1, RefundOperation::query()->where('payment_id', $paymentId)->count());
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        proc_terminate($worker['process']);
                    }
                }

                foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
                    if (isset($worker[$pipe]) && is_resource($worker[$pipe])) {
                        fclose($worker[$pipe]);
                    }
                }

                if (is_resource($worker['process'])) {
                    proc_close($worker['process']);
                }
            }

            // Remove only this test's synthetic rows, using ordinary DML in FK order.
            $connection->beginTransaction();
            $connection->table('payment_transactions')->where('payment_id', $paymentId)->delete();
            $connection->table('refund_operations')->where('payment_id', $paymentId)->delete();
            $connection->table('payments')->where('id', $paymentId)->delete();
            $bookingId = $connection->table('bookings')->where('id', $payment->booking_id)->value('id');
            $spaceId = $connection->table('bookings')->where('id', $bookingId)->value('space_id');
            $connection->table('bookings')->where('id', $bookingId)->delete();
            $connection->table('spaces')->where('id', $spaceId)->delete();
            $connection->table('users')->where('id', $requesterId)->delete();
            $connection->commit();
        }
    }

    private function startReserveWorker(string $role, int $paymentId, int $requesterId, string $requestKey, RefundOperationSource $source): array
    {
        $workerCode = <<<'PHP'
$projectRoot = __PROJECT_ROOT__;
require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($app->configurationIsCached()) { fwrite(STDERR, "CONFIG_CACHED\n"); exit(20); }
config(['database.default'=>'mysql','database.connections.mysql.driver'=>'mysql','database.connections.mysql.host'=>'127.0.0.1','database.connections.mysql.database'=>'room_booking_refund_test','database.connections.mysql.url'=>null]);
Illuminate\Support\Facades\DB::purge('mysql');
$db=Illuminate\Support\Facades\DB::connection('mysql');
if ($db->selectOne('SELECT DATABASE() AS db')->db !== 'room_booking_refund_test') { fwrite(STDERR, "DB_MISMATCH\n"); exit(21); }
fwrite(STDERR, "READY\n"); fflush(STDERR);
$role = __ROLE__;
$source = App\Enums\RefundOperationSource::from(__SOURCE__);
if ($role === 'holder') {
    fgets(STDIN);
    $db->beginTransaction();
    App\Models\Payment::query()->lockForUpdate()->findOrFail(__PAYMENT_ID__);
    fwrite(STDERR, "HOLDING:".$db->selectOne('SELECT CONNECTION_ID() AS id')->id."\n"); fflush(STDERR);
    fgets(STDIN);
} else {
    fgets(STDIN);
}
try {
    $payment=App\Models\Payment::query()->findOrFail(__PAYMENT_ID__);
    $requester=App\Models\User::query()->findOrFail(__REQUESTER_ID__);
    if ($role === 'contender') { fwrite(STDERR, "ATTEMPTING:".$db->selectOne('SELECT CONNECTION_ID() AS id')->id."\n"); fflush(STDERR); }
    $operation=(new App\Services\RefundOperationService())->reserve($payment, $source, $requester, __REQUEST_KEY__);
    if ($role === 'holder') { $db->commit(); }
    fwrite(STDERR, "CREATED:".$operation->id."\n");
} catch (DomainException $exception) {
    if ($role === 'holder' && $db->transactionLevel() > 0) { $db->rollBack(); }
    fwrite(STDERR, "BLOCKED\n");
} catch (Throwable $exception) {
    if ($role === 'holder' && $db->transactionLevel() > 0) { $db->rollBack(); }
    fwrite(STDERR, "WORKER_ERROR:".$exception::class."\n");
    exit(22);
}
PHP;
        $workerCode = str_replace(
            ['__PROJECT_ROOT__', '__ROLE__', '__PAYMENT_ID__', '__REQUESTER_ID__', '__REQUEST_KEY__', '__SOURCE__'],
            [var_export(dirname(__DIR__, 2), true), var_export($role, true), (string) $paymentId, (string) $requesterId, var_export($requestKey, true), var_export($source->value, true)],
            $workerCode,
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, '-r', $workerCode], $descriptors, $pipes, dirname(__DIR__, 2));

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start an independent PHP worker process.');
        }

        return [
            'process' => $process,
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    private function makePendingCheckout(): array
    {
        [$payment] = $this->makePaidPayment();
        $booking = Booking::query()->findOrFail($payment->booking_id);
        $payment->update([
            'provider_reference' => 'cs_checkout_'.$payment->id,
            'payment_intent_id' => null,
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
        $booking->update(['status' => BookingStatus::PendingPayment]);

        return [$payment->fresh(), $booking->fresh()];
    }

    private function checkoutCompletedEvent(Payment $payment, Booking $booking, string $eventId): object
    {
        return (object) [
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => (object) [
                'object' => (object) [
                    'id' => $payment->provider_reference,
                    'mode' => 'payment',
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_checkout_'.$payment->id,
                    'amount_total' => 1250,
                    'currency' => 'usd',
                    'metadata' => (object) [
                        'payment_id' => (string) $payment->id,
                        'booking_id' => (string) $booking->id,
                    ],
                ],
            ],
        ];
    }

    private function makePaidPayment(): array
    {
        $token = bin2hex(random_bytes(8));
        $now = now();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Refund Test '.$token,
            'email' => 'refund-'.$token.'@example.test',
            'password' => 'not-a-real-login',
            'role' => 'customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $spaceId = DB::table('spaces')->insertGetId([
            'title' => 'Refund Test Space '.$token,
            'price_per_hour' => 12.50,
            'status' => 'available',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $userId,
            'space_id' => $spaceId,
            'start_time' => $now->copy()->addDay(),
            'end_time' => $now->copy()->addDay()->addHour(),
            'total_amount' => 12.50,
            'currency' => 'USD',
            'status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $paymentId = DB::table('payments')->insertGetId([
            'booking_id' => $bookingId,
            'provider' => 'stripe',
            'payment_intent_id' => 'pi_test_'.$token,
            'status' => PaymentStatus::Paid->value,
            'amount' => 12.50,
            'currency' => 'USD',
            'paid_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [Payment::query()->findOrFail($paymentId), User::query()->findOrFail($userId)];
    }
}
