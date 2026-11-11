<?php

namespace App\Http\Controllers\Web;

use App\Models\Booking;
use App\Services\stripe\StripeCheckoutService;
use App\Services\BookingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MyBookingsPageController
{
    public function __construct(
        private readonly StripeCheckoutService $stripeCheckoutService,
        private readonly BookingService $bookingService,
    ) {}

    public function index()
    {
        abort_unless(Auth::guard('web')->user()?->role === 'customer', 403);

        $bookings = Booking::query()
            ->with(['space', 'payment'])
            ->where('user_id', Auth::guard('web')->id())
            ->latest('created_at')
            ->latest('id')
            ->paginate(10);

        $bookings->getCollection()->each(function (Booking $booking): void {
            $reason = $this->stripeCheckoutService->checkoutUnavailableReason($booking);
            $booking->setAttribute(
                'can_start_checkout',
                $reason === null,
            );
            $booking->setAttribute(
                'checkout_unavailable_reason',
                $reason,
            );
            $booking->setAttribute('checkout_request_key', (string) Str::uuid());
            $booking->setAttribute('cancellation_request_key', $this->cancellationRequestKey($booking->id));
        });

        return view('bookings.index', [
            'bookings' => $bookings,
            'checkoutSchemaReady' => Schema::hasTable('checkout_attempts'),
        ]);
    }

    public function show(int $booking)
    {
        abort_unless(Auth::guard('web')->user()?->role === 'customer', 403);
        $booking = Booking::query()->with(['space.categories', 'payment.transactions'])
            ->where('user_id', Auth::guard('web')->id())->findOrFail($booking);
        $checkoutReason = $this->stripeCheckoutService->checkoutUnavailableReason($booking);
        $booking->setAttribute('can_start_checkout', $checkoutReason === null);

        return view('bookings.show', [
            'booking' => $booking,
            'checkoutRequestKey' => (string) Str::uuid(),
            'cancellationRequestKey' => $this->cancellationRequestKey($booking->id),
            'checkoutSchemaReady' => Schema::hasTable('checkout_attempts'),
            'checkoutUnavailableReason' => $checkoutReason,
        ]);
    }

    public function cancel(Request $request, int $booking)
    {
        abort_unless(Auth::guard('web')->user()?->role === 'customer', 403);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'idempotency_key.required' => 'تعذر التحقق من طلب الإلغاء. أعد تحميل الصفحة وحاول مجددًا.',
            'idempotency_key.uuid' => 'مفتاح طلب الإلغاء غير صالح.',
        ]);

        try {
            $result = $this->bookingService->cancelBooking(
                Auth::guard('web')->user(),
                $booking,
                $validated['idempotency_key'],
            );
            $status = $result->getAttribute('refund_operation_status');
            $notice = in_array($status, ['unknown', 'processing', 'pending', 'requires_action'], true)
                ? 'طلب الإلغاء محفوظ، لكن نتيجة الاسترداد لم تُحسم بعد. لا تعاود المحاولة بمفتاح جديد.'
                : 'تم تسجيل طلب الإلغاء.';

            return redirect()->route('customer.bookings.show', $booking)->with('payment_notice', $notice);
        } catch (\Throwable $exception) {
            return redirect()->route('customer.bookings.show', $booking)
                ->withErrors(['cancellation' => 'تعذر إلغاء الحجز. تحقق من حالة الحجز وحاول مجددًا.']);
        }
    }

    private function cancellationRequestKey(int $bookingId): string
    {
        $sessionKey = 'customer_booking_cancel_keys.'.$bookingId;
        $key = session()->get($sessionKey);
        if (! is_string($key) || ! Str::isUuid($key)) {
            $key = (string) Str::uuid();
            session()->put($sessionKey, $key);
        }

        return $key;
    }
}
