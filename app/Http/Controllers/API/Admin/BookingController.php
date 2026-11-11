<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    //

     public function index(Request $request)
    {
        $query = Booking::with(['user', 'space']);

        // 🔍 Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->get()
        ]);
    }
}
