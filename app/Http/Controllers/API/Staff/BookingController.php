<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingApprovalService;
use App\Services\BookingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    //
      use AuthorizesRequests, ValidatesRequests;
   protected $service;
   protected $approvalService;
   public function __construct(BookingService $service,BookingApprovalService $approvalService)
    {
        $this->service = $service;
        $this->approvalService = $approvalService;
    }
      /**
     * 📋 Get all bookings (Staff)
     */
    public function index(Request $request)
    {
        $bookings = $this->service->listForStaff($request->user(), $request->all());

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    public function approve($id)
    {
        try {
            $booking = Booking::with('space')->findOrFail($id);
            $this->authorize('approve', $booking);

            $booking = $this->approvalService->approve($booking->id);

            return response()->json([
                'success' => true,
                'message' => 'Booking approved successfully',
                'data' => $booking
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden - you are not allowed to approve this booking'
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function reject(Request $request, $id)
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        Validator::make(
            ['idempotency_key' => $idempotencyKey],
            ['idempotency_key' => ['required', 'string', 'max:255']],
        )->validate();

        try {
            $booking = Booking::with('space')->findOrFail($id);
            $this->authorize('reject', $booking);

            $booking = $this->approvalService->reject($booking->id, $idempotencyKey);

            $refundStatus = $booking->getAttribute('refund_operation_status');
            if ($refundStatus !== null && in_array($refundStatus, [
                'processing', 'unknown', 'pending', 'requires_action',
            ], true)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Rejection is awaiting refund confirmation.',
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
                    'message' => 'The refund was not completed; the booking remains awaiting staff approval.',
                    'refund_operation' => [
                        'id' => $booking->getAttribute('refund_operation_id'),
                        'status' => $refundStatus,
                    ],
                    'data' => $booking,
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Booking rejected successfully',
                'data' => $booking
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden - you are not allowed to reject this booking'
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }


}
