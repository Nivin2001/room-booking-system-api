@extends('layouts.dashboard')
@section('title', 'المساحات المسندة | '.config('app.name', 'Room Booking'))
@section('dashboard-content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <p class="text-brand fw-semibold mb-1">المساحات المسندة إلى حسابك</p>
            <h1 class="h2 fw-bold mb-1">مساحاتي</h1>
            <p class="text-secondary mb-0">هذه الصفحة للعرض فقط. يتولى Admin إدارة إعدادات المساحات.</p>
        </div>
        <a href="{{ route('staff.bookings.index') }}" class="btn btn-outline-secondary">عرض الحجوزات</a>
    </div>

    @if ($spaces->isEmpty())
        <div class="bg-white border rounded-4 text-center p-5 text-secondary">لا توجد مساحات مسندة إلى حسابك حاليًا.</div>
    @else
        <div class="row g-3">
            @foreach ($spaces as $space)
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="bg-white border rounded-4 p-4 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <h2 class="h5 fw-bold mb-0">{{ filled($space->title) ? $space->title : 'مساحة بدون اسم #'.$space->id }}</h2>
                            <x-status-badge :status="$space->status" />
                        </div>
                        <p class="text-secondary mb-3">{{ $space->location ?: 'الموقع غير محدد' }}</p>
                        <dl class="row small mb-0">
                            <dt class="col-6 text-secondary">السعة</dt><dd class="col-6 text-end">{{ $space->capacity ?? '—' }}</dd>
                            <dt class="col-6 text-secondary">السعر لكل ساعة</dt><dd class="col-6 text-end">{{ number_format((float) $space->price_per_hour, 2) }}</dd>
                        </dl>
                        @if ($space->categories->isNotEmpty())
                            <div class="d-flex flex-wrap gap-2 mt-3" aria-label="التصنيفات">
                                @foreach ($space->categories as $category)
                                    <span class="badge rounded-pill text-bg-light border">{{ filled($category->name) ? $category->name : 'تصنيف غير مسمى' }}</span>
                                @endforeach
                            </div>
                        @endif
                    </article>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $spaces->links() }}</div>
    @endif
@endsection
