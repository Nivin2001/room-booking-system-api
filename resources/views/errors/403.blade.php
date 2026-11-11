@extends('layouts.public')
@section('title', 'غير مصرح بالوصول')
@section('content')
    <section class="bg-white border rounded-4 p-4 p-lg-5 text-center">
        <p class="text-brand fw-bold mb-2">403</p>
        <h1 class="h3 fw-bold">غير مصرح لك بالوصول إلى هذه الصفحة</h1>
        <p class="text-secondary mb-4">لا يملك حسابك الصلاحية المطلوبة لعرض هذا المحتوى.</p>
        <a href="{{ route('spaces.index') }}" class="btn btn-brand">العودة إلى الصفحة الرئيسية</a>
    </section>
@endsection
