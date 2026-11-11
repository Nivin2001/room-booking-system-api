<?php

namespace App\Http\Controllers\Web;

use App\Enums\BookingStatus;
use App\Enums\RefundOperationStatus;
use App\Models\Booking;
use App\Services\BookingApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffBookingPageController
{
    public function __construct(private readonly BookingApprovalService $approvalService) {}

    public function spaces(Request $request)
    {
        $spaces = $request->user()->assignedSpaces()
            ->with('categories')
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(12);

        return view('staff.spaces.index', compact('spaces'));
    }

    public function index(Request $request)
    {
        $statuses = array_column(BookingStatus::cases(), 'value');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($statuses)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $staffId = $request->user()->id;
        $query = Booking::query()->with(['space', 'user', 'payment'])
            ->whereHas('space', fn ($spaces) => $spaces->where('staff_id', $staffId));

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('id', is_numeric($term) ? (int) $term : -1)
                    ->orWhereHas('space', fn ($spaces) => $spaces->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('user', fn ($users) => $users->where('name', 'like', '%'.$term.'%'));
            });
        }
        $bookings = $query->latest('created_at')->paginate(15)->withQueryString();

        return view('staff.bookings.index', [
            'bookings' => $bookings,
            'filters' => $filters,
            'statuses' => BookingStatus::cases(),
            'statusLabels' => [
                BookingStatus::PendingPayment->value => 'بانتظار الدفع',
                BookingStatus::PaymentFailed->value => 'تعذر الدفع',
                BookingStatus::PendingStaffApproval->value => 'بانتظار موافقة الموظف',
                BookingStatus::Confirmed->value => 'مؤكد',
                BookingStatus::Rejected->value => 'مرفوض',
                BookingStatus::Cancelled->value => 'ملغي',
                BookingStatus::Expired->value => 'منتهي',
            ],
        ]);
    }

    public function show(Request $request, int $booking)
    {
        $booking = $this->ownedBooking($request, $booking);
        abort_unless($request->user()->can('view', $booking), 403);

        return view('staff.bookings.show', [
            'booking' => $booking->load(['space', 'user', 'payment.transactions']),
            'rejectRequestKey' => (string) Str::uuid(),
        ]);
    }

    public function approve(Request $request, int $booking)
    {
        $booking = $this->ownedBooking($request, $booking);
        abort_unless($request->user()->can('approve', $booking), 403);

        try {
            $approved = $this->approvalService->approve($booking->id);
            return redirect()->route('staff.bookings.show', $approved->id)->with('auth_notice', 'تمت الموافقة على الحجز.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['booking' => 'تعذرت الموافقة. قد تكون حالة الحجز تغيّرت أو أنه قيد المعالجة.']);
        }
    }

    public function reject(Request $request, int $booking)
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'يرجى إدخال سبب الرفض.',
            'reason.max' => 'يجب ألا يتجاوز سبب الرفض 1000 حرف.',
        ]);
        $booking = $this->ownedBooking($request, $booking);
        abort_unless($request->user()->can('reject', $booking), 403);

        try {
            $result = $this->approvalService->reject($booking->id, $validated['idempotency_key'], $validated['reason']);
            $refundStatus = $result->getAttribute('refund_operation_status');
            if (in_array($refundStatus, [RefundOperationStatus::Processing->value, RefundOperationStatus::Unknown->value, RefundOperationStatus::Pending->value, RefundOperationStatus::RequiresAction->value], true)) {
                return redirect()->route('staff.bookings.show', $booking->id)
                    ->with('payment_notice', 'طلب الرفض ينتظر حسم نتيجة الاسترداد؛ بقي الحجز بانتظار الموافقة حتى تتأكد النتيجة.');
            }
            return redirect()->route('staff.bookings.show', $booking->id)->with('auth_notice', 'تم رفض الحجز بعد تأكيد نتيجة الاسترداد.');
        } catch (\Throwable $exception) {
            return back()->withErrors(['booking' => 'تعذر إكمال الرفض. تحقق من حالة الحجز والدفع وحاول مجددًا.']);
        }
    }

    private function ownedBooking(Request $request, int $booking): Booking
    {
        return Booking::query()->whereKey($booking)
            ->whereHas('space', fn ($spaces) => $spaces->where('staff_id', $request->user()->id))
            ->firstOrFail();
    }
}
