<?php

namespace App\Enums;

enum RefundOperationEvidence: string
{
    case ProviderResponse = 'provider_response';
    case Webhook = 'webhook';
    case Reconciliation = 'reconciliation';
    case NotSubmitted = 'not_submitted';
}
