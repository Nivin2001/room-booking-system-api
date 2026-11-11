<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Jobs\SendBookingReminderJob;
use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use App\Notifications\BookingReminderNotification;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BookingReminderReliabilityTest extends TestCase
{
    public function test_scheduler_dispatches_one_unique_job_for_repeated_minute_runs(): void
    {
        $booking = $this->confirmedBooking(now()->addHour());
        Bus::fake();

        $this->artisan('bookings:reminders')->assertExitCode(0);
        $this->artisan('bookings:reminders')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendBookingReminderJob::class, 1);
        Bus::assertDispatched(SendBookingReminderJob::class, fn (SendBookingReminderJob $job) =>
            $job->bookingId === $booking->id
            && $job->uniqueId() === 'booking-reminder:'.$booking->id
        );
    }

    public function test_reminder_job_is_idempotent_and_uses_a_stable_notification_id(): void
    {
        $booking = $this->confirmedBooking(now()->addHour());
        Notification::fake();
        $job = new SendBookingReminderJob((int) $booking->id);
        $firstId = (new BookingReminderNotification($booking))->id;

        $job->handle();
        $job->handle();

        Notification::assertSentToTimes($booking->user, BookingReminderNotification::class, 1);
        $this->assertSame($firstId, (new BookingReminderNotification($booking->fresh()))->id);
        $this->assertTrue((bool) $booking->fresh()->reminder_sent);
        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 60, 120, 300], $job->backoff);
    }

    public function test_an_existing_unique_lock_blocks_a_concurrent_reminder_dispatch(): void
    {
        $booking = $this->confirmedBooking(now()->addHour());
        $job = new SendBookingReminderJob((int) $booking->id);
        $lock = Cache::lock(UniqueLock::getKey($job), $job->uniqueFor);
        $this->assertTrue($lock->get());
        Bus::fake();

        $this->artisan('bookings:reminders')->assertExitCode(0);
        Bus::assertNothingDispatched();

        $lock->release();
        $this->artisan('bookings:reminders')->assertExitCode(0);
        Bus::assertDispatchedTimes(SendBookingReminderJob::class, 1);
    }

    public function test_failed_notification_rolls_back_sent_flag_so_the_job_can_retry(): void
    {
        $booking = $this->confirmedBooking(now()->addHour());
        $calls = 0;
        $dispatcher = Mockery::mock(NotificationDispatcher::class);
        $dispatcher->shouldReceive('send')->twice()->andReturnUsing(function () use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException('simulated notification failure');
            }
        });
        app()->instance(NotificationDispatcher::class, $dispatcher);
        $job = new SendBookingReminderJob((int) $booking->id);

        try {
            $job->handle();
            $this->fail('The first notification attempt should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated notification failure', $exception->getMessage());
        }

        $this->assertFalse((bool) $booking->fresh()->reminder_sent);
        $job->handle();
        $this->assertTrue((bool) $booking->fresh()->reminder_sent);
        $this->assertSame(2, $calls);
    }

    public function test_reminder_and_expiration_scheduler_events_use_shared_overlap_guards(): void
    {
        $events = collect(Schedule::events());

        foreach (['bookings:reminders', 'bookings:expire'] as $command) {
            $event = $events->first(fn ($scheduled) => str_contains((string) $scheduled->command, $command));
            $this->assertNotNull($event, "Missing scheduled event: {$command}");
            $this->assertTrue($event->onOneServer);
            $this->assertTrue($event->withoutOverlapping);
        }
    }

    private function confirmedBooking(\Illuminate\Support\Carbon $start): Booking
    {
        $creator = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $space = Space::query()->create([
            'title' => 'Reminder Test Space',
            'price_per_hour' => 30,
            'capacity' => 8,
            'status' => 'available',
            'created_by' => $creator->id,
        ]);

        return Booking::query()->create([
            'user_id' => $customer->id,
            'space_id' => $space->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addHour(),
            'total_amount' => 30,
            'currency' => 'USD',
            'status' => BookingStatus::Confirmed,
            'reminder_sent' => false,
        ]);
    }
}
