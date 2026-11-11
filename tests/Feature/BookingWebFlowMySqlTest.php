<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class BookingWebFlowMySqlTest extends LaravelTestCase
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
        ]);
        DB::purge('mysql');

        if (DB::connection('mysql')->getDriverName() !== 'mysql'
            || DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db !== 'room_booking_refund_test') {
            throw new RuntimeException('The active test connection is not room_booking_refund_test.');
        }

        foreach (['users', 'spaces', 'bookings'] as $table) {
            if (! Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException("Required test table is missing: {$table}.");
            }
        }

        DB::connection('mysql')->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->transactionStarted && DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }

        DB::disconnect('mysql');
        parent::tearDown();
    }

    public function test_customer_can_create_booking_and_see_its_pending_payment_result(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        $date = now()->addDays(3)->format('Y-m-d');

        $response = $this->actingAs($customer)->post(route('spaces.bookings.store', $space->id), [
            'date' => $date,
            'slot' => '08:00',
        ]);

        $booking = Booking::query()->where('user_id', $customer->id)->firstOrFail();
        $response->assertRedirect(route('spaces.bookings.confirmation', $booking));
        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
        $this->assertSame('08:00', $booking->start_time->format('H:i'));

        $this->get(route('spaces.bookings.confirmation', $booking))
            ->assertOk()
            ->assertSee('#'.$booking->id)
            ->assertSee('بانتظار الدفع')
            ->assertSee('التأكيد يتم بعد Webhook موثوق');
    }

    public function test_unavailable_or_conflicting_slot_is_rejected_without_creating_booking(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        $date = now()->addDays(4)->format('Y-m-d');
        $start = $date.' 08:00:00';

        Booking::query()->create([
            'user_id' => $customer->id,
            'space_id' => $space->id,
            'start_time' => $start,
            'end_time' => $date.' 09:00:00',
            'total_amount' => 20,
            'currency' => 'USD',
            'status' => BookingStatus::Confirmed,
        ]);

        $before = Booking::query()->count();
        $this->actingAs($customer)
            ->from(route('spaces.bookings.create', ['space' => $space->id, 'date' => $date]))
            ->post(route('spaces.bookings.store', $space->id), ['date' => $date, 'slot' => '08:00'])
            ->assertRedirect(route('spaces.bookings.create', ['space' => $space->id, 'date' => $date]))
            ->assertSessionHasErrors('slot');

        $this->assertSame($before, Booking::query()->count());
    }

    public function test_guest_is_redirected_to_web_login_before_booking(): void
    {
        [, $space] = $this->makeCustomerAndSpace();
        $before = Booking::query()->count();

        $this->post(route('spaces.bookings.store', $space->id), [
            'date' => now()->addDays(5)->format('Y-m-d'),
            'slot' => '08:00',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('url.intended');

        $this->assertSame($before, Booking::query()->count());
    }

    public function test_non_customer_cannot_open_booking_form(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        $staff = User::query()->create([
            'name' => 'Booking test staff',
            'email' => 'booking-staff-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'not-a-real-login',
            'role' => 'staff',
        ]);

        $this->actingAs($staff)->get(route('spaces.bookings.create', $space->id))->assertForbidden();
    }

    public function test_past_same_day_slots_are_hidden_consistently_from_web_and_customer_apis(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-11-01 21:30:00', $timezone));

        $webResponse = $this->actingAs($customer, 'web')->get(route('spaces.bookings.create', [
            'space' => $space->id,
            'date' => '2026-11-01',
        ]));
        $webResponse->assertOk();
        $webTimes = collect($webResponse->viewData('slots'))->pluck('time')->all();
        $this->assertSame(['22:00'], $webTimes);

        Sanctum::actingAs($customer);
        $apiResponse = $this->getJson('/api/v1/customer/spaces/'.$space->id.'/slots?date=2026-11-01')
            ->assertOk();
        $apiTimes = collect($apiResponse->json('slots'))->pluck('time')->all();
        $this->assertSame($webTimes, $apiTimes);

        $calendarResponse = $this->getJson('/api/v1/customer/spaces/'.$space->id.'/availability?date=2026-11-01')
            ->assertOk();
        $this->assertSame([
            ['start' => '2026-11-01 22:00:00', 'end' => '2026-11-01 23:00:00'],
        ], $calendarResponse->json('data.available_slots'));

        $before = Booking::query()->count();
        $this->actingAs($customer, 'web')
            ->from(route('spaces.bookings.create', ['space' => $space->id, 'date' => '2026-11-01']))
            ->post(route('spaces.bookings.store', $space->id), [
                'date' => '2026-11-01',
                'slot' => '08:00',
            ])
            ->assertSessionHasErrors('slot');

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/customer/bookings', [
            'space_id' => $space->id,
            'date' => '2026-11-01',
            'slot' => '08:00',
        ])->assertUnprocessable();

        $this->assertSame($before, Booking::query()->count());
    }

    public function test_booking_service_rejects_past_and_out_of_hours_ranges(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        $bookingCount = Booking::query()->where('user_id', $customer->id)->where('space_id', $space->id)->count();
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', config('app.timezone')));

        try {
            app(BookingService::class)->createBooking($customer, [
                'space_id' => $space->id,
                'start_time' => '2026-11-01 11:00:00',
                'end_time' => '2026-11-01 12:00:00',
            ]);
            $this->fail('A past booking range must be rejected by the service.');
        } catch (\DomainException $exception) {
            $this->assertSame('Booking start time must be in the future.', $exception->getMessage());
        }

        try {
            app(BookingService::class)->createBooking($customer, [
                'space_id' => $space->id,
                'start_time' => '2026-11-02 07:00:00',
                'end_time' => '2026-11-02 08:00:00',
            ]);
            $this->fail('A range outside operating hours must be rejected by the service.');
        } catch (\DomainException $exception) {
            $this->assertSame('Booking time must be within the space operating hours.', $exception->getMessage());
        }

        $this->assertSame($bookingCount, Booking::query()->where('user_id', $customer->id)->where('space_id', $space->id)->count());
    }

    public function test_booking_service_rejects_an_end_time_that_is_not_after_the_start(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', config('app.timezone')));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Booking end time must be after its start time.');

        app(BookingService::class)->createBooking($customer, [
            'space_id' => $space->id,
            'start_time' => '2026-11-02 10:00:00',
            'end_time' => '2026-11-02 10:00:00',
        ]);
    }

    public function test_a_start_equal_to_now_is_rejected_but_no_extra_lead_time_is_added(): void
    {
        [, $space] = $this->makeCustomerAndSpace();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', $timezone));
        $availability = app(\App\Services\AvailabilityService::class);

        try {
            $availability->validateTimeRange(
                $space,
                '2026-11-01 12:00:00',
                '2026-11-01 13:00:00',
            );
            $this->fail('A booking cannot start at the exact current time.');
        } catch (\DomainException $exception) {
            $this->assertSame('Booking start time must be in the future.', $exception->getMessage());
        }

        [$nearFutureStart, $nearFutureEnd] = $availability->validateTimeRange(
            $space,
            '2026-11-01 12:01:00',
            '2026-11-01 13:01:00',
        );

        $this->assertSame('12:01', $nearFutureStart->format('H:i'));
        $this->assertSame('13:01', $nearFutureEnd->format('H:i'));
    }

    public function test_web_and_api_accept_the_same_future_slots_in_the_app_timezone(): void
    {
        [$customer, $space] = $this->makeCustomerAndSpace();
        $timezone = config('app.timezone');
        Carbon::setTestNow(Carbon::parse('2026-11-01 21:30:00', $timezone));

        $webResponse = $this->actingAs($customer, 'web')->get(route('spaces.bookings.create', [
            'space' => $space->id,
            'date' => '2026-11-02',
        ]));
        $webResponse->assertOk();
        $webSlots = collect($webResponse->viewData('slots'));

        Sanctum::actingAs($customer);
        $apiResponse = $this->getJson('/api/v1/customer/spaces/'.$space->id.'/slots?date=2026-11-02')
            ->assertOk();
        $apiSlots = collect($apiResponse->json('slots'));
        $this->assertSame($webSlots->pluck('time')->all(), $apiSlots->pluck('time')->all());
        $this->assertSame('2026-11-02 08:00:00', $apiSlots->first()['start']);
        $this->assertSame('08:00', $apiSlots->first()['time']);

        $localStart = Carbon::parse('2026-11-02 08:00:00', $timezone);
        $utcStart = $localStart->copy()->setTimezone('UTC');
        [$normalizedStart] = app(\App\Services\AvailabilityService::class)->validateTimeRange(
            $space,
            $utcStart,
            $utcStart->copy()->addHour(),
        );
        $this->assertSame($localStart->format('Y-m-d H:i:s'), $normalizedStart->format('Y-m-d H:i:s'));

        $this->postJson('/api/v1/customer/bookings', [
            'space_id' => $space->id,
            'date' => '2026-11-02',
            'slot' => '08:00',
        ])->assertCreated();

        $created = Booking::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('2026-11-02 08:00:00', $created->start_time->format('Y-m-d H:i:s'));
        $this->assertSame($timezone, $created->start_time->timezoneName);
    }

    private function makeCustomerAndSpace(): array
    {
        $token = bin2hex(random_bytes(8));
        $customer = User::query()->create([
            'name' => 'Booking test customer '.$token,
            'email' => 'booking-'.$token.'@example.test',
            'password' => 'not-a-real-login',
            'role' => 'customer',
        ]);
        $spaceId = DB::table('spaces')->insertGetId([
            'title' => 'Booking test space '.$token,
            'price_per_hour' => 12.50,
            'status' => 'available',
            'created_by' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customer, \App\Models\Space::query()->findOrFail($spaceId)];
    }
}
