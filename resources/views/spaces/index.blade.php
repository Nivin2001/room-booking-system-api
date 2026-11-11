@extends('layouts.public')

@section('title', 'استكشف المساحات | '.config('app.name', 'Room Booking'))
@section('description', 'اعثر على مساحة مناسبة لاجتماعك أو يوم عملك.')

@section('content')
    @if (session('booking_notice'))
        <div class="alert alert-info" role="status">{{ session('booking_notice') }}</div>
    @endif
    <section class="hero-panel p-4 p-md-5 mb-5 shadow-sm">
        <div class="hero-content row align-items-center g-4">
            <div class="col-lg-7">
                <span class="badge rounded-pill text-bg-light text-brand mb-3 px-3 py-2">مساحات متاحة الآن</span>
                <h1 class="display-5 fw-bold mb-3">مساحتك المناسبة، <span class="d-block">على بُعد خطوة.</span></h1>
                <p class="lead mb-0 text-white-50">تصفّح المساحات المتاحة واختر المكان الذي يناسب اجتماعك أو يوم عملك.</p>
            </div>
            <div class="col-lg-5">
                <form action="{{ route('spaces.index') }}" method="GET" class="bg-white rounded-4 p-2 shadow d-flex gap-2" role="search">
                    <label for="space-search" class="visually-hidden">ابحث عن مساحة</label>
                    <input id="space-search" name="q" value="{{ $search }}" class="form-control border-0 shadow-none" type="search" placeholder="ابحث بالاسم أو الموقع">
                    <button class="btn btn-brand rounded-3 px-4" type="submit">بحث</button>
                </form>
                <div class="small text-white-50 mt-3">{{ $spaces->total() }} مساحة متاحة</div>
            </div>
        </div>
    </section>

    <section aria-labelledby="spaces-heading">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
            <div>
                <p class="text-brand small fw-bold mb-1">اكتشف المكان</p>
                <h2 id="spaces-heading" class="h3 fw-bold mb-0">مساحات تناسب احتياجك</h2>
            </div>
            @if ($search !== '')
                <a href="{{ route('spaces.index') }}" class="link-secondary">مسح البحث</a>
            @endif
        </div>

        @if ($spaces->isEmpty())
            <div class="bg-white border rounded-4 text-center p-5">
                <div class="detail-icon mx-auto mb-3" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                </div>
                <h3 class="h5 fw-bold">لا توجد نتائج مطابقة</h3>
                <p class="text-secondary mb-0">جرّب كلمة بحث أخرى أو امسح البحث لعرض المساحات المتاحة.</p>
            </div>
        @else
            <div class="row g-4">
                @foreach ($spaces as $space)
                    @php($mainImage = $space->getFirstMediaUrl('images'))
                    <div class="col-sm-6 col-xl-4">
                        <article class="card space-card h-100 shadow-sm">
                            <a href="{{ route('spaces.show', $space) }}" class="space-image" aria-label="تفاصيل مساحة: {{ filled(trim((string) $space->title)) ? $space->title : 'بدون اسم رقم '.$space->id }}">
                                <div class="image-placeholder" aria-hidden="true">
                                    <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M8 9h.01M12 9h.01M16 9h.01"/></svg>
                                </div>
                                @if ($mainImage !== '')
                                    <img src="{{ $mainImage }}" alt="صورة مساحة: {{ filled(trim((string) $space->title)) ? $space->title : 'بدون اسم رقم '.$space->id }}" loading="lazy" onerror="this.remove()">
                                @endif
                                <span class="badge text-bg-light position-absolute top-0 start-0 m-3 rounded-pill">متاحة</span>
                            </a>
                            <div class="card-body p-4 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <h3 class="h5 fw-bold mb-0"><a class="text-dark" href="{{ route('spaces.show', $space) }}"><x-space-name :space="$space" /></a></h3>
                                    @if ($space->capacity)
                                        <span class="badge rounded-pill text-bg-light text-secondary">حتى {{ $space->capacity }}</span>
                                    @endif
                                </div>
                                    <p class="small text-secondary mb-3 d-flex align-items-center gap-1">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                        <x-space-location :space="$space" />
                                    </p>
                                @if ($space->description)
                                    <p class="text-secondary small flex-grow-1">{{ \Illuminate\Support\Str::limit($space->description, 110) }}</p>
                                @else
                                    <div class="flex-grow-1"></div>
                                @endif
                                @if ($space->categories->isNotEmpty())
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        @foreach ($space->categories->take(3) as $category)
                                            <span class="badge rounded-pill text-bg-light text-brand"><x-category-name :category="$category" /></span>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="d-flex align-items-center justify-content-between border-top pt-3 mt-auto">
                                    <div><span class="fw-bold fs-5">{{ number_format((float) $space->price_per_hour, 2) }}</span><span class="small text-secondary"> USD / ساعة</span></div>
                                    <a href="{{ route('spaces.show', $space) }}" class="btn btn-sm btn-outline-success rounded-pill px-3">التفاصيل <span aria-hidden="true">←</span></a>
                                </div>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>

            @if ($spaces->hasPages())
                <nav class="d-flex justify-content-center align-items-center gap-3 mt-5" aria-label="صفحات المساحات">
                    @if ($spaces->previousPageUrl())
                        <a class="btn btn-outline-secondary rounded-pill px-3" href="{{ $spaces->previousPageUrl() }}">السابق</a>
                    @endif
                    <span class="small text-secondary">صفحة {{ $spaces->currentPage() }} من {{ $spaces->lastPage() }}</span>
                    @if ($spaces->nextPageUrl())
                        <a class="btn btn-outline-secondary rounded-pill px-3" href="{{ $spaces->nextPageUrl() }}">التالي</a>
                    @endif
                </nav>
            @endif
        @endif
    </section>
@endsection
