<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Space;
use App\Notifications\BookingCreated;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;



class BookingController extends Controller
{
      use AuthorizesRequests, ValidatesRequests;
    protected $service;

    public function __construct(BookingService $service)
    {
        $this->service = $service;
    }
    /**
     * 📅 Create Booking
     */
public function store(Request $request)
{
    $request->validate([
        'space_id' => 'required|exists:spaces,id',
        'date' => 'required|date',
        'slot' => 'required'
    ]);

    [$start, $end] = $this->service->buildSlotTime(
        $request->date,
        $request->slot
    );

    $result = $this->service->createCustomerBooking(
        auth()->user(),
        [
            'space_id' => $request->space_id,
            'start_time' => $start,
            'end_time' => $end,
        ]
    );

    if (!$result['success']) {
        return response()->json($result, 422);
    }

    return response()->json($result, 201);
}



// public function store(Request $request)
// {
//     $request->validate([
//         'space_id' => 'required|exists:spaces,id',
//         'date' => 'required|date',
//         'slot' => 'required'
//     ]);

//     $space = Space::find($request->space_id);

//     if (!$space) {
//         return response()->json([
//             'success' => false,
//             'message' => 'Space not found'
//         ], 404);
//     }

//     // ⏱️ build time
//     [$start, $end] = $this->service->buildSlotTime(
//         $request->date,
//         $request->slot
//     );

//     // 📦 create booking
//     $result = $this->service->createCustomerBooking(
//         auth()->user(),
//         [
//             'space_id' => $space->id,
//             'start_time' => $start,
//             'end_time' => $end,
//         ]
//     );

//     // ❌ fail case
//     if (!$result['success']) {
//         return response()->json([
//             'success' => false,
//             'message' => $result['message']
//         ], 422);
//     }

//     // 📩 SUCCESS → send notification (THIS IS IMPORTANT)
//     $booking = $result['data'];

//     // // $booking->user->notify(new BookingCreated($booking));
//     // auth()->user()->notify(new BookingCreated($booking));

//     return response()->json([
//         'success' => true,
//         'message' => $result['message'],
//         'data' => $booking
//     ], 201);
// }

public function slots($id, Request $request)
{
    $space = Space::find($id);

    if (!$space) {
        return response()->json([
            'success' => false,
            'message' => 'Space not found'
        ], 404);
    }

    $date = $request->date;

    $slots = $this->service->getSlots($space->id, $date);

    return response()->json([
        'success' => true,
        'date' => $date,
        'slots' => $slots
    ]);
}

    /**
     * 📋 Get all bookings (with eager loading)
     */
    public function index()
    {
        $bookings = $this->service->getAllBookings();

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }
    /**
     * 🔍 Get single booking
     */
    public function show($id)
    {
        $booking = $this->service->getBooking($id);

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }

    public function myBookings()
{
    $bookings = auth()->user()
        ->bookings()
        ->with('space')
        ->latest()
        ->get();

    return response()->json([
        'success' => true,
        'data' => $bookings
    ]);
}

    /**
     * ❌ Soft Cancel Booking (NOT delete)
     */
    public function cancelBooking($id)
    {
        $booking = $this->service->cancelBooking($id);
        $this->authorize('cancel', $booking);

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled successfully',
            'data' => $booking
        ]);
    }


}
