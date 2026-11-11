<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\stripe\StripeWebhookService;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeWebhookService $webhookService
    ) {}

    public function handle(Request $request)
    {
        return $this->webhookService->handle($request);
    }
}
