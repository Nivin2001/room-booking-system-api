<?php

namespace App\Services;

use App\Events\BookingCreated;
use App\Models\Booking;
use App\Repositories\BookingRepository;
use App\Models\Space;
use App\Notifications\BookingApproved;
use App\Notifications\BookingRejected;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\Notifiable;

class BookingService
{
    protected $repo;

    public function __construct(BookingRepository $repo)
    {
        $this->repo = $repo;
    }

public function isSpaceAvailable($spaceId, $startTime, $endTime)
{
    return !Booking::where('space_id', $spaceId)
        ->where('status', '!=', 'cancelled')
        ->where(function ($q) use ($startTime, $endTime) {
            $q->whereBetween('start_time', [$startTime, $endTime])
              ->orWhereBetween('end_time', [$startTime, $endTime])
              ->orWhere(function ($q2) use ($startTime, $endTime) {
                  $q2->where('start_time', '<=', $startTime)
                     ->where('end_time', '>=', $endTime);
              });
        })
        ->exists();
}

 public function buildSlotTime($date, $slot)
{
    $start = Carbon::parse($date . ' ' . $slot);
    $end = $start->copy()->addHour();

    return [$start, $end];
}
  public function getSlots($spaceId, $date)
{
    $slots = [];

    for ($hour = 8; $hour < 20; $hour++) {
        $time = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';

        [$start, $end] = $this->buildSlotTime($date, $time);

        $available = $this->isSpaceAvailable($spaceId, $start, $end);

        $slots[] = [
            'time' => $time,
            'available' => $available
        ];
    }

    return $slots;
}


    /**
     * 📅 Create Booking
     */
public function createCustomerBooking($user, array $data)
{
    return DB::transaction(function () use ($user, $data) {

        $conflict = Booking::where('space_id', $data['space_id'])
            ->whereIn('status', ['pending', 'confirmed'])
            ->lockForUpdate()
            ->where(function ($q) use ($data) {
                $q->whereBetween('start_time', [$data['start_time'], $data['end_time']])
                  ->orWhereBetween('end_time', [$data['start_time'], $data['end_time']])
                  ->orWhere(function ($q2) use ($data) {
                      $q2->where('start_time', '<=', $data['start_time'])
                         ->where('end_time', '>=', $data['end_time']);
                  });
            })
            ->exists();

        if ($conflict) {
            return [
                'success' => false,
                'message' => 'This space is already booked in this time',
                'data' => null
            ];
        }

        $booking = Booking::create([
            'user_id' => $user->id,
            'space_id' => $data['space_id'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'status' => 'pending',
        ]);
        event(new BookingCreated($booking));

        return [
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking
        ];
    });
}
    /**
     * 📋 Get all bookings (with relations)
     */
    public function getAllBookings()
    {
        return Booking::with(['user', 'space'])
            ->latest()
            ->get();
    }

    /**
     * 🔍 Get single booking
     */
    public function getBooking($id)
    {
        return Booking::with(['user', 'space'])
            ->findOrFail($id);
    }


    public function cancelBooking($id)
{
    $booking = Booking::where('id', $id)
        ->where('user_id', auth()->id())
        ->firstOrFail();

    // 🚨 business rule: can't cancel past bookings
    if ($booking->status === 'cancelled') {
        return response()->json([
            'message' => 'Booking already cancelled'
        ], 400);
    }

    if (now()->greaterThan($booking->start_time)) {
        return response()->json([
            'message' => 'Cannot cancel past or ongoing booking'
        ], 422);
    }

    $booking->update([
        'status' => 'cancelled'
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Booking cancelled successfully',
        'data' => $booking->load('space')
    ]);
}

public function list($filters)
    {
        $query = Booking::with(['user', 'space']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('start_time', $filters['date']);
        }

        return $query->latest()->paginate(10);
    }

    public function approve($id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->status !== 'pending') {
            throw new \Exception('Only pending bookings can be approved');
        }

        $booking->update([
            'status' => 'confirmed'
        ]);
            // 🔔 notify customer
    $booking->user->notify(new BookingApproved($booking));


        return $booking;
    }

    public function reject($id)
    {
        $booking = Booking::findOrFail($id);

        if ($booking->status !== 'pending') {
            throw new \Exception('Only pending bookings can be rejected');
        }

        $booking->update([
            'status' => 'rejected'
        ]);
        $booking->user->notify(new BookingRejected($booking));

        return $booking;
    }
}
