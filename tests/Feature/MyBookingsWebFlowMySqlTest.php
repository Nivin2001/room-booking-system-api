<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Space;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MyBookingsWebFlowMySqlTest extends LaravelTestCase
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
        ]);
        DB::purge('mysql');

        if (DB::connection('mysql')->getDriverName() !== 'mysql'
            || DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db !== 'room_booking_refund_test') {
            throw new RuntimeException('The active test connection is not room_booking_refund_test.');
        }

        foreach (['users', 'spaces', 'bookings', 'payments'] as $table) {
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('customer.bookings.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('customer.bookings.index'));
    }

    public function test_customer_only_sees_their_own_bookings_and_can_open_details(): void
    {
        [$customer, $booking] = $this->makeBooking();
        [, $otherBooking] = $this->makeBooking();

        $this->actingAs($customer, 'web')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee('حجوزاتي')
            ->assertSee('حجز #'.$booking->id)
            ->assertDontSee('حجز #'.$otherBooking->id)
            ->assertSee(route('customer.bookings.show', $booking->id));

        $this->actingAs($customer, 'web')
            ->get(route('spaces.bookings.confirmation', $otherBooking->id))
            ->assertNotFound();
    }

    public function test_customer_with_no_bookings_sees_empty_state(): void
    {
        $customer = $this->makeUser();

        $this->actingAs($customer, 'web')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee('لا توجد حجوزات حتى الآن')
            ->assertSee('استعراض المساحات');
    }

    public function test_booking_and_payment_statuses_are_shown_separately(): void
    {
        [$customer, $booking] = $this->makeBooking(BookingStatus::PendingPayment);
        $this->makePayment($booking, PaymentStatus::Failed);

        $this->actingAs($customer, 'web')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee('حالة الحجز:')
            ->assertSee('بانتظار الدفع')
            ->assertSee('حالة الدفع:')
            ->assertSee('فشل الدفع');
    }

    public function test_checkout_button_appears_only_for_an_eligible_owned_booking(): void
    {
        [$customer, $eligibleBooking] = $this->makeBooking(BookingStatus::PendingPayment);
        [, $paidBooking] = $this->makeBookingFor($customer, BookingStatus::PendingPayment);
        $this->makePayment($paidBooking, PaymentStatus::Paid);
        [, $confirmedBooking] = $this->makeBookingFor($customer, BookingStatus::Confirmed);
        $this->makePayment($confirmedBooking, PaymentStatus::Pending);
        [, $processingBooking] = $this->makeBookingFor($customer, BookingStatus::PendingPayment);
        $this->makePayment($processingBooking, PaymentStatus::Processing);
        [, $startedBooking] = $this->makeBookingFor($customer, BookingStatus::PendingPayment);
        $startedBooking->update(['start_time' => now()->subMinute()]);
        $this->makePayment($startedBooking, PaymentStatus::Pending);
        [, $expiredBooking] = $this->makeBookingFor($customer, BookingStatus::PendingPayment);
        $expiredBooking->update(['expires_at' => now()->subSecond()]);
        $this->makePayment($expiredBooking, PaymentStatus::Pending);

        $this->actingAs($customer, 'web')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee(route('spaces.bookings.checkout', $eligibleBooking->id))
            ->assertDontSee(route('spaces.bookings.checkout', $paidBooking->id))
            ->assertDontSee(route('spaces.bookings.checkout', $confirmedBooking->id))
            ->assertDontSee(route('spaces.bookings.checkout', $processingBooking->id))
            ->assertDontSee(route('spaces.bookings.checkout', $startedBooking->id))
            ->assertDontSee(route('spaces.bookings.checkout', $expiredBooking->id));
    }

    public function test_non_customer_role_cannot_open_my_bookings(): void
    {
        $staff = $this->makeUser('staff');

        $this->actingAs($staff, 'web')
            ->get(route('customer.bookings.index'))
            ->assertForbidden();
    }

    public function test_customer_can_cancel_unpaid_booking_but_pending_staff_approval_stays_blocked(): void
    {
        [$customer, $unpaidBooking] = $this->makeBooking(BookingStatus::PendingPayment);
        [, $approvalBooking] = $this->makeBookingFor($customer, BookingStatus::PendingStaffApproval);

        $this->actingAs($customer, 'web')
            ->get(route('customer.bookings.show', $approvalBooking))
            ->assertOk()
            ->assertDontSee(route('customer.bookings.cancel', $approvalBooking));

        $this->post(route('customer.bookings.cancel', $approvalBooking), ['idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('customer.bookings.show', $approvalBooking))
            ->assertSessionHasErrors('cancellation');
        $this->assertSame(BookingStatus::PendingStaffApproval, $approvalBooking->fresh()->status);

        $this->post(route('customer.bookings.cancel', $unpaidBooking), ['idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('customer.bookings.show', $unpaidBooking));
        $this->assertSame(BookingStatus::Cancelled, $unpaidBooking->fresh()->status);
    }

    public function test_bookings_are_paginated_ten_per_page(): void
    {
        [$customer, $oldestBooking] = $this->makeBooking();
        $bookings = [];
        for ($index = 0; $index < 11; $index++) {
            [, $bookings[]] = $this->makeBookingFor($customer);
        }

        $firstPage = $this->actingAs($customer, 'web')
            ->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee('page=2');

        $this->assertSame(10, $firstPage->viewData('bookings')->count());

        $secondPage = $this->get(route('customer.bookings.index', ['page' => 2]))
            ->assertOk();
        $this->assertSame(2, $secondPage->viewData('bookings')->count());
        $this->assertTrue($secondPage->viewData('bookings')->getCollection()->contains('id', $oldestBooking->id));
    }

    private function makeBooking(BookingStatus $status = BookingStatus::PendingPayment): array
    {
        return $this->makeBookingFor($this->makeUser(), $status);
    }

    private function makeBookingFor(User $customer, BookingStatus $status = BookingStatus::PendingPayment): array
    {
        $token = bin2hex(random_bytes(6));
        $space = Space::query()->create([
            'title' => 'My bookings test space '.$token,
            'price_per_hour' => 32,
            'status' => 'available',
            'created_by' => $customer->id,
        ]);
        $booking = Booking::query()->create([
            'user_id' => $customer->id,
            'space_id' => $space->id,
            'start_time' => now()->addDays(4),
            'end_time' => now()->addDays(4)->addHour(),
            'total_amount' => 32,
            'currency' => 'USD',
            'status' => $status,
        ]);

        return [$customer, $booking];
    }

    private function makeUser(string $role = 'customer'): User
    {
        $token = bin2hex(random_bytes(8));

        return User::query()->create([
            'name' => 'My bookings test customer '.$token,
            'email' => 'my-bookings-'.$token.'@example.test',
            'password' => Hash::make('VerySecure123!'),
            'role' => $role,
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
