<?php

namespace App\Http\Controllers;

use App\Services\MidtransSignatureVerifier;
use App\Services\PaymentStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class MidtransWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        MidtransSignatureVerifier $signatureVerifier,
        PaymentStateService $payments,
    ): JsonResponse {
        $rawBody = $request->getContent();

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        if (! is_array($payload) || ! $signatureVerifier->verify($payload)) {
            return response()->json(['message' => 'Invalid notification signature.'], 401);
        }

        $result = $payments->handleNotification($payload, $rawBody);

        if ($result === 'rejected') {
            return response()->json(['message' => 'Notification amount or currency does not match the payment attempt.'], 422);
        }

        return response()->json(['status' => $result], 200);
    }
}
