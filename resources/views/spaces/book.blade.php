@extends('layouts.public')

@section('title', 'حجز '.(filled(trim((string) $space->title)) ? $space->title : 'مساحة #'.$space->id).' | '.config('app.name', 'Room Booking'))

@section('content')
    <nav aria-label="مسار الصفحة" class="small mb-4">
        <a href="{{ route('spaces.index') }}" class="text-secondary">المساحات</a>
        <span class="text-secondary mx-2" aria-hidden="true">/</span>
        <a href="{{ route('spaces.show', $space->id) }}" class="text-secondary"><x-space-name :space="$space" /></a>
        <span class="text-secondary mx-2" aria-hidden="true">/</span>
        <span class="text-dark">الحجز</span>
    </nav>

    <div class="row g-4 justify-content-center">
        <div class="col-lg-8 col-xl-7">
            <div class="bg-white border rounded-4 shadow-sm p-4 p-md-5">
                <span class="badge rounded-pill bg-success-subtle text-success-emphasis mb-3"><x-space-name :space="$space" /></span>
                <h1 class="h2 fw-bold mb-2">اختر موعد الحجز</h1>
                <p class="text-secondary mb-4">السعر {{ number_format((float) $space->price_per_hour, 2) }} USD لكل ساعة. الفترات المعروضة مأخوذة من أوقات تشغيل المساحة وتوفر الحجوزات الحالية.</p>

                @if ($errors->any())
                    <div class="alert alert-danger" role="alert" aria-live="polite">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="GET" action="{{ route('spaces.bookings.create', $space->id) }}" class="mb-4">
                    <label for="date" class="form-label fw-semibold">تاريخ الحجز</label>
                    <div class="input-group">
                        <input id="date" name="date" type="date" class="form-control" value="{{ old('date', $date) }}" required>
                        <button class="btn btn-outline-secondary" type="submit">عرض المواعيد</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('spaces.bookings.store', $space->id) }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ old('date', $date) }}">
                    <label for="slot" class="form-label fw-semibold">الفترة الزمنية (ساعة واحدة)</label>
                    <select id="slot" name="slot" dir="ltr" class="form-select text-start @error('slot') is-invalid @enderror" required>
                        <option value="">اختر فترة متاحة</option>
                        @foreach ($slots as $slot)
                            @if ($slot['available'])
                                <option value="{{ $slot['time'] }}" data-total="{{ number_format((float) $slot['estimated_total'], 2, '.', '') }}" @selected(old('slot') === $slot['time'])>{{ $slot['time'] }} – {{ \Illuminate\Support\Carbon::parse($slot['end'])->format('H:i') }} · {{ number_format((float) $slot['estimated_total'], 2) }} {{ strtoupper($space->currency ?? 'USD') }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('slot')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    <div class="alert alert-success mt-3 mb-0" role="status" aria-live="polite">
                        <span class="text-secondary">الإجمالي المتوقع:</span>
                        <strong id="estimated-total">اختر فترة لعرض السعر</strong>
                    </div>

                    @if (collect($slots)->where('available', true)->isEmpty())
                        <div class="alert alert-warning mt-3 mb-0">لا توجد فترات متاحة في هذا التاريخ. اختر تاريخًا آخر.</div>
                    @endif

                    <div class="d-grid d-sm-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-brand btn-lg px-4" @disabled(collect($slots)->where('available', true)->isEmpty())>تأكيد إنشاء الحجز</button>
                        <a href="{{ route('spaces.show', $space->id) }}" class="btn btn-outline-secondary btn-lg">العودة للتفاصيل</a>
                    </div>
                </form>
                <p class="small text-secondary mt-4 mb-0">سيُعاد التحقق من التوفر عند الإرسال. إنشاء الحجز لا يعني إتمام الدفع؛ حالة الدفع تُعالج عبر التدفق الحالي المنفصل.</p>
            </div>
        </div>
    </div>
    <script>
        (() => {
            const slot = document.getElementById('slot');
            const total = document.getElementById('estimated-total');
            const currency = @json(strtoupper($space->currency ?? 'USD'));
            const updateTotal = () => {
                const amount = slot?.selectedOptions?.[0]?.dataset?.total;
                total.textContent = amount ? `${Number(amount).toFixed(2)} ${currency}` : 'اختر فترة لعرض السعر';
            };
            slot?.addEventListener('change', updateTotal);
            updateTotal();
        })();
    </script>
@endsection
