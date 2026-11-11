@extends('layouts.public')

@section('title', (filled(trim((string) $space->title)) ? $space->title : 'مساحة بدون اسم #'.$space->id).' | '.config('app.name', 'Room Booking'))
@section('description', \Illuminate\Support\Str::limit($space->description ?: 'تفاصيل المساحة المتاحة.', 155))

@section('content')
    @php($mainImage = $space->getFirstMediaUrl('images'))
    <nav aria-label="مسار الصفحة" class="small mb-4">
        <a href="{{ route('spaces.index') }}" class="text-secondary">المساحات</a>
        <span class="text-secondary mx-2" aria-hidden="true">/</span>
        <span class="text-dark"><x-space-name :space="$space" /></span>
    </nav>

    <div class="row g-4 g-xl-5 align-items-start">
        <div class="col-lg-7">
            <div class="space-detail-image rounded-4 overflow-hidden shadow-sm">
                @if ($mainImage !== '')
                    <img src="{{ $mainImage }}" alt="صورة مساحة #{{ $space->id }}" onerror="this.remove()">
                @else
                    <div class="h-100 min-vh-50 d-grid place-items-center text-secondary p-5 text-center">
                        <div>
                            <div class="detail-icon mx-auto mb-3" aria-hidden="true">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>
                            </div>
                            <span>لا توجد صورة مضافة لهذه المساحة</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
            <span class="badge rounded-pill bg-success-subtle text-success-emphasis mb-3">متاحة</span>
            <h1 class="display-6 fw-bold mb-3"><x-space-name :space="$space" /></h1>
                <p class="text-secondary d-flex align-items-center gap-2 mb-4">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    <x-space-location :space="$space" />
                </p>

            @if ($space->description)
                <div class="text-secondary lh-lg mb-4">{{ $space->description }}</div>
            @endif

            @if ($space->categories->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 mb-4">
                    @foreach ($space->categories as $category)
                        <span class="badge rounded-pill text-bg-light text-brand px-3 py-2"><x-category-name :category="$category" /></span>
                    @endforeach
                </div>
            @endif

            <div class="bg-white border rounded-4 p-4 shadow-sm">
                <h2 class="h6 fw-bold mb-3">معلومات المساحة</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <span class="detail-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0M19 8a3 3 0 0 1 0 6m1 2a6 6 0 0 1 3 5"/></svg></span>
                        <div><div class="small text-secondary">السعة</div><div class="fw-semibold">{{ $space->capacity ? $space->capacity.' أشخاص' : 'غير محددة' }}</div></div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="detail-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                        <div><div class="small text-secondary">السعر</div><div class="fw-semibold">{{ number_format((float) $space->price_per_hour, 2) }} USD <span class="small text-secondary fw-normal">لكل ساعة</span></div></div>
                    </div>
                    @if ($space->opening_hour || $space->closing_hour)
                        <div class="d-flex align-items-center gap-3">
                            <span class="detail-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
                            <div><div class="small text-secondary">ساعات التشغيل</div><div class="fw-semibold">{{ $space->opening_hour ?: '—' }} – {{ $space->closing_hour ?: '—' }}</div></div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="d-grid d-sm-flex gap-2 mt-4">
                <a href="{{ route('spaces.bookings.create', $space->id) }}" class="btn btn-brand btn-lg px-4">احجز هذه المساحة</a>
                <a href="{{ route('spaces.index') }}" class="btn btn-outline-secondary btn-lg">جميع المساحات</a>
            </div>
            <p class="small text-secondary mt-3 mb-0">يتطلب إنشاء الحجز جلسة دخول بحساب عميل. يتم التحقق من التوفر مرة أخرى عند التأكيد.</p>
        </div>
    </div>
@endsection
