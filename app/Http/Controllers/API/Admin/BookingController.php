<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    //

public function testMail()
{
    Mail::raw('Test Email 🔥', function ($message) {
        $message->to('test@test.com')
                ->subject('Test Booking');
    });

    return response()->json([
        'message' => 'Email sent (check log)'
    ]);
}
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
