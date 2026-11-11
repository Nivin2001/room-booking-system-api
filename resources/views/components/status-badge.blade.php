@props(['status', 'kind' => 'booking'])
@php
    $value = is_object($status) && isset($status->value) ? $status->value : (string) $status;
    $bookingLabels = [
        'pending_payment' => 'بانتظار الدفع', 'payment_failed' => 'تعذر الدفع',
        'pending_staff_approval' => 'بانتظار موافقة الموظف', 'confirmed' => 'مؤكد',
        'rejected' => 'مرفوض', 'cancelled' => 'ملغي', 'expired' => 'منتهي',
    ];
    $paymentLabels = [
        'pending' => 'بانتظار الدفع', 'processing' => 'قيد المعالجة', 'paid' => 'مدفوع',
        'failed' => 'فشل الدفع', 'refunded' => 'مسترد', 'partially_refunded' => 'مسترد جزئيًا',
        'disputed' => 'متنازع عليه',
    ];
    $classes = [
        'confirmed' => 'text-bg-success', 'paid' => 'text-bg-success', 'succeeded' => 'text-bg-success',
        'pending_payment' => 'text-bg-warning', 'pending_staff_approval' => 'text-bg-warning',
        'pending' => 'text-bg-warning', 'processing' => 'text-bg-info', 'unknown' => 'text-bg-info',
        'payment_failed' => 'text-bg-danger', 'failed' => 'text-bg-danger', 'rejected' => 'text-bg-danger',
        'refunded' => 'text-bg-secondary', 'partially_refunded' => 'text-bg-secondary',
        'cancelled' => 'text-bg-secondary', 'expired' => 'text-bg-secondary', 'disputed' => 'text-bg-dark',
    ];
    $labels = $kind === 'payment' ? $paymentLabels : $bookingLabels;
@endphp
<span {{ $attributes->class(['badge', 'rounded-pill', $classes[$value] ?? 'text-bg-light text-dark border']) }}>{{ $labels[$value] ?? str_replace('_', ' ', $value) }}</span>
