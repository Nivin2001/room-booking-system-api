@extends('layouts.public')

@section('content')
    @php($role = auth('web')->user()?->role)
    <div class="row g-4 align-items-start">
        <aside class="col-lg-3 col-xl-2">
            <nav class="dashboard-sidebar p-3" aria-label="التنقل الإداري">
                <div class="small text-secondary fw-semibold px-2 mb-2">{{ $role === 'admin' ? 'الإدارة' : 'مساحة الموظف' }}</div>
                @if ($role === 'staff')
                    <a class="dashboard-link {{ request()->routeIs('staff.dashboard') ? 'active' : '' }}" href="{{ route('staff.dashboard') }}">نظرة عامة</a>
                    <a class="dashboard-link {{ request()->routeIs('staff.spaces.*') ? 'active' : '' }}" href="{{ route('staff.spaces.index') }}">مساحاتي المسندة</a>
                    <a class="dashboard-link {{ request()->routeIs('staff.bookings.*') ? 'active' : '' }}" href="{{ route('staff.bookings.index') }}">الحجوزات</a>
                @elseif ($role === 'admin')
                    <a class="dashboard-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">نظرة عامة</a>
                    <a class="dashboard-link {{ request()->routeIs('admin.spaces.*') ? 'active' : '' }}" href="{{ route('admin.spaces.index') }}">المساحات</a>
                    <a class="dashboard-link {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}" href="{{ route('admin.categories.index') }}">التصنيفات</a>
                    <a class="dashboard-link {{ request()->routeIs('admin.bookings.*') ? 'active' : '' }}" href="{{ route('admin.bookings.index') }}">الحجوزات</a>
                    <a class="dashboard-link {{ request()->routeIs('admin.payments.*') ? 'active' : '' }}" href="{{ route('admin.payments.index') }}">المدفوعات والمراجعة</a>
                @endif
                <hr>
                <a class="dashboard-link" href="{{ route('spaces.index') }}">عرض الموقع</a>
            </nav>
        </aside>
        <section class="col-lg-9 col-xl-10">
            @if (session('auth_notice'))<div class="alert alert-success" role="status">{{ session('auth_notice') }}</div>@endif
            @if (session('payment_notice'))<div class="alert alert-info" role="status">{{ session('payment_notice') }}</div>@endif
            @if ($errors->any())
                <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('dashboard-content')
        </section>
    </div>
@endsection
