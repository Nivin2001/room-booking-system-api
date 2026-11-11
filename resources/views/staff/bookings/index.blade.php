@extends('layouts.dashboard')
@section('title', 'إدارة الحجوزات | '.config('app.name', 'Room Booking'))
@section('dashboard-content')
    <div class="mb-4"><h1 class="h2 fw-bold mb-1">حجوزات مساحاتي</h1><p class="text-secondary mb-0">تظهر هنا حجوزات المساحات المسندة إلى حسابك فقط.</p></div>
    <form method="GET" class="bg-white border rounded-4 p-3 mb-3"><div class="row g-2"><div class="col-md-5"><label class="form-label" for="q">بحث</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="اسم المساحة أو العميل"></div><div class="col-md-4"><label class="form-label" for="status">الحالة</label><select id="status" name="status" class="form-select"><option value="">كل الحالات</option>@foreach ($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $statusLabels[$status->value] ?? $status->value }}</option>@endforeach</select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-brand w-100">تصفية</button></div></div></form>
    <div class="bg-white border rounded-4 p-3 p-lg-4">
        @if ($bookings->isEmpty())<div class="text-center text-secondary py-5">لا توجد حجوزات تطابق الفلتر.</div>
        @else<div class="table-responsive"><table class="table align-middle"><thead><tr><th>الحجز</th><th>العميل</th><th>المساحة</th><th>الموعد</th><th>الحالة</th><th>الدفع</th><th></th></tr></thead><tbody>
            @foreach ($bookings as $booking)<tr><td>#{{ $booking->id }}</td><td>{{ filled($booking->user?->name) ? $booking->user->name : 'عميل بدون اسم #'.$booking->user_id }}</td><td>{{ filled($booking->space?->title) ? $booking->space->title : 'مساحة بدون اسم #'.$booking->space_id }}</td><td>{{ $booking->start_time->format('Y-m-d H:i') }}</td><td><x-status-badge :status="$booking->status" /></td><td>@if($booking->payment)<x-status-badge :status="$booking->payment->status" kind="payment" />@else—@endif</td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('staff.bookings.show', $booking) }}">فتح</a></td></tr>@endforeach
        </tbody></table></div>{{ $bookings->links() }}@endif
    </div>
@endsection
