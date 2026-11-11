@extends('layouts.public')

@section('title', 'إنشاء حساب عميل | '.config('app.name', 'Room Booking'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">
            <section class="bg-white border rounded-4 shadow-sm p-4 p-md-5">
                <div class="text-center mb-4">
                    <span class="brand-mark mb-3">R</span>
                    <h1 class="h2 fw-bold mt-3">إنشاء حساب عميل</h1>
                    <p class="text-secondary mb-0">أنشئ حسابك لتتمكن من حجز المساحات.</p>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <form method="POST" action="{{ route('register.store') }}" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">الاسم</label>
                        <input id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" maxlength="255" required autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">البريد الإلكتروني</label>
                        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">كلمة المرور</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" class="form-control @error('password') is-invalid @enderror" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label fw-semibold">تأكيد كلمة المرور</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-brand btn-lg w-100">إنشاء الحساب</button>
                </form>

                <p class="text-center text-secondary mt-4 mb-0">لديك حساب بالفعل؟ <a class="text-brand fw-semibold" href="{{ route('login') }}">سجّل الدخول</a></p>
            </section>
        </div>
    </div>
@endsection
