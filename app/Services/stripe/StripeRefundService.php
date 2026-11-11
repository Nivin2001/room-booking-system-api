<?php

namespace App\Services\stripe;

use App\Models\Payment;
use App\Models\RefundOperation;
use Stripe\Stripe;
use Stripe\Refund;
use UnexpectedValueException;

class StripeRefundService
{
    public function refund(Payment $payment, RefundOperation $operation)
    {
        $this->configureStripe();

        $parameters = [
            'payment_intent' => $payment->payment_intent_id,
            'amount' => $operation->amount_minor,
            'metadata' => ['refund_operation_id' => (string) $operation->id],
        ];

        if ((int) $operation->payment_id !== (int) $payment->id
            || blank($payment->payment_intent_id)
            || blank($operation->idempotency_key)
            || (int) $operation->amount_minor <= 0) {
            throw new \InvalidArgumentException('A valid refund operation is required to submit a Stripe refund.');
        }

        return Refund::create($parameters, [
            'idempotency_key' => $operation->idempotency_key,
        ]);
    }

    public function retrieveRefund(string $refundId)
    {
        $this->configureStripe();

        return Refund::retrieve($refundId);
    }

    /** @return array<int, object> */
    public function listRefundsForPaymentIntent(string $paymentIntentId): array
    {
        $this->configureStripe();

        $refunds = [];
        $parameters = [
            'payment_intent' => $paymentIntentId,
            'limit' => 100,
        ];
        $cursors = [];

        do {
            $page = Refund::all($parameters);
            $pageItems = $page->data ?? [];

            foreach ($pageItems as $refund) {
                $refunds[] = $refund;
            }

            if (! ($page->has_more ?? false)) {
                break;
            }

            $lastRefundId = data_get(end($pageItems), 'id');
            if (! is_string($lastRefundId) || $lastRefundId === '' || isset($cursors[$lastRefundId])) {
                throw new UnexpectedValueException('Stripe refund pagination did not provide a safe continuation cursor.');
            }

            $cursors[$lastRefundId] = true;
            $parameters['starting_after'] = $lastRefundId;
        } while (true);

        return $refunds;
    }

    private function configureStripe(): void
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }
}
