<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Space;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\PricingService;
use App\Services\stripe\StripeCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BookingPageController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly BookingService $bookingService,
        private readonly StripeCheckoutService $stripeCheckoutService,
        private readonly PricingService $pricingService,
    ) {}

    public function create(Request $request, int $space)
    {
        if ($response = $this->authorizeCustomerSession()) {
            return $response;
        }

        $space = $this->availableSpace($space);
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'date.date_format' => 'يرجى اختيار تاريخ صحيح.',
        ]);

        $date = $validated['date'] ?? now()->format('Y-m-d');
        $slots = $this->availabilityService->getSlots($space->id, $date);
        foreach ($slots as &$slot) {
            $slot['estimated_total'] = $this->pricingService->calculatePrice(
                $space->id,
                $slot['start'],
                $slot['end'],
            );
        }
        unset($slot);

        return view('spaces.book', compact('space', 'date', 'slots'));
    }

    public function store(Request $request, int $space)
    {
        if ($response = $this->authorizeCustomerSession()) {
            return $response;
        }

        $space = $this->availableSpace($space);
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'slot' => ['required', 'date_format:H:i'],
        ], [
            'date.required' => 'يرجى اختيار تاريخ الحجز.',
            'date.date_format' => 'صيغة التاريخ غير صحيحة.',
            'slot.required' => 'يرجى اختيار فترة زمنية متاحة.',
            'slot.date_format' => 'الفترة الزمنية المختارة غير صحيحة.',
        ]);

        $selectedSlot = collect($this->availabilityService->getSlots($space->id, $validated['date']))
            ->firstWhere('time', $validated['slot']);

        if (! $selectedSlot || ! $selectedSlot['available']) {
            return back()->withInput()->withErrors([
                'slot' => 'هذه الفترة لم تعد متاحة. حدّث المواعيد واختر فترة أخرى.',
            ]);
        }

        try {
            $booking = $this->bookingService->createBooking(Auth::guard('web')->user(), [
                'space_id' => $space->id,
                'start_time' => $selectedSlot['start'],
                'end_time' => $selectedSlot['end'],
            ]);
        } catch (\DomainException $exception) {
            $message = $exception->getMessage() === 'Space is not available'
                ? 'حُجزت هذه الفترة للتو. اختر موعدًا آخر.'
                : 'اختر فترة مستقبلية تقع ضمن ساعات تشغيل المساحة.';

            return back()->withInput()->withErrors(['slot' => $message]);
        } catch (\Exception $exception) {
            if ($exception->getMessage() === 'Space is not available') {
                return back()->withInput()->withErrors([
                    'slot' => 'حُجزت هذه الفترة للتو. اختر موعدًا آخر.',
                ]);
            }

            throw $exception;
        }

        return redirect()->route('spaces.bookings.confirmation', $booking);
    }

    public function confirmation(int $booking)
    {
        if ($response = $this->authorizeCustomerSession()) {
            return $response;
        }

        $booking = Booking::query()
            ->with(['space', 'payment'])
            ->where('user_id', Auth::guard('web')->id())
            ->findOrFail($booking);

        $checkoutReason = $this->stripeCheckoutService->checkoutUnavailableReason($booking);
        $booking->setAttribute('can_start_checkout', $checkoutReason === null);

        return view('spaces.booking-result', [
            'booking' => $booking,
            'checkoutRequestKey' => (string) Str::uuid(),
            'checkoutSchemaReady' => Schema::hasTable('checkout_attempts'),
            'checkoutUnavailableReason' => $checkoutReason,
        ]);
    }

    public function startCheckout(Request $request, int $booking)
    {
        if ($response = $this->authorizeCustomerSession()) {
            return $response;
        }

        $booking = $this->ownedBooking($booking);
        if (! $this->stripeCheckoutService->canStartCheckout($booking)) {
            return redirect()->route('spaces.bookings.confirmation', $booking)
                ->withErrors(['checkout' => 'هذا الحجز غير مؤهل لبدء الدفع.']);
        }

        $successUrl = route('spaces.bookings.payment-success', $booking->id)
            .'?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('spaces.bookings.payment-cancelled', $booking->id);
        $validated = $request->validate([
            'checkout_request_key' => ['required', 'uuid'],
        ]);

        try {
            $checkout = $this->stripeCheckoutService->createCheckoutSession(
                $booking,
                $validated['checkout_request_key'],
                $successUrl,
                $cancelUrl,
            );

            $checkoutUrl = $checkout['url'] ?? null;
            if (! is_string($checkoutUrl)) {
                $message = match ($checkout['status'] ?? 'unknown') {
                    'complete' => 'اكتملت جلسة Checkout. ننتظر تأكيد Webhook موثوق؛ لا تبدأ محاولة جديدة الآن.',
                    'expired' => 'انتهت جلسة الدفع. حدّث الصفحة لبدء محاولة جديدة.',
                    'failed' => 'تعذر إنشاء جلسة الدفع. حدّث الصفحة لإعادة المحاولة.',
                    default => 'نتيجة محاولة الدفع غير محسومة. لن ننشئ جلسة أخرى حتى تتم المصالحة.',
                };

                return redirect()->route('spaces.bookings.confirmation', $booking)
                    ->with('payment_notice', $message);
            }
            if (! is_string($checkoutUrl)
                || filter_var($checkoutUrl, FILTER_VALIDATE_URL) === false
                || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https') {
                throw new \UnexpectedValueException('Invalid Checkout URL returned by provider.');
            }

            return redirect()->away($checkoutUrl);
        } catch (\Throwable $exception) {
            Log::warning('Web Checkout session could not be started.', [
                'booking_id' => $booking->id,
                'exception' => class_basename($exception),
            ]);

            return redirect()->route('spaces.bookings.confirmation', $booking)
                ->withErrors(['checkout' => 'تعذر بدء عملية الدفع الآن. يرجى المحاولة لاحقًا.']);
        }
    }

    public function paymentSuccess(int $booking)
    {
        if ($response = $this->authorizeCustomerSession()) {
            return $response;
        }

        $booking = $this->ownedBooking($booking);

        return view('spaces.booking-result', [
            'booking' => $booking,
            'paymentNotice' => 'عاد المتصفح من صفحة الدفع. لا يُعد ذلك تأكيدًا للدفع؛ ستظهر الحالة بعد استلام Webhook موثوق من Stripe.',
            'checkoutSchemaReady' => Schema::hasTable('checkout_attempts'),
        ]);
    }

    public function paymentCancelled(int $booking)
    {
        if ($response = $this->authorizeCustomerSession()) {
            return $response;
        }

        $booking = $this->ownedBooking($booking);

        return redirect()->route('spaces.bookings.confirmation', $booking)
            ->with('payment_notice', 'أُلغيت جلسة الدفع أو لم تكتمل. لم يتم تغيير حالة الحجز.');
    }

    private function ownedBooking(int $booking): Booking
    {
        return Booking::query()
            ->with(['space', 'payment'])
            ->where('user_id', Auth::guard('web')->id())
            ->findOrFail($booking);
    }

    private function authorizeCustomerSession()
    {
        if (! Auth::guard('web')->check()) {
            if (\Illuminate\Support\Facades\Route::has('login')) {
                return redirect()->guest(route('login'));
            }

            return redirect()->route('spaces.index')->with(
                'booking_notice',
                'إنشاء الحجز يتطلب تسجيل دخول العميل. تسجيل الدخول المتاح حاليًا مخصص للـ API ولا ينشئ جلسة متصفح.'
            );
        }

        abort_unless(Auth::guard('web')->user()->role === 'customer', 403);

        return null;
    }

    private function availableSpace(int $space): Space
    {
        return Space::query()
            ->where('status', 'available')
            ->findOrFail($space);
    }
}
