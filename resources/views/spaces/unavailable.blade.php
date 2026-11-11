@extends('layouts.public')

@section('title', 'تعذر تحميل المساحات | '.config('app.name', 'Room Booking'))

@section('content')
    <section class="bg-white border rounded-4 text-center p-5 my-5">
        <div class="detail-icon mx-auto mb-3" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 3.9 2.7 17.2A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        </div>
        <h1 class="h4 fw-bold">تعذر تحميل المساحات الآن</h1>
        <p class="text-secondary mb-4">لم نتمكن من جلب البيانات. حاول مرة أخرى بعد قليل.</p>
        <a class="btn btn-brand rounded-pill px-4" href="{{ route('spaces.index') }}">إعادة المحاولة</a>
    </section>
@endsection
