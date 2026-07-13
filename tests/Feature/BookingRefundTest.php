<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BookingRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_for_cancel_confirmed_booking_refund()
    {
        $this->markTestSkipped('Integration test requires Stripe and DB setup. Implement when CI has credentials.');
    }
}
