<?php

namespace App\Enums;

enum CheckoutAttemptStatus: string
{
    case Creating = 'creating';
    case Open = 'open';
    case Complete = 'complete';
    case Expired = 'expired';
    case Unknown = 'unknown';
    case Failed = 'failed';
    case Succeeded = 'succeeded';

    public function holdsPaymentSlot(): bool
    {
        return in_array($this, [
            self::Creating,
            self::Open,
            self::Complete,
            self::Unknown,
        ], true);
    }
}
