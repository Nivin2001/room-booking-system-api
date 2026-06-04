<?php

namespace App\Services\Stripe;

use App\Enums\BookingStatus;
use App\Models\Payment;
use App\Models\Booking;
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
        return match ($event->type) {

            'checkout.session.completed' => $this->handleCheckoutCompleted($event),

            'payment_intent.payment_failed' => $this->handlePaymentFailed($event),

            'charge.refunded' => $this->handleRefund($event),

            default => response()->json(['message' => 'Event ignored']),
        };
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

        $booking->update([
            'status' => 'pending_staff_approval',
        ]);

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

        $payment = Payment::where('provider_reference', $intent->id)->first();

        if (!$payment) return response()->json(['ignored' => true]);

        $payment->update([
            'status' => 'failed',
        ]);

        $payment->booking->update([
            'status' => 'payment_failed',
        ]);

        return response()->json(['failed_handled' => true]);
    }

    // 🔴 REFUND
    private function handleRefund($event)
    {
        $charge = $event->data->object;

        // $payment = Payment::where('provider_reference', $charge->payment_intent)->first();
        $payment = Payment::where(
            'payment_intent_id',
            $charge->payment_intent
        )->first();

        if (!$payment) return response()->json(['ignored' => true]);

        $payment->update([
            'status' => 'refunded',
        ]);
        $payment->booking->update([
            'status' => BookingStatus::Rejected,
        ]);

        // $payment->booking->update([
        //     'status' => 'cancelled',
        // ]);

        return response()->json(['refund_handled' => true]);
    }
}
