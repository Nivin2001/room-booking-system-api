@extends('layouts.dashboard')
@section('title', 'تفاصيل الحجز #'.$booking->id.' | الإدارة')
@section('dashboard-content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><p class="text-brand fw-semibold mb-1">مراجعة إدارية</p><h1 class="h2 fw-bold mb-0">الحجز #{{ $booking->id }}</h1></div>
    <a href="{{ route('admin.bookings.index') }}" class="btn btn-outline-secondary">كل الحجوزات</a>
</div>
<div class="row g-3">
    <div class="col-xl-7"><section class="bg-white border rounded-4 p-4">
        <h2 class="h5 fw-bold mb-3">بيانات الحجز</h2>
        <div class="row g-3">
            <div class="col-sm-6"><div class="small text-secondary">العميل</div>{{ $booking->user?->name }} · {{ $booking->user?->email }}</div>
            <div class="col-sm-6"><div class="small text-secondary">المساحة</div>@if($booking->space)<x-space-name :space="$booking->space" />@else مساحة غير متاحة @endif</div>
            <div class="col-sm-6"><div class="small text-secondary">الموعد</div>{{ $booking->start_time->format('Y-m-d H:i') }} – {{ $booking->end_time->format('H:i') }}</div>
            <div class="col-sm-6"><div class="small text-secondary">الإجمالي</div>{{ number_format((float)$booking->total_amount,2) }} {{ strtoupper($booking->currency) }}</div>
            <div class="col-sm-6"><div class="small text-secondary">الحالة</div><x-status-badge :status="$booking->status" /></div>
            <div class="col-sm-6"><div class="small text-secondary">الدفع</div>
                @if($booking->payment)<x-status-badge :status="$booking->payment->status" kind="payment" />@else<span>غير موجود</span>@endif
            </div>
            <div class="col-sm-6"><div class="small text-secondary">تاريخ الإنشاء</div>{{ $booking->created_at->format('Y-m-d H:i') }}</div>
            <div class="col-sm-6"><div class="small text-secondary">الموافقة</div>{{ $booking->approved_at?->format('Y-m-d H:i') ?? 'لا توجد' }}</div>
        </div>
    </section></div>
    <div class="col-xl-5"><section class="bg-white border rounded-4 p-4">
        <h2 class="h5 fw-bold mb-3">سجل الدفع</h2>
        @if(!$booking->payment)
            <p class="text-secondary mb-0">لا يوجد Payment مرتبط.</p>
        @else
            <div class="small mb-3">Payment #{{ $booking->payment->id }} · {{ strtoupper($booking->payment->currency) }} · {{ $booking->payment->provider ?: '—' }}</div>
            <h3 class="h6">Payment Transactions</h3>
            @forelse($booking->payment->transactions as $transaction)
                <div class="border rounded-3 p-2 mb-2 small">{{ $transaction->type }} · {{ number_format((float)$transaction->amount,2) }} {{ strtoupper($transaction->currency) }}<div class="text-secondary">{{ $transaction->created_at->format('Y-m-d H:i:s') }}</div></div>
            @empty
                <p class="text-secondary small">لا توجد معاملات مسجلة.</p>
            @endforelse
            <h3 class="h6 mt-3">Refund Operations</h3>
            @forelse($booking->payment->refundOperations as $operation)
                <div class="border rounded-3 p-2 mb-2 small">#{{ $operation->id }} · {{ $operation->status->value }} · {{ number_format($operation->amount_minor/100,2) }} {{ strtoupper($operation->currency) }}</div>
            @empty
                <p class="text-secondary small">لا توجد عمليات Refund.</p>
            @endforelse
        @endif
    </section></div>
</div>
@endsection
