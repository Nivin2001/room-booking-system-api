<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\CheckoutAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\CheckoutAttempt;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Space;
use App\Models\User;
use App\Services\stripe\StripeWebhookService;
use App\Services\stripe\StripeCheckoutService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class StripeCheckoutWebFlowMySqlTest extends LaravelTestCase
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
            throw new RuntimeException('Config cache prevents verifying the isolated test database.');
        }

        $mysql = config('database.connections.mysql');
        if (! is_array($mysql) || empty($mysql['port'])) {
            throw new RuntimeException('Configured MySQL port is unavailable.');
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => 'room_booking_refund_test',
            'database.connections.mysql.url' => null,
            'session.driver' => 'array',
            'cache.default' => 'array',
            'services.stripe.secret' => 'sk_test_checkout_fake',
        ]);
        DB::purge('mysql');

        if (DB::connection('mysql')->getDriverName() !== 'mysql'
            || DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db !== 'room_booking_refund_test') {
            throw new RuntimeException('The active test connection is not room_booking_refund_test.');
        }

        foreach (['users', 'spaces', 'bookings', 'payments', 'payment_transactions', 'checkout_attempts', 'webhook_logs'] as $table) {
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

    public function test_customer_can_start_checkout_for_owned_pending_booking_using_backend_amount(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['amount' => '0.01', 'currency' => 'xxx', 'checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');

        $payment = Payment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('48.00', number_format((float) $payment->amount, 2, '.', ''));
        $this->assertSame('usd', strtolower($payment->currency));
        $this->assertSame('stripe', $payment->provider);
        $this->assertSame('cs_test_checkout_1', $payment->provider_reference);
        $attempt = CheckoutAttempt::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(CheckoutAttemptStatus::Open, $attempt->status);
        $this->assertNotSame('booking-'.$booking->id.'-payment-'.$payment->id, $attempt->stripe_idempotency_key);
        $this->assertSame(1, $fake->created);
        $this->assertStringContainsString('{CHECKOUT_SESSION_ID}', $fake->successUrl);
        $this->assertStringContainsString('/payment-cancelled', $fake->cancelUrl);
        $this->assertSame(0, DB::table('payment_transactions')->where('payment_id', $payment->id)->count());
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
    }

    public function test_existing_checkout_session_is_reused_instead_of_creating_another(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');
        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');

        $this->assertSame(1, $fake->created);
        $this->assertSame(1, $fake->retrieved);
        $this->assertSame(1, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, CheckoutAttempt::query()->where('payment_id', Payment::query()->where('booking_id', $booking->id)->value('id'))->count());
    }

    public function test_request_key_reused_for_an_active_attempt_stays_bound_after_expiry(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $initialKey = $this->checkoutRequestKey();
        $retryKey = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $initialKey])
            ->assertRedirect();
        $attempt = $this->attemptsForBooking($booking)->firstOrFail();

        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $retryKey])
            ->assertRedirect();
        $this->assertContains(hash('sha256', $retryKey), $attempt->fresh()->request_key_hashes);

        $fake->retrievedSession = clone $fake->createdSessions[0];
        $fake->retrievedSession->status = 'expired';
        $fake->retrievedSession->url = null;

        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $retryKey])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id));

        $this->assertSame(1, $fake->created);
        $this->assertSame(1, CheckoutAttempt::query()->where('payment_id', $attempt->payment_id)->count());
        $this->assertSame(CheckoutAttemptStatus::Expired, $attempt->fresh()->status);
    }

    public function test_customer_cannot_start_checkout_for_another_users_booking(): void
    {
        [$owner, $booking] = $this->makeBooking();
        $otherCustomer = $this->makeUser();
        $fake = $this->bindFakeCheckout();

        $this->actingAs($otherCustomer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertNotFound();

        $this->assertSame(0, $fake->created);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
    }

    public function test_paid_payment_is_not_payable_even_if_booking_still_says_pending(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Paid);
        $fake = $this->bindFakeCheckout();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, $fake->created);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
    }

    public function test_demo_payment_is_not_converted_or_reused_for_stripe_checkout(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Pending);
        $payment->update(['provider' => 'demo', 'provider_reference' => 'demo-session-1']);
        $fake = $this->bindFakeCheckout();

        $this->assertFalse(app(StripeCheckoutService::class)->canStartCheckout($booking->fresh()));

        try {
            app(StripeCheckoutService::class)->createCheckoutSession($booking->fresh(), $this->checkoutRequestKey());
            $this->fail('A demo payment must not be reused for Stripe Checkout.');
        } catch (\DomainException $exception) {
            $this->assertSame('Payment belongs to a different provider.', $exception->getMessage());
        }

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame('demo', $payment->fresh()->provider);
        $this->assertSame('demo-session-1', $payment->fresh()->provider_reference);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, $fake->created);
        $this->assertSame(0, $fake->retrieved);
    }

    public function test_checkout_webhook_rejects_demo_provider_payment(): void
    {
        [, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Pending);
        $payment->update([
            'provider' => 'demo',
            'provider_reference' => 'cs_demo_session',
        ]);
        $event = (object) [
            'id' => 'evt_demo_provider_'.$payment->id,
            'type' => 'checkout.session.completed',
            'data' => (object) ['object' => (object) [
                'id' => 'cs_demo_session',
                'mode' => 'payment',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_demo_'.$payment->id,
                'amount_total' => 4800,
                'currency' => 'usd',
                'metadata' => (object) [
                    'payment_id' => (string) $payment->id,
                    'booking_id' => (string) $booking->id,
                ],
            ]],
        ];

        $response = app(StripeWebhookService::class)->processEvent($event, []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('demo', $payment->fresh()->provider);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
        $this->assertSame(0, DB::table('payment_transactions')->where('payment_id', $payment->id)->count());
        $this->assertNull(DB::table('webhook_logs')->where('event_id', $event->id)->value('processed_at'));
    }

    public function test_non_pending_booking_cannot_start_checkout(): void
    {
        [$customer, $booking] = $this->makeBooking(BookingStatus::Confirmed);
        $this->makePayment($booking, PaymentStatus::Pending);
        $fake = $this->bindFakeCheckout();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, $fake->created);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_checkout_is_rejected_for_a_booking_whose_start_time_has_passed(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $booking->update(['start_time' => now()->subMinute()]);
        $fake = $this->bindFakeCheckout();

        $this->assertFalse(app(StripeCheckoutService::class)->canStartCheckout($booking->fresh()));
        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, $fake->created);
        $this->assertSame(0, $fake->retrieved);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, $this->attemptsForBooking($booking)->count());
    }

    public function test_checkout_is_rejected_after_booking_expiration_without_creating_a_payment_or_attempt(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $booking->update(['expires_at' => now()->subSecond()]);
        $fake = $this->bindFakeCheckout();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame(0, $fake->created);
        $this->assertSame(0, $fake->retrieved);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, $this->attemptsForBooking($booking)->count());
    }

    public function test_ineligible_booking_does_not_reuse_an_existing_open_checkout_attempt(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');
        $attempt = $this->attemptsForBooking($booking)->firstOrFail();
        $this->assertSame(CheckoutAttemptStatus::Open, $attempt->status);

        $booking->update(['start_time' => now()->subMinute()]);
        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame(1, $fake->created);
        $this->assertSame(0, $fake->retrieved);
        $this->assertSame(CheckoutAttemptStatus::Open, $attempt->fresh()->status);
        $this->assertSame('checkout-payment-'.$attempt->payment_id, $attempt->fresh()->active_key);
        $this->assertSame(1, $this->attemptsForBooking($booking)->count());
    }

    public function test_checkout_url_is_withheld_if_booking_becomes_ineligible_while_session_is_retrieved(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');
        $attempt = $this->attemptsForBooking($booking)->firstOrFail();
        $fake->bookingToMakeIneligibleDuringRetrieve = $booking;

        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id));

        $this->assertSame(1, $fake->created);
        $this->assertSame(1, $fake->retrieved);
        $this->assertSame(CheckoutAttemptStatus::Open, $attempt->fresh()->status);
        $this->assertTrue($booking->fresh()->start_time->isPast());
    }

    public function test_checkout_attempt_with_expired_local_session_snapshot_is_not_rendered_or_reused(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');
        $attempt = $this->attemptsForBooking($booking)->firstOrFail();
        $attempt->update(['expires_at' => now()->subSecond()]);

        $this->get(route('customer.bookings.show', $booking->id))
            ->assertOk()
            ->assertDontSee(route('spaces.bookings.checkout', $booking->id));
        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHasErrors('checkout');

        $this->assertSame(1, $fake->created);
        $this->assertSame(0, $fake->retrieved);
        $this->assertSame(CheckoutAttemptStatus::Open, $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->active_key);
    }

    public function test_open_session_past_its_provider_expiration_snapshot_is_not_usable(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');
        $attempt = $this->attemptsForBooking($booking)->firstOrFail();
        $fake->retrievedSession = clone $fake->createdSessions[0];
        $fake->retrievedSession->expires_at = now()->subSecond()->timestamp;

        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id));

        $this->assertSame(1, $fake->created);
        $this->assertSame(1, $fake->retrieved);
        $this->assertSame(CheckoutAttemptStatus::Unknown, $attempt->fresh()->status);
        $this->assertSame('checkout-payment-'.$attempt->payment_id, $attempt->fresh()->active_key);
    }

    public function test_checkout_creation_failure_is_reported_without_leaking_provider_error(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $fake->shouldFail = true;

        $this->actingAs($customer, 'web')
            ->from(route('spaces.bookings.confirmation', $booking->id))
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id))
            ->assertSessionHas('payment_notice');

        $payment = Payment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertNull($payment->provider_reference);
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
        $attempt = CheckoutAttempt::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(CheckoutAttemptStatus::Unknown, $attempt->status);
    }

    public function test_return_from_stripe_does_not_confirm_payment_without_webhook(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Pending);

        $this->actingAs($customer, 'web')
            ->get(route('spaces.bookings.payment-success', $booking->id).'?session_id=cs_unverified')
            ->assertOk()
            ->assertSee('لا يُعد ذلك تأكيدًا للدفع');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
    }

    public function test_cancel_return_does_not_change_booking_or_payment_state(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Pending);

        $this->actingAs($customer, 'web')
            ->get(route('spaces.bookings.payment-cancelled', $booking->id))
            ->assertRedirect(route('spaces.bookings.confirmation', $booking->id));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
    }

    public function test_expired_unpaid_session_creates_a_new_attempt_with_a_new_stripe_key(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $firstKey = $this->checkoutRequestKey();
        $secondKey = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $firstKey])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');

        $firstAttempt = $this->attemptsForBooking($booking)->firstOrFail();
        $fake->retrievedSession = $fake->createdSessions[0];
        $fake->retrievedSession->status = 'expired';
        $fake->retrievedSession->url = null;

        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $secondKey])
            ->assertRedirect('https://checkout.stripe.test/session-test-2');

        $attempts = $this->attemptsForBooking($booking)->orderBy('id')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(CheckoutAttemptStatus::Expired, $attempts[0]->status);
        $this->assertSame(CheckoutAttemptStatus::Open, $attempts[1]->status);
        $this->assertNotSame($firstAttempt->stripe_idempotency_key, $attempts[1]->stripe_idempotency_key);
        $this->assertNotSame($fake->idempotencyKeys[0], $fake->idempotencyKeys[1]);
        $this->assertSame(2, $fake->created);
        $this->assertSame(1, $fake->retrieved);
        $this->assertSame(PaymentStatus::Pending, Payment::query()->where('booking_id', $booking->id)->value('status'));
        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
    }

    public function test_completed_paid_or_unpaid_session_is_not_replaced(): void
    {
        $fake = $this->bindFakeCheckout();
        $expectedCreations = 0;
        foreach ([['paid', 'pi_complete_paid'], ['unpaid', null]] as [$stripePaymentStatus, $intent]) {
            [$customer, $booking] = $this->makeBooking();
            $fake->retrievedSession = null;
            $key = $this->checkoutRequestKey();

            $this->actingAs($customer, 'web')
                ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
                ->assertRedirect();

            $fake->retrievedSession = clone $fake->createdSessions[array_key_last($fake->createdSessions)];
            $fake->retrievedSession->status = 'complete';
            $fake->retrievedSession->payment_status = $stripePaymentStatus;
            $fake->retrievedSession->payment_intent = $intent;

            $response = $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()]);
            $response->assertRedirect(route('spaces.bookings.confirmation', $booking->id));
            $this->assertSame(++$expectedCreations, $fake->created);
            $this->assertSame(CheckoutAttemptStatus::Complete, CheckoutAttempt::query()->where('payment_id', Payment::query()->where('booking_id', $booking->id)->value('id'))->value('status'));
            $this->assertNotSame(PaymentStatus::Paid, Payment::query()->where('booking_id', $booking->id)->value('status'));
            $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
        }
    }

    public function test_same_request_key_cannot_be_reused_after_payment_data_changes(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $key = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $key])
            ->assertRedirect();

        $booking->update(['total_amount' => 49.00]);
        Payment::query()->where('booking_id', $booking->id)->update(['amount' => 49.00]);
        $this->expectException(\DomainException::class);
        app(StripeCheckoutService::class)->createCheckoutSession($booking->fresh(), $key);
    }

    public function test_legacy_expired_session_is_recorded_then_replaced_only_after_confirmation(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Pending);
        $payment->update(['provider_reference' => 'cs_test_legacy_expired']);
        $fake = $this->bindFakeCheckout();
        $fake->retrievedSession = (object) [
            'id' => 'cs_test_legacy_expired',
            'url' => null,
            'status' => 'expired',
            'payment_status' => 'unpaid',
            'mode' => 'payment',
            'livemode' => false,
            'amount_total' => 4800,
            'currency' => 'usd',
            'payment_intent' => null,
            'expires_at' => now()->subHour()->timestamp,
            'metadata' => (object) ['booking_id' => (string) $booking->id, 'payment_id' => (string) $payment->id],
        ];

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect('https://checkout.stripe.test/session-test-1');

        $attempts = CheckoutAttempt::query()->where('payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(2, $attempts);
        $this->assertTrue($attempts[0]->is_legacy);
        $this->assertSame(CheckoutAttemptStatus::Expired, $attempts[0]->status);
        $this->assertSame(CheckoutAttemptStatus::Open, $attempts[1]->status);
        $this->assertSame('cs_test_checkout_1', $payment->fresh()->provider_reference);
        $this->assertSame(1, $fake->created);
        $this->assertSame(1, $fake->retrieved);
    }

    public function test_unmatched_or_unresolved_session_stays_unknown_and_holds_payment_slot(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $initialKey = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $initialKey])
            ->assertRedirect();

        $fake->retrievedSession = clone $fake->createdSessions[0];
        $fake->retrievedSession->metadata->booking_id = '999999';
        $response = $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()]);
        $response->assertRedirect(route('spaces.bookings.confirmation', $booking->id));

        $attempt = CheckoutAttempt::query()->where('payment_id', Payment::query()->where('booking_id', $booking->id)->value('id'))->firstOrFail();
        $this->assertSame(CheckoutAttemptStatus::Unknown, $attempt->status);
        $this->assertNotNull($attempt->active_key);
        $this->assertSame(1, $fake->created);
        $this->assertSame(PaymentStatus::Processing, Payment::query()->where('booking_id', $booking->id)->value('status'));
    }

    public function test_api_checkout_requires_a_stable_idempotency_key(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/customer/payments/checkout', ['booking_id' => $booking->id])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(0, $fake->created);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
    }

    public function test_api_checkout_uses_the_provided_idempotency_key(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();

        $this->actingAs($customer, 'sanctum')
            ->withHeader('Idempotency-Key', $this->checkoutRequestKey())
            ->postJson('/api/v1/customer/payments/checkout', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'https://checkout.stripe.test/session-test-1');

        $this->assertSame(1, $fake->created);
        $this->assertSame(1, CheckoutAttempt::query()->where('payment_id', Payment::query()->where('booking_id', $booking->id)->value('id'))->count());
    }

    public function test_two_independent_mysql_connections_share_one_concurrent_checkout_attempt(): void
    {
        if (! function_exists('proc_open')) {
            $this->fail('proc_open is unavailable; concurrent Checkout creation cannot be verified.');
        }

        [$customer, $booking] = $this->makeBooking();
        $payment = $this->makePayment($booking, PaymentStatus::Pending);
        $bookingId = (int) $booking->id;
        $paymentId = (int) $payment->id;
        $customerId = (int) $customer->id;
        $requestKey = $this->checkoutRequestKey();

        DB::connection('mysql')->commit();
        $this->transactionStarted = false;

        $workers = [];
        $connection = DB::connection('mysql');
        try {
            $workers[] = $this->startCheckoutWorker($bookingId, $requestKey);
            $workers[] = $this->startCheckoutWorker($bookingId, $requestKey);

            foreach ($workers as $worker) {
                stream_set_timeout($worker['stderr'], 15);
                $this->assertSame('READY', trim((string) fgets($worker['stderr'])));
            }
            foreach ($workers as $worker) {
                fwrite($worker['stdin'], "GO\n");
                fflush($worker['stdin']);
            }

            $results = [];
            foreach ($workers as $worker) {
                stream_set_timeout($worker['stdout'], 20);
                $output = stream_get_contents($worker['stdout']);
                $error = stream_get_contents($worker['stderr']);
                $exit = proc_close($worker['process']);
                $this->assertSame(0, $exit, 'Worker failed: '.$error);
                preg_match('/RESULT\|([^|]+)\|([^|]+)\|([^\r\n]+)/', $output, $matches);
                $this->assertCount(4, $matches, 'Worker did not return a complete checkout result.');
                $results[] = [$matches[1], $matches[2], $matches[3]];
            }

            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, $connection->table('checkout_attempts')->where('payment_id', $paymentId)->count());
            $this->assertSame(1, $connection->table('checkout_attempts')->where('payment_id', $paymentId)->whereNotNull('active_key')->count());
        } finally {
            foreach ($workers as $worker) {
                foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
                    if (isset($worker[$pipe]) && is_resource($worker[$pipe])) {
                        fclose($worker[$pipe]);
                    }
                }
                if (isset($worker['process']) && is_resource($worker['process'])) {
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        proc_terminate($worker['process']);
                    }
                }
            }

            $connection->beginTransaction();
            $connection->table('payment_transactions')->where('payment_id', $paymentId)->delete();
            $connection->table('checkout_attempts')->where('payment_id', $paymentId)->delete();
            $connection->table('payments')->where('id', $paymentId)->delete();
            $spaceId = $connection->table('bookings')->where('id', $bookingId)->value('space_id');
            $connection->table('bookings')->where('id', $bookingId)->delete();
            $connection->table('spaces')->where('id', $spaceId)->delete();
            $connection->table('users')->where('id', $customerId)->delete();
            $connection->commit();
        }
    }

    public function test_webhook_from_a_previous_attempt_is_matched_even_when_payment_reference_is_newer(): void
    {
        [$customer, $booking] = $this->makeBooking();
        $fake = $this->bindFakeCheckout();
        $firstKey = $this->checkoutRequestKey();

        $this->actingAs($customer, 'web')
            ->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $firstKey])
            ->assertRedirect();
        $payment = Payment::query()->where('booking_id', $booking->id)->firstOrFail();
        $oldAttempt = CheckoutAttempt::query()->where('payment_id', $payment->id)->firstOrFail();
        $oldSession = clone $fake->createdSessions[0];
        $oldSession->status = 'expired';
        $oldSession->url = null;
        $fake->retrievedSession = $oldSession;

        $this->post(route('spaces.bookings.checkout', $booking->id), ['checkout_request_key' => $this->checkoutRequestKey()])
            ->assertRedirect();
        $newSessionId = $payment->fresh()->provider_reference;
        $this->assertNotSame($oldSession->id, $newSessionId);

        $oldSession->status = 'complete';
        $oldSession->payment_status = 'paid';
        $oldSession->payment_intent = 'pi_old_attempt_paid';
        $event = (object) [
            'id' => 'evt_previous_checkout_attempt_'.$payment->id,
            'type' => 'checkout.session.completed',
            'data' => (object) ['object' => $oldSession],
        ];

        $response = app(StripeWebhookService::class)->processEvent($event, []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame('pi_old_attempt_paid', $payment->fresh()->payment_intent_id);
        $this->assertSame($oldSession->id, $payment->fresh()->provider_reference);
        $this->assertSame(BookingStatus::PendingStaffApproval, $booking->fresh()->status);
        $this->assertSame(CheckoutAttemptStatus::Succeeded, $oldAttempt->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('stripe_event_id', $event->id)->count());
    }

    private function checkoutRequestKey(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    private function startCheckoutWorker(int $bookingId, string $requestKey): array
    {
        $workerCode = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if($app->configurationIsCached()){fwrite(STDERR,"CONFIG_CACHED\n");exit(20);}
config(['database.default'=>'mysql','database.connections.mysql.driver'=>'mysql','database.connections.mysql.host'=>'127.0.0.1','database.connections.mysql.database'=>'room_booking_refund_test','database.connections.mysql.url'=>null,'cache.default'=>'array','session.driver'=>'array','services.stripe.secret'=>'sk_test_checkout_fake']);
Illuminate\Support\Facades\DB::purge('mysql');
$db=Illuminate\Support\Facades\DB::connection('mysql');
if($db->selectOne('SELECT DATABASE() AS db')->db!=='room_booking_refund_test'){fwrite(STDERR,"DB_MISMATCH\n");exit(21);}
fwrite(STDERR,"READY\n");fflush(STDERR);fgets(STDIN);
class CheckoutWorkerFake extends App\Services\stripe\StripeCheckoutService {
    protected function createSession(App\Models\Booking $booking, App\Models\Payment $payment, App\Models\CheckoutAttempt $attempt, ?string $successUrl=null, ?string $cancelUrl=null): object {
        usleep(500000);
        return (object)['id'=>'cs_concurrency_'.$attempt->uuid,'url'=>'https://checkout.stripe.test/'.$attempt->uuid,'status'=>'open','payment_status'=>'unpaid','mode'=>'payment','livemode'=>false,'amount_total'=>(int)((float)$attempt->amount*100),'currency'=>strtolower($attempt->currency),'payment_intent'=>null,'expires_at'=>time()+1800,'metadata'=>(object)['booking_id'=>(string)$booking->id,'payment_id'=>(string)$payment->id,'checkout_attempt_id'=>$attempt->uuid]];
    }
    protected function retrieveSession(string $sessionId): object {
        $attempt=App\Models\CheckoutAttempt::query()->where('stripe_session_id',$sessionId)->firstOrFail();
        $payment=$attempt->payment;
        return (object)['id'=>$sessionId,'url'=>'https://checkout.stripe.test/'.$attempt->uuid,'status'=>'open','payment_status'=>'unpaid','mode'=>'payment','livemode'=>false,'amount_total'=>(int)((float)$attempt->amount*100),'currency'=>strtolower($attempt->currency),'payment_intent'=>null,'expires_at'=>time()+1800,'metadata'=>(object)['booking_id'=>(string)$payment->booking_id,'payment_id'=>(string)$payment->id,'checkout_attempt_id'=>$attempt->uuid]];
    }
}
try{$booking=App\Models\Booking::query()->findOrFail(__BOOKING_ID__);$result=(new CheckoutWorkerFake)->createCheckoutSession($booking,__REQUEST_KEY__);fwrite(STDOUT,'RESULT|'.$result['attempt']->stripe_idempotency_key.'|'.$result['attempt']->stripe_session_id.'|'.$result['status']."\n");}catch(Throwable $e){fwrite(STDERR,'WORKER_ERROR|'.$e::class."\n");exit(22);}
PHP;
        $workerCode = str_replace(
            ['__BOOKING_ID__', '__REQUEST_KEY__'],
            [(string) $bookingId, var_export($requestKey, true)],
            $workerCode,
        );
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([PHP_BINARY, '-r', $workerCode], $descriptors, $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start an independent checkout worker.');
        }

        return ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function bindFakeCheckout(): FakeWebStripeCheckoutService
    {
        $service = new FakeWebStripeCheckoutService;
        $this->app->instance(StripeCheckoutService::class, $service);

        return $service;
    }

    private function attemptsForBooking(Booking $booking)
    {
        return CheckoutAttempt::query()->whereHas(
            'payment',
            fn ($payments) => $payments->where('booking_id', $booking->id),
        );
    }

    private function makeBooking(BookingStatus $status = BookingStatus::PendingPayment): array
    {
        $user = $this->makeUser();
        $space = Space::query()->create([
            'title' => 'Checkout test space '.bin2hex(random_bytes(6)),
            'price_per_hour' => 48,
            'status' => 'available',
            'created_by' => $user->id,
        ]);
        $booking = Booking::query()->create([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'start_time' => now()->addDays(3),
            'end_time' => now()->addDays(3)->addHour(),
            'total_amount' => 48,
            'currency' => 'USD',
            'status' => $status,
        ]);

        return [$user, $booking];
    }

    private function makeUser(): User
    {
        $token = bin2hex(random_bytes(8));

        return User::query()->create([
            'name' => 'Checkout test customer '.$token,
            'email' => 'checkout-'.$token.'@example.test',
            'password' => Hash::make('VerySecure123!'),
            'role' => 'customer',
        ]);
    }

    private function makePayment(Booking $booking, PaymentStatus $status): Payment
    {
        return Payment::query()->create([
            'booking_id' => $booking->id,
            'provider' => 'stripe',
            'status' => $status,
            'amount' => $booking->total_amount,
            'currency' => 'usd',
        ]);
    }
}

class FakeWebStripeCheckoutService extends StripeCheckoutService
{
    public int $created = 0;
    public int $retrieved = 0;
    public bool $shouldFail = false;
    public ?string $successUrl = null;
    public ?string $cancelUrl = null;
    public array $createdSessions = [];
    public ?object $retrievedSession = null;
    public ?Booking $bookingToMakeIneligibleDuringRetrieve = null;
    public array $idempotencyKeys = [];
    public array $expiredSessions = [];

    protected function createSession(
        Booking $booking,
        Payment $payment,
        CheckoutAttempt $attempt,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
    ): object {
        $this->created++;
        $this->successUrl = $successUrl;
        $this->cancelUrl = $cancelUrl;
        $this->idempotencyKeys[] = $attempt->stripe_idempotency_key;

        if ($this->shouldFail) {
            throw new RuntimeException('Fake provider failure detail must not reach the browser.');
        }

        $session = (object) [
            'id' => 'cs_test_checkout_'.$this->created,
            'url' => 'https://checkout.stripe.test/session-test-'.$this->created,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'mode' => 'payment',
            'livemode' => false,
            'amount_total' => (int) ((float) $attempt->amount * 100),
            'currency' => strtolower($attempt->currency),
            'payment_intent' => null,
            'expires_at' => now()->addMinutes(30)->timestamp,
            'metadata' => (object) [
                'booking_id' => (string) $booking->id,
                'payment_id' => (string) $payment->id,
                'checkout_attempt_id' => $attempt->uuid,
            ],
        ];
        $this->createdSessions[] = $session;

        return $session;
    }

    protected function retrieveSession(string $sessionId): object
    {
        $this->retrieved++;

        if ($this->bookingToMakeIneligibleDuringRetrieve) {
            $this->bookingToMakeIneligibleDuringRetrieve->update(['start_time' => now()->subMinute()]);
            $this->bookingToMakeIneligibleDuringRetrieve = null;
        }

        if ($this->retrievedSession) {
            return $this->retrievedSession;
        }

        foreach ($this->createdSessions as $session) {
            if ($session->id === $sessionId) {
                return $session;
            }
        }

        throw new RuntimeException('Fake Checkout session not found.');
    }

    public function expireCheckoutSession(string $sessionId): object
    {
        $this->expiredSessions[] = $sessionId;
        return (object) ['id' => $sessionId, 'status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null];
    }
}
