<?php
namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Space;
use App\Services\AvailabilityService;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $service,
        protected AvailabilityService $availabilityService
    ) {}

    /**
     * 📅 Create Booking
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'space_id' => 'required|exists:spaces,id',
            'date' => 'required|date',
            'slot' => 'required'
        ]);

       $slots = $this->availabilityService->getSlots(
    $request->space_id,
    $request->date
);

$selectedSlot = collect($slots)->firstWhere('time', $request->slot);

if (!$selectedSlot || !$selectedSlot['available']) {
    return response()->json([
        'success' => false,
        'message' => 'Selected slot is not available'
    ], 422);
}

$start = $selectedSlot['start'];
$end = $selectedSlot['end'];

        $booking = $this->service->createBooking(
            Auth::user(),
            [
                'space_id' => $validated['space_id'],
                'start_time' => $start,
                'end_time' => $end,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $booking
        ], 201);
    }

    /**
     * ⏰ Slots
     */
    public function slots($id, Request $request)
    {
        $request->validate([
            'date' => 'required|date'
        ]);

        $space = Space::findOrFail($id);

        $slots = $this->service->getSlots($space->id, $request->date);

        return response()->json([
            'success' => true,
            'date' => $request->date,
            'slots' => $slots
        ]);
    }

    /**
     * 📋 All bookings
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getAllBookings()
        ]);
    }

    /**
     * 🔍 Show
     */
    public function show($id)
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getBooking($id)
        ]);
    }

    /**
     * 👤 My bookings
     */
    public function myBookings()
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getUserBookings(Auth::id())
        ]);
    }

    /**
     * ✏️ Update
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
        ]);

        $booking = $this->service->updateBooking(
            Auth::user(),
            $id,
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }

    /**
     * ❌ Cancel
     */
    public function cancelBooking($id)
    {
        $booking = $this->service->cancelBooking($id);

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }
}
