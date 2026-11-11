<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_booking_is_created(): void
    {
        $space = $this->space();

        $booking = $this->service()->createBooking($this->customer(), [
            'space_id' => $space->id,
            'start_time' => now()->addDay()->setTime(10, 0),
            'end_time' => now()->addDay()->setTime(11, 0),
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'space_id' => $space->id,
            'status' => BookingStatus::PendingPayment->value,
        ]);
    }

    public function test_an_overlapping_booking_is_rejected(): void
    {
        $space = $this->space();
        $start = now()->addDay()->setTime(10, 0);

        $this->booking($this->customer(), $space, $start, $start->copy()->addHour());

        $this->expectExceptionMessage('Space is not available');

        $this->service()->createBooking($this->customer(), [
            'space_id' => $space->id,
            'start_time' => $start->copy()->addMinutes(30),
            'end_time' => $start->copy()->addMinutes(90),
        ]);
    }

    public function test_empty_space_rejects_a_second_overlapping_attempt(): void
    {
        $space = $this->space();
        $start = now()->addDay()->setTime(10, 0);

        $this->service()->createBooking($this->customer(), [
            'space_id' => $space->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
        ]);

        try {
            $this->service()->createBooking($this->customer(), [
                'space_id' => $space->id,
                'start_time' => $start,
                'end_time' => $start->copy()->addHour(),
            ]);
            $this->fail('The second overlapping booking should be rejected.');
        } catch (\Exception $exception) {
            $this->assertSame('Space is not available', $exception->getMessage());
        }

        $this->assertSame(1, Booking::where('space_id', $space->id)->count());
    }

    public function test_bookings_for_different_spaces_both_succeed(): void
    {
        $firstSpace = $this->space();
        $secondSpace = $this->space();
        $start = now()->addDay()->setTime(10, 0);

        $firstBooking = $this->service()->createBooking($this->customer(), [
            'space_id' => $firstSpace->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
        ]);
        $secondBooking = $this->service()->createBooking($this->customer(), [
            'space_id' => $secondSpace->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
        ]);

        $this->assertDatabaseHas('bookings', ['id' => $firstBooking->id]);
        $this->assertDatabaseHas('bookings', ['id' => $secondBooking->id]);
    }

    public function test_rescheduling_into_an_occupied_time_range_fails_without_changes(): void
    {
        $space = $this->space();
        $customer = $this->customer();
        $firstStart = now()->addDay()->setTime(10, 0);
        $secondStart = now()->addDay()->setTime(12, 0);
        $firstBooking = $this->booking($customer, $space, $firstStart, $firstStart->copy()->addHour());
        $secondBooking = $this->booking($customer, $space, $secondStart, $secondStart->copy()->addHour());

        try {
            $this->service()->updateBooking($customer, $firstBooking->id, [
                'start_time' => $secondStart->copy()->addMinutes(30),
                'end_time' => $secondStart->copy()->addMinutes(90),
            ]);
            $this->fail('The conflicting reschedule should be rejected.');
        } catch (\Exception $exception) {
            $this->assertSame('This time slot is already booked', $exception->getMessage());
        }

        $this->assertDatabaseHas('bookings', [
            'id' => $firstBooking->id,
            'start_time' => $firstStart->toDateTimeString(),
            'end_time' => $firstStart->copy()->addHour()->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $secondBooking->id,
            'start_time' => $secondStart->toDateTimeString(),
            'end_time' => $secondStart->copy()->addHour()->toDateTimeString(),
        ]);
    }

    public function test_cancelled_and_expired_bookings_do_not_block_availability(): void
    {
        $space = $this->space();
        $start = now()->addDay()->setTime(10, 0);

        $this->booking($this->customer(), $space, $start, $start->copy()->addHour(), BookingStatus::Cancelled);
        $this->booking($this->customer(), $space, $start, $start->copy()->addHour(), BookingStatus::Expired);

        $booking = $this->service()->createBooking($this->customer(), [
            'space_id' => $space->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
        ]);

        $this->assertSame(BookingStatus::PendingPayment, $booking->status);
    }

    public function test_a_failure_after_the_insert_rolls_back_the_booking(): void
    {
        $space = $this->space();

        Booking::created(function (): void {
            throw new RuntimeException('Forced transaction failure');
        });

        try {
            $this->service()->createBooking($this->customer(), [
                'space_id' => $space->id,
                'start_time' => now()->addDay()->setTime(10, 0),
                'end_time' => now()->addDay()->setTime(11, 0),
            ]);
            $this->fail('The forced transaction failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced transaction failure', $exception->getMessage());
        } finally {
            Booking::flushEventListeners();
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    private function service(): BookingService
    {
        return app(BookingService::class);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function space(): Space
    {
        $staff = User::factory()->create(['role' => 'staff']);

        return Space::create([
            'title' => 'Room '.fake()->unique()->word(),
            'location' => 'Office',
            'price_per_hour' => 50,
            'capacity' => 10,
            'status' => 'available',
            'created_by' => $staff->id,
            'staff_id' => $staff->id,
        ]);
    }

    private function booking(
        User $customer,
        Space $space,
        $start,
        $end,
        BookingStatus $status = BookingStatus::PendingPayment,
    ): Booking {
        return Booking::create([
            'user_id' => $customer->id,
            'space_id' => $space->id,
            'start_time' => $start,
            'end_time' => $end,
            'total_amount' => 50,
            'status' => $status,
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
