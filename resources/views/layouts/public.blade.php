<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'استكشف مساحات الاجتماعات والعمل المتاحة للحجز.')">
    <title>@yield('title', config('app.name', 'Room Booking'))</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.rtl.min.css"
          rel="stylesheet"
          integrity="sha384-CfCrinSRH2IR6a4e6fy2q6ioOX7O6Mtm1L9vRvFZ1trBncWmMePhzvafv7oIcWiW"
          crossorigin="anonymous">
    <style>
        :root { --ink: #172b3a; --muted: #637482; --brand: #176b67; --brand-dark: #105451; --sand: #f4f7f5; }
        body { background: var(--sand); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { text-decoration: none; }
        .site-nav { background: rgba(255,255,255,.94); }
        .brand-mark { display: inline-grid; place-items: center; width: 2.45rem; height: 2.45rem; border-radius: .85rem; background: var(--brand); color: white; font-weight: 800; }
        .text-brand { color: var(--brand) !important; }
        .btn-brand { --bs-btn-bg: var(--brand); --bs-btn-border-color: var(--brand); --bs-btn-hover-bg: var(--brand-dark); --bs-btn-hover-border-color: var(--brand-dark); --bs-btn-color: #fff; --bs-btn-hover-color: #fff; }
        .hero-panel { position: relative; overflow: hidden; border-radius: 1.5rem; color: white; background: linear-gradient(120deg, #123f49, #176b67 58%, #86a99b); }
        .hero-panel::after { content: ""; position: absolute; width: 19rem; height: 19rem; border: 1px solid rgba(255,255,255,.18); border-radius: 50%; left: -5rem; top: -10rem; box-shadow: 0 0 0 2rem rgba(255,255,255,.05), 0 0 0 4rem rgba(255,255,255,.035); }
        .hero-content { position: relative; z-index: 1; }
        .space-card { overflow: hidden; border: 1px solid #e8eeeb; border-radius: 1.15rem; transition: transform .2s ease, box-shadow .2s ease; }
        .space-card:hover { transform: translateY(-4px); box-shadow: 0 1rem 2rem rgba(23,43,58,.09) !important; }
        .space-image { position: relative; height: 13rem; overflow: hidden; background: linear-gradient(135deg, #d8e7e1, #eff3ef); }
        .space-image img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .image-placeholder { position: absolute; inset: 0; display: grid; place-items: center; color: #648078; }
        .space-detail-image { min-height: 22rem; background: linear-gradient(135deg, #d8e7e1, #eff3ef); }
        .space-detail-image img { width: 100%; max-height: 34rem; object-fit: cover; }
        .min-vh-50 { min-height: 22rem; }
        .place-items-center { place-items: center; }
        .detail-icon { width: 2.5rem; height: 2.5rem; display: grid; place-items: center; border-radius: .8rem; background: #edf5f1; color: var(--brand); }
        .footer-link { color: var(--muted); }
        .footer-link:hover { color: var(--brand); }
        .dashboard-sidebar { border-radius: 1rem; background: #fff; border: 1px solid #e5ece8; }
        .dashboard-link { display: flex; align-items: center; gap: .65rem; color: #536570; padding: .7rem .85rem; border-radius: .7rem; }
        .dashboard-link:hover, .dashboard-link.active { color: var(--brand-dark); background: #eaf4ef; }
        .stat-card { border: 1px solid #e5ece8; border-radius: 1rem; background: #fff; height: 100%; }
        @media (max-width: 575.98px) { .hero-panel { border-radius: 1rem; } .space-image { height: 12rem; } }
    </style>
    @stack('head')
</head>
<body>
    <nav class="navbar site-nav border-bottom py-3">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="d-flex align-items-center gap-2 text-dark fw-bold" href="{{ route('spaces.index') }}" aria-label="الصفحة الرئيسية">
                <span class="brand-mark">R</span>
                <span>{{ config('app.name', 'Room Booking') }}</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="{{ route('spaces.index') }}">استكشف المساحات</a>
                @auth('web')
                    @if (auth('web')->user()->role === 'customer')
                        <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="{{ route('customer.bookings.index') }}">حجوزاتي</a>
                    @elseif (auth('web')->user()->role === 'staff')
                        <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="{{ route('staff.dashboard') }}">لوحة الموظف</a>
                    @elseif (auth('web')->user()->role === 'admin')
                        <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="{{ route('admin.dashboard') }}">لوحة الإدارة</a>
                    @endif
                    <span class="small text-secondary d-none d-md-inline">مرحبًا، {{ auth('web')->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3">تسجيل الخروج</button>
                    </form>
                @else
                    <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="{{ route('login') }}">دخول</a>
                    <a class="btn btn-brand btn-sm rounded-pill px-3" href="{{ route('register') }}">إنشاء حساب</a>
                @endauth
            </div>
        </div>
    </nav>

    <main class="container py-4 py-lg-5">
        @if (session('auth_notice'))
            <div class="alert alert-success" role="status">{{ session('auth_notice') }}</div>
        @endif
        @yield('content')
    </main>

    <footer class="border-top bg-white mt-5 py-4">
        <div class="container d-flex flex-column flex-sm-row justify-content-between gap-2 small text-secondary">
            <span>مساحات عملية لاجتماعاتك وأعمالك.</span>
            <a class="footer-link" href="{{ route('spaces.index') }}">العودة إلى المساحات</a>
        </div>
    </footer>
</body>
</html>
