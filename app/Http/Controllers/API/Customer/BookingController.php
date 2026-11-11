<?php

namespace App\Http\Controllers\API\Customer;

use App\Http\Controllers\Controller;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Space;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{

    use AuthorizesRequests, ValidatesRequests;

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
            'date' => ['required', 'date_format:Y-m-d'],
            'slot' => ['required', 'date_format:H:i'],
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

        try {
            $booking = $this->service->createBooking(
                Auth::user(),
                [
                    'space_id' => $validated['space_id'],
                    'start_time' => $start,
                    'end_time' => $end,
                ]
            );
        } catch (\DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() === 'Space is not available'
                    ? 'Selected slot is no longer available.'
                    : 'Selected slot must be in the future and within operating hours.',
            ], 422);
        }

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
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $space = Space::findOrFail($id);

        $slots = $this->service->getSlots($space->id, $request->date);

        return response()->json([
            'success' => true,
            // 'date' => $request->date,
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
        $booking = Booking::findOrFail($id);
        $this->authorize('view', $booking);

        return response()->json([
            'success' => true,
            'data' => $this->service->getBooking($booking->id)
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
        $booking = Booking::findOrFail($id);

        $this->authorize('update', $booking);

        try {
            $booking = $this->service->updateBooking(
                Auth::user(),
                $id,
                $validated
            );
        } catch (\DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Selected time must be in the future and within operating hours.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }

    /**
     * ❌ Cancel
     */
    public function cancelBooking(Request $request, $id)
    {
        $booking = Booking::with('payment')->findOrFail($id);

        // 🔐 Authorization
        $this->authorize('cancel', $booking);

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($booking->status === BookingStatus::Confirmed
            && $booking->payment?->status === PaymentStatus::Paid) {
            Validator::make(
                ['idempotency_key' => $idempotencyKey],
                ['idempotency_key' => ['required', 'string', 'max:255']],
            )->validate();
        }

        $booking = $this->service->cancelBooking($request->user(), $id, $idempotencyKey);

        $refundStatus = $booking->getAttribute('refund_operation_status');
        if ($refundStatus !== null && in_array($refundStatus, [
            'processing', 'unknown', 'pending', 'requires_action',
        ], true)) {
            return response()->json([
                'success' => true,
                'message' => 'Cancellation is awaiting refund confirmation.',
                'refund_operation' => [
                    'id' => $booking->getAttribute('refund_operation_id'),
                    'status' => $refundStatus,
                ],
                'data' => $booking,
            ], 202);
        }

        if ($refundStatus !== null && in_array($refundStatus, ['failed', 'canceled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'The refund was not completed; the booking remains unchanged.',
                'refund_operation' => [
                    'id' => $booking->getAttribute('refund_operation_id'),
                    'status' => $refundStatus,
                ],
                'data' => $booking,
            ], 409);
        }


        $response = [
            'success' => true,
            'data' => $booking,
        ];

        if ($refundStatus !== null) {
            $response['refund_operation'] = [
                'id' => $booking->getAttribute('refund_operation_id'),
                'status' => $refundStatus,
            ];
        }

        return response()->json($response);
    }
    public function status($id)
    {
        $booking = Booking::with('payment')
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'booking_id' => $booking->id,
            'booking_status' => $booking->status,
            'payment_status' => $booking->payment?->status,
        ]);
    }
}
