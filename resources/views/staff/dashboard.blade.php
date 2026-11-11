@extends('layouts.dashboard')
@section('title', 'لوحة الموظف | '.config('app.name', 'Room Booking'))
@section('dashboard-content')
    <div class="mb-4"><p class="text-brand fw-semibold mb-1">متابعة المساحات التابعة لك</p><h1 class="h2 fw-bold mb-1">لوحة الموظف</h1><p class="text-secondary mb-0">تعرض هذه اللوحة الحجوزات المرتبطة بالمساحات المسندة إليك.</p></div>
    <div class="row g-3 mb-4">
        @foreach ([['pending_payment', 'بانتظار الدفع', $counts['pending_payment'], 'warning'], ['pending', 'قيد الموافقة', $counts['pending'], 'warning'], ['confirmed', 'مؤكدة', $counts['confirmed'], 'success'], ['rejected', 'مرفوضة', $counts['rejected'], 'danger'], ['upcoming', 'قادمة', $counts['upcoming'], 'primary']] as [$key, $label, $value, $color])
            <div class="col-6 col-xl"><div class="stat-card p-3 p-lg-4" data-stat="{{ $key }}" data-count="{{ $value }}"><div class="small text-secondary">{{ $label }}</div><div class="display-6 fw-bold text-{{ $color }}">{{ number_format($value) }}</div></div></div>
        @endforeach
    </div>
    <section class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">المساحات المسندة إليك</h2><a href="{{ route('staff.spaces.index') }}">عرض كل المساحات</a></div>
        @if ($assignedSpaces->isEmpty())
            <div class="bg-white border rounded-4 text-center text-secondary p-4">لا توجد مساحات مسندة إلى حسابك حاليًا.</div>
        @else
            <div class="row g-3">
                @foreach ($assignedSpaces as $space)
                    <div class="col-12 col-md-6 col-xl-3"><article class="stat-card p-3 h-100"><h3 class="h6 fw-bold mb-2">{{ filled($space->title) ? $space->title : 'مساحة بدون اسم #'.$space->id }}</h3><div class="small text-secondary">{{ $space->location ?: 'الموقع غير محدد' }}</div><div class="small mt-2">{{ number_format((float) $space->price_per_hour, 2) }} / ساعة · سعة {{ $space->capacity ?? '—' }}</div><div class="mt-3"><x-status-badge :status="$space->status" /></div></article></div>
                @endforeach
            </div>
        @endif
    </section>
    <section class="bg-white border rounded-4 p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">الحجوزات القادمة</h2><a href="{{ route('staff.bookings.index', ['status' => 'pending_staff_approval']) }}">إدارة الحجوزات</a></div>
        @if ($upcoming->isEmpty())<div class="text-center text-secondary py-5">لا توجد حجوزات مؤكدة قادمة.</div>
        @else
            <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>المساحة</th><th>العميل</th><th>الموعد</th><th>الحالة</th><th></th></tr></thead><tbody>
                @foreach ($upcoming as $booking)<tr><td>{{ filled($booking->space?->title) ? $booking->space->title : 'مساحة بدون اسم #'.$booking->space_id }}</td><td>{{ filled($booking->user?->name) ? $booking->user->name : 'عميل بدون اسم #'.$booking->user_id }}</td><td>{{ $booking->start_time->format('Y-m-d H:i') }}</td><td><x-status-badge :status="$booking->status" /></td><td><a class="btn btn-sm btn-outline-secondary" href="{{ route('staff.bookings.show', $booking) }}">التفاصيل</a></td></tr>@endforeach
            </tbody></table></div>
        @endif
    </section>
@endsection
