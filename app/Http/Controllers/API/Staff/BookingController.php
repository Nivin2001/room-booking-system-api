<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingApprovalService;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

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
        $bookings = $this->service->list($request->all());

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    public function approve($id)
    {
        try {
            $booking = $this->approvalService->approve($id);
            $this->authorize('approve', $booking);

            return response()->json([
                'success' => true,
                'message' => 'Booking approved successfully',
                'data' => $booking
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function reject($id)
    {
        try {
            $booking = $this->approvalService->reject($id);

            return response()->json([
                'success' => true,
                'message' => 'Booking rejected successfully',
                'data' => $booking
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }


}
