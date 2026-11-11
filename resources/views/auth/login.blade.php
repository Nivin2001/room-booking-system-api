@extends('layouts.public')

@section('title', 'تسجيل الدخول | '.config('app.name', 'Room Booking'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">
            <section class="bg-white border rounded-4 shadow-sm p-4 p-md-5">
                <div class="text-center mb-4">
                    <span class="brand-mark mb-3">R</span>
                    <h1 class="h2 fw-bold mt-3">مرحبًا بعودتك</h1>
                    <p class="text-secondary mb-0">سجّل الدخول بحسابك للمتابعة إلى المساحات والصفحات المصرح بها.</p>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">البريد الإلكتروني</label>
                        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">كلمة المرور</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" class="form-control @error('password') is-invalid @enderror" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check mb-4">
                        <input id="remember" name="remember" type="checkbox" value="1" class="form-check-input">
                        <label for="remember" class="form-check-label">تذكرني على هذا الجهاز</label>
                    </div>
                    <button type="submit" class="btn btn-brand btn-lg w-100">تسجيل الدخول</button>
                </form>

                <p class="text-center text-secondary mt-4 mb-0">ليس لديك حساب؟ <a class="text-brand fw-semibold" href="{{ route('register') }}">أنشئ حساب عميل</a></p>
            </section>
        </div>
    </div>
@endsection
