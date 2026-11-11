@extends('layouts.public')

@section('title', 'نتيجة الحجز | '.config('app.name', 'Room Booking'))

@section('content')
    @php
        $statusLabels = [
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
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <section class="bg-white border rounded-4 shadow-sm p-4 p-md-5 text-center">
                <div class="detail-icon mx-auto mb-3" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 12 4 4L19 6"/></svg>
                </div>
                <h1 class="h2 fw-bold">تم إنشاء الحجز</h1>
                <p class="text-secondary">رقم الحجز <strong class="text-dark">#{{ $booking->id }}</strong></p>
                <div class="border rounded-3 p-3 my-4 text-start">
                    <div class="d-flex justify-content-between gap-3 py-2"><span class="text-secondary">المساحة</span><strong><x-space-name :space="$booking->space" /></strong></div>
                    <div class="d-flex justify-content-between gap-3 py-2"><span class="text-secondary">الموعد</span><strong>{{ $booking->start_time->format('Y-m-d H:i') }} – {{ $booking->end_time->format('H:i') }}</strong></div>
                    <div class="d-flex justify-content-between gap-3 py-2"><span class="text-secondary">الحالة</span><strong>{{ $statusLabels[$booking->status->value] ?? $booking->status->value }}</strong></div>
                    <div class="d-flex justify-content-between gap-3 py-2"><span class="text-secondary">الإجمالي</span><strong>{{ number_format((float) $booking->total_amount, 2) }} {{ $booking->currency ?? 'USD' }}</strong></div>
                    @if ($booking->payment)
                        <div class="d-flex justify-content-between gap-3 py-2"><span class="text-secondary">حالة الدفع</span><strong>{{ $paymentStatusLabels[$booking->payment->status->value] ?? $booking->payment->status->value }}</strong></div>
                    @endif
                </div>
                @if ($booking->status === \App\Enums\BookingStatus::PendingPayment)
                    <div class="alert alert-info text-start">الحجز بانتظار الدفع. لن تتغير حالة الدفع عند الرجوع من Stripe؛ التأكيد يتم بعد Webhook موثوق.</div>
                    @if (isset($checkoutSchemaReady) && ! $checkoutSchemaReady)
                        <div class="alert alert-warning text-start">بدء الدفع غير متاح في هذه البيئة حتى تجهيز مخطط Checkout. لم يتم تغيير قاعدة العرض.</div>
                    @endif
                @endif
                @if (session('payment_notice') || isset($paymentNotice))
                    <div class="alert alert-info text-start" role="status">{{ $paymentNotice ?? session('payment_notice') }}</div>
                @endif
                @if ($errors->has('checkout'))
                    <div class="alert alert-danger text-start" role="alert">{{ $errors->first('checkout') }}</div>
                @endif
                @if (! ($booking->can_start_checkout ?? false) && ! (isset($checkoutSchemaReady) && ! $checkoutSchemaReady))
                    <x-checkout-unavailable :reason="$checkoutUnavailableReason ?? null" class="mb-3 text-start" />
                @endif
                @if ($booking->status === \App\Enums\BookingStatus::PendingPayment
                    && ($booking->can_start_checkout ?? false)
                    && (! $booking->payment || ! in_array($booking->payment->status, [
                        \App\Enums\PaymentStatus::Paid,
                        \App\Enums\PaymentStatus::Refunded,
                        \App\Enums\PaymentStatus::PartiallyRefunded,
                        \App\Enums\PaymentStatus::Disputed,
                    ], true)))
                    <form method="POST" action="{{ route('spaces.bookings.checkout', $booking->id) }}" class="mb-3">
                        @csrf
                        <input type="hidden" name="checkout_request_key" value="{{ $checkoutRequestKey }}">
                        <button type="submit" class="btn btn-brand btn-lg">المتابعة إلى الدفع الآمن</button>
                    </form>
                @endif
                <a href="{{ route('spaces.show', $booking->space_id) }}" class="btn btn-brand">العودة إلى المساحة</a>
                <a href="{{ route('spaces.index') }}" class="btn btn-outline-secondary">استعراض المساحات</a>
            </section>
        </div>
    </div>
@endsection
