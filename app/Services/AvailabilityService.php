<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Space;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;

class AvailabilityService
{
    /**
     * Return one-hour slots in the application's local timezone. Past slots
     * are omitted; occupied future slots remain in the result with available=false.
     *
     * @return array<int, array{time: string, start: string, end: string, available: bool}>
     */
    public function getSlots(int|string $spaceId, string $date): array
    {
        $space = Space::query()->findOrFail($spaceId);
        $timezone = config('app.timezone', 'UTC');
        $localDate = $this->parseDate($date, $timezone);

        if ($localDate === null) {
            return [];
        }

        [$opening, $closing] = $this->operatingBounds($space, $localDate->toDateString(), $timezone);
        $now = Carbon::now($timezone);
        $slots = [];

        for ($start = $opening->copy(); $start->copy()->addHour()->lessThanOrEqualTo($closing); $start->addHour()) {
            $end = $start->copy()->addHour();

            // A start equal to now is not bookable. No additional lead-time is imposed.
            if (! $start->greaterThan($now)) {
                continue;
            }

            $slots[] = [
                'time' => $start->format('H:i'),
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
                'available' => $this->isAvailable($space->id, $start, $end),
            ];
        }

        return $slots;
    }

    /**
     * Normalize a requested range into the app timezone and reject invalid,
     * past, or out-of-hours ranges. MySQL DATETIME columns store these local
     * wall-clock values without timezone metadata.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function validateTimeRange(Space $space, mixed $start, mixed $end): array
    {
        $timezone = config('app.timezone', 'UTC');
        $start = $this->parseDateTime($start, $timezone);
        $end = $this->parseDateTime($end, $timezone);

        if (! $end->greaterThan($start)) {
            throw new DomainException('Booking end time must be after its start time.');
        }

        if (! $start->greaterThan(Carbon::now($timezone))) {
            throw new DomainException('Booking start time must be in the future.');
        }

        if ($start->toDateString() !== $end->toDateString()) {
            throw new DomainException('A booking must remain within one local operating day.');
        }

        [$opening, $closing] = $this->operatingBounds($space, $start->toDateString(), $timezone);

        if ($start->lessThan($opening) || $end->greaterThan($closing)) {
            throw new DomainException('Booking time must be within the space operating hours.');
        }

        return [$start, $end];
    }

    public function isAvailable(
        int|string $spaceId,
        mixed $start,
        mixed $end,
        ?int $excludeBookingId = null,
    ): bool {
        $space = Space::query()->findOrFail($spaceId);

        try {
            [$start, $end] = $this->validateTimeRange($space, $start, $end);
        } catch (DomainException|\InvalidArgumentException $exception) {
            return false;
        }

        $query = Booking::query()
            ->where('space_id', $space->id)
            ->whereIn('status', [
                BookingStatus::PendingPayment->value,
                BookingStatus::PendingStaffApproval->value,
                BookingStatus::Confirmed->value,
            ])
            ->where('start_time', '<', $end->format('Y-m-d H:i:s'))
            ->where('end_time', '>', $start->format('Y-m-d H:i:s'));

        if ($excludeBookingId !== null) {
            $query->where('id', '<>', $excludeBookingId);
        }

        return ! $query->exists();
    }

    private function parseDate(string $date, string $timezone): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
        } catch (\Throwable) {
            return null;
        }

        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    private function parseDateTime(mixed $value, string $timezone): Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($timezone);
        }

        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('Booking time is invalid.');
        }

        try {
            return Carbon::parse($value, $timezone)->setTimezone($timezone);
        } catch (\Throwable $exception) {
            throw new DomainException('Booking time is invalid.', previous: $exception);
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function operatingBounds(Space $space, string $date, string $timezone): array
    {
        $opening = $this->operatingTime($date, $space->opening_hour ?: '08:00', $timezone);
        $closing = $this->operatingTime($date, $space->closing_hour ?: '23:00', $timezone);

        if (! $closing->greaterThan($opening)) {
            throw new DomainException('Space operating hours are invalid.');
        }

        return [$opening, $closing];
    }

    private function operatingTime(string $date, string $time, string $timezone): Carbon
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new DomainException('Space operating hours are invalid.');
        }

        return Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$time, $timezone);
    }
}
