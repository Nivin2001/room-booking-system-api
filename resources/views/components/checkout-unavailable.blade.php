@props(['reason'])
@php
    $messages = [
        'booking_state' => 'لا يمكن بدء الدفع لأن حالة الحجز لا تسمح بالدفع الآن.',
        'missing_start_time' => 'لا يمكن بدء الدفع لعدم وجود موعد بداية محفوظ للحجز.',
        'booking_started' => 'انتهى موعد الحجز أو بدأ بالفعل، لذلك لم يعد الدفع متاحًا.',
        'booking_expired' => 'انتهت صلاحية الحجز قبل إتمام الدفع.',
        'checkout_unavailable' => 'الدفع غير متاح في هذه البيئة لأن مخطط Checkout غير مجهز.',
        'different_provider' => 'هذا الدفع مرتبط بمزوّد دفع آخر ولا يمكن تحويله إلى Stripe.',
        'payment_final' => 'تم حسم حالة الدفع بالفعل، لذلك لا يمكن بدء محاولة Checkout جديدة.',
        'checkout_attempt_unavailable' => 'توجد محاولة دفع سابقة غير قابلة لإعادة الاستخدام؛ يلزم حسم حالتها أولًا.',
        'payment_ineligible' => 'حالة سجل الدفع الحالي لا تسمح بمحاولة Checkout جديدة.',
    ];
@endphp
@if ($reason)
    <p {{ $attributes->class(['small', 'text-secondary', 'mb-0']) }} role="status">{{ $messages[$reason] ?? 'الدفع غير متاح لهذا الحجز حاليًا.' }}</p>
@endif
