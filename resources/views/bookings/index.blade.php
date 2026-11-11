@extends('layouts.public')

@section('title', 'حجوزاتي | '.config('app.name', 'Room Booking'))

@section('description', 'عرض حجوزاتك وحالاتها وتفاصيل الدفع.')

@section('content')
    @php
        $bookingStatusLabels = [
            'pending_payment' => 'بانتظار الدفع',
            'payment_failed' => 'تعذر الدفع',
            'pending_staff_approval' => 'بانتظار موافقة الموظف',
            'confirmed' => 'مؤكد',
            'rejected' => 'مرفوض',
            'cancelled' => 'ملغي',
            'expired' => 'منتهي الصلاحية',
        ];
        $paymentStatusLabels = [
            'pending' => 'بانتظار الدفع',
            'processing' => 'قيد المعالجة',
            'paid' => 'مدفوع',
            'failed' => 'فشل الدفع',
            'refunded' => 'مسترد',
            'partially_refunded' => 'مسترد جزئيًا',
            'disputed' => 'متنازع عليه',
        ];
    @endphp

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-3 mb-4">
        <div>
            <p class="text-brand fw-semibold mb-2">حساب العميل</p>
            <h1 class="display-6 fw-bold mb-1">حجوزاتي</h1>
            <p class="text-secondary mb-0">تابع مواعيدك وحالة كل حجز ودفع.</p>
        </div>
        <a href="{{ route('spaces.index') }}" class="btn btn-brand rounded-pill px-4">استكشف المساحات</a>
    </div>

    @if (! $checkoutSchemaReady)
        <div class="alert alert-warning" role="status">بدء الدفع غير متاح حاليًا في هذه البيئة. يلزم تجهيز مخطط Checkout قبل تفعيل المحاولة، ولم يتم تغيير قاعدة العرض.</div>
    @endif

    @if ($bookings->isEmpty())
        <section class="bg-white border rounded-4 shadow-sm p-4 p-md-5 text-center">
            <div class="detail-icon mx-auto mb-3" aria-hidden="true">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
            </div>
            <h2 class="h4 fw-bold">لا توجد حجوزات حتى الآن</h2>
            <p class="text-secondary">عندما تنشئ حجزًا، ستجد تفاصيله وحالته هنا.</p>
            <a href="{{ route('spaces.index') }}" class="btn btn-brand">استعراض المساحات</a>
        </section>
    @else
        <div class="row g-3">
            @foreach ($bookings as $booking)
                @php
                    $minutes = (int) $booking->start_time->diffInMinutes($booking->end_time);
                    $amount = $booking->payment?->amount ?? $booking->total_amount;
                    $currency = $booking->payment?->currency ?? $booking->currency ?? 'USD';
                @endphp
                <div class="col-12">
                    <article class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                <div class="flex-grow-1">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <h2 class="h5 fw-bold mb-0">@if($booking->space)<x-space-name :space="$booking->space" />@else مساحة غير متاحة @endif</h2>
                                        <span class="badge rounded-pill text-bg-light border">حجز #{{ $booking->id }}</span>
                                    </div>
                                    <div class="row g-2 small text-secondary">
                                        <div class="col-sm-6">الموعد: <strong class="text-dark">{{ $booking->start_time->format('Y-m-d H:i') }} – {{ $booking->end_time->format('H:i') }}</strong></div>
                                        <div class="col-sm-6">المدة: <strong class="text-dark">{{ intdiv($minutes, 60) ? intdiv($minutes, 60).' ساعة ' : '' }}{{ $minutes % 60 ? ($minutes % 60).' دقيقة' : '' }}</strong></div>
                                        <div class="col-sm-6">حالة الحجز: <strong class="text-dark">{{ $bookingStatusLabels[$booking->status->value] ?? $booking->status->value }}</strong></div>
                                        <div class="col-sm-6">المبلغ: <strong class="text-dark">{{ number_format((float) $amount, 2) }} {{ strtoupper($currency) }}</strong></div>
                                        @if ($booking->payment)
                                            <div class="col-sm-6">حالة الدفع: <x-status-badge :status="$booking->payment->status" kind="payment" /></div>
                                        @else
                                            <div class="col-sm-6">حالة الدفع: <strong class="text-dark">لم يبدأ الدفع</strong></div>
                                        @endif
                                    </div>
                                </div>
                                <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2">
                                    <a href="{{ route('customer.bookings.show', $booking->id) }}" class="btn btn-outline-secondary">تفاصيل الحجز</a>
                                    @if ($booking->can_start_checkout)
                                        <form method="POST" action="{{ route('spaces.bookings.checkout', $booking->id) }}" class="m-0">
                                            @csrf
                                            <input type="hidden" name="checkout_request_key" value="{{ $booking->checkout_request_key }}">
                                            <button type="submit" class="btn btn-brand w-100">المتابعة إلى الدفع</button>
                                        </form>
                                    @endif
                                    @if (! $booking->can_start_checkout)
                                        <x-checkout-unavailable :reason="$booking->checkout_unavailable_reason" class="align-self-center" />
                                    @endif
                                    @if (! in_array($booking->status->value, ['pending_staff_approval', 'rejected', 'cancelled', 'expired'], true))
                                        <form method="POST" action="{{ route('customer.bookings.cancel', $booking->id) }}" class="m-0" onsubmit="return confirm('تأكيد طلب إلغاء الحجز؟')">
                                            @csrf
                                            <input type="hidden" name="idempotency_key" value="{{ $booking->cancellation_request_key }}">
                                            <button type="submit" class="btn btn-outline-danger">إلغاء الحجز</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        @if ($bookings->hasPages())
            <nav class="mt-4" aria-label="صفحات الحجوزات">
                {{ $bookings->links() }}
            </nav>
        @endif
    @endif
@endsection
