@extends('layouts.dashboard')
@section('title', 'لوحة الإدارة | '.config('app.name', 'Room Booking'))
@section('dashboard-content')
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="text-brand fw-semibold mb-1">إدارة النظام</p><h1 class="h2 fw-bold mb-1">نظرة عامة</h1><p class="text-secondary mb-0">ملخص تشغيلي للبيانات المسجلة حاليًا.</p></div><a href="{{ route('admin.bookings.index') }}" class="btn btn-brand">مراجعة الحجوزات</a></div>
    <div class="row g-3 mb-4">
        @foreach ([['المستخدمون', $stats['users'], 'primary'], ['المساحات', $stats['spaces'], 'success'], ['كل الحجوزات', $stats['bookings'], 'dark'], ['بانتظار الإجراء', $stats['pending'], 'warning'], ['حجوزات مؤكدة', $stats['confirmed'], 'info'], ['مدفوعات مكتملة', $stats['paid'], 'success'], ['استردادات', $stats['refunds'], 'secondary'], ['تعارضات دفع للمراجعة', $stats['conflicts'], 'danger']] as [$label, $value, $color])
            <div class="col-6 col-xl-3"><div class="stat-card p-3 p-lg-4"><div class="small text-secondary">{{ $label }}</div><div class="h2 fw-bold text-{{ $color }} mb-0">{{ number_format($value) }}</div></div></div>
        @endforeach
        @foreach($stats['revenueByCurrency'] as $revenue)
            <div class="col-6 col-xl-3"><div class="stat-card p-3 p-lg-4"><div class="small text-secondary">المدفوعات المكتملة · {{ strtoupper($revenue->currency) }}</div><div class="h4 fw-bold mb-0">{{ number_format((float) $revenue->total, 2) }}</div></div></div>
        @endforeach
    </div>
    <section class="bg-white border rounded-4 p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">أحدث الحجوزات</h2><a href="{{ route('admin.bookings.index') }}">عرض الكل</a></div>
        @if($recentBookings->isEmpty())<div class="text-center text-secondary py-5">لا توجد حجوزات مسجلة.</div>@else<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>الرقم</th><th>العميل</th><th>المساحة</th><th>الموعد</th><th>الحالة</th><th>الدفع</th></tr></thead><tbody>@foreach($recentBookings as $booking)<tr><td>#{{ $booking->id }}</td><td>{{ $booking->user?->name ?: 'عميل بدون اسم #'.$booking->user_id }}</td><td>@if($booking->space)<x-space-name :space="$booking->space" />@else مساحة غير متاحة @endif</td><td>{{ $booking->start_time?->format('Y-m-d H:i') ?? '—' }}</td><td><x-status-badge :status="$booking->status" /></td><td>@if($booking->payment)<x-status-badge :status="$booking->payment->status" kind="payment" />@else—@endif</td></tr>@endforeach</tbody></table></div>@endif
    </section>
@endsection
