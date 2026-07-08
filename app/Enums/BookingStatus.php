<?php
namespace App\Enums;

    enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case PaymentFailed = 'payment_failed';
    case PendingStaffApproval = 'pending_staff_approval';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

public function canTransitionTo(self $newStatus): bool
{
    return match ($this) {

        self::PendingPayment => in_array($newStatus, [
            self::PendingStaffApproval,
            self::PaymentFailed,
            self::Cancelled,
            self::Expired,
        ]),

        self::PaymentFailed => in_array($newStatus, [
            self::PendingPayment,
            self::Cancelled,
        ]),

        self::PendingStaffApproval => in_array($newStatus, [
            self::Confirmed,
            self::Rejected,
        ]),

        self::Confirmed => in_array($newStatus, [
            self::Cancelled,
        ]),

        self::Rejected,
        self::Cancelled,
        self::Expired => false,
    };
}
}


