<?php

namespace App\Services\Stripe;

use App\Enums\BookingStatus;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundCompleted;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\PaymentTransaction;
use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookService
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        // 🟢 1. Verify Stripe Signature (IMPORTANT)
        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $secret
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // 🟢 2. Route event type
        $webhookLog = WebhookLog::firstOrCreate(
            ['event_id' => $event->id],
            [
                'provider' => 'stripe',
                'event_type' => $event->type,
                'payload' => json_decode($payload, true) ?: [],
            ]
        );

        if ($webhookLog->processed_at) {
            return response()->json([
                'message' => 'Duplicate webhook event ignored',
                'event_id' => $event->id,
            ]);
        }

        $response = match ($event->type) {

            'checkout.session.completed' => $this->handleCheckoutCompleted($event),

            'payment_intent.payment_failed' => $this->handlePaymentFailed($event),

            'charge.refunded' => $this->handleRefund($event),

            default => response()->json(['message' => 'Event ignored']),
        };

        if ($response->getStatusCode() < 400) {
            $webhookLog->update([
                'processed_at' => now(),
            ]);
        }

        return $response;
    }

    // 🟢 PAYMENT SUCCESS
    private function handleCheckoutCompleted($event)
    {
        $session = $event->data->object;

        $paymentId = $session->metadata->payment_id;
        $bookingId = $session->metadata->booking_id;

        $payment = Payment::find($paymentId);
        $booking = Booking::find($bookingId);

        if (!$payment || !$booking) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $paymentIntentId = $session->payment_intent;

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_intent_id' => $paymentIntentId,
        ]);
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'type' => 'payment',
            'amount' => $payment->amount,
        ]);

        $booking->update([
            'status' => 'pending_staff_approval',
        ]);
        event(new PaymentSucceeded($booking, $payment));


        Log::info('Payment successful', [
            'payment_id' => $paymentId,
            'booking_id' => $bookingId,
        ]);

        return response()->json(['success' => true]);
    }

    // 🟡 PAYMENT FAILED
    private function handlePaymentFailed($event)
    {
        $intent = $event->data->object;

        $payment = Payment::where('payment_intent_id', $intent->id)
            ->orWhere('provider_reference', $intent->id)
            ->first();

        if (!$payment) return response()->json(['ignored' => true]);

        $payment->update([
            'status' => 'failed',
        ]);

        $payment->booking->update([
            'status' => 'payment_failed',
        ]);
        event(new PaymentFailed($payment->booking, $payment->id));

        return response()->json(['failed_handled' => true]);
    }

    // 🔴 REFUND

//     private function handleRefund($event)
// {
//     $object = $event->data->object;

//     // 1. منع التكرار
//     if (PaymentTransaction::where('stripe_event_id', $event->id)->exists()) {
//         return response()->json(['ignored' => true]);
//     }

//     $payment = Payment::where('payment_intent_id', $object->payment_intent)->first();

//     // 2. حماية null
//     if (!$payment || !$payment->booking) {
//         return response()->json(['ignored' => true]);
//     }

//     $booking = $payment->booking;

//     $payment->update([
//         'status' => 'refunded',
//     ]);

//     PaymentTransaction::create([
//         'payment_id' => $payment->id,
//         'type' => 'refund',
//         'amount' => $payment->amount,
//         'currency' => $payment->currency,
//         'stripe_event_id' => $event->id,
//         'meta' => [
//             'stripe_refund_id' => $object->id ?? null,
//         ],
//     ]);

//     // 3. تصحيح الحالة
//     $booking->update([
//         'status' => BookingStatus::Cancelled,
//     ]);

//     event(new RefundCompleted($payment, $booking));

//     return response()->json(['refund_handled' => true]);
// }
    private function handleRefund($event)
{
    $charge = $event->data->object;

    $paymentIntentId = $charge->payment_intent ?? null;
    $refundId = $charge->id ?? null;

    if (!$paymentIntentId) {
        return response()->json([
            'error' => 'Missing payment intent'
        ], 400);
    }

    // 🟢 1. منع التكرار (Idempotency key)
    $alreadyProcessed = PaymentTransaction::where('type', 'refund')
        ->where('meta->stripe_refund_id', $refundId)
        ->exists();

    if ($alreadyProcessed) {
        return response()->json([
            'status' => 'ignored',
            'message' => 'Refund already processed',
            'refund_id' => $refundId,
        ]);
    }

    // 🟢 2. جلب الدفع
    $payment = Payment::where('payment_intent_id', $paymentIntentId)->first();

    if (!$payment) {
        return response()->json([
            'ignored' => true,
            'message' => 'Payment not found'
        ]);
    }
    $payment->update([
        'status' => 'refunded',
    ]);

    // 🟢 4. تسجيل transaction (منع التكرار هنا كمان)
    PaymentTransaction::create([
        'payment_id' => $payment->id,
        'type' => 'refund',
        'amount' => $payment->amount,
        'currency' => $payment->currency,
        'stripe_event_id' => $event->id,
        'meta' => [
            'stripe_refund_id' => $refundId,
            'payment_intent' => $paymentIntentId,
        ],
    ]);

    // 🟢 5. تحديث الحجز (مع حماية null)
    if ($payment->booking) {
        $payment->booking->update([
            'status' => \App\Enums\BookingStatus::Rejected,
        ]);
    }

    // 🟢 6. Event (اختياري)
    event(new \App\Events\RefundCompleted(
        $payment,
        $payment->booking
    ));

    return response()->json([
        'success' => true,
        'refund_handled' => true
    ]);
}
}
