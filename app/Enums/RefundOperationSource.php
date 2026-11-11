<?php

namespace App\Enums;

enum RefundOperationSource: string
{
    case CustomerCancel = 'customer_cancel';
    case StaffReject = 'staff_reject';
}
