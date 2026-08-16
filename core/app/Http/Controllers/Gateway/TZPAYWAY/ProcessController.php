<?php

namespace App\Http\Controllers\Gateway\TZPAYWAY;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Gateway\PaymentController;
use App\Models\Deposit;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    /**
     * Process deposit request and redirect customer to TZPayWay Checkout
     *
     * @param object $deposit
     * @return string JSON response for Viserlab deposit handler
     */
    public static function process($deposit)
    {
        $gateway = $deposit->gateway;
        $params = json_decode(json_encode($gateway->gateway_parameters ?? $gateway->parameters ?? []), true);

        // Retrieve API Key and API Base URL
        $apiKey = $params['api_key']['value'] ?? ($params['api_key'] ?? '');
        $apiUrl = rtrim($params['api_url']['value'] ?? ($params['api_url'] ?? 'https://tzpayway.com'), '/');

        if (empty($apiKey)) {
            return json_encode([
                'error' => 'true',
                'message' => 'TZPayWay API Key is missing. Please configure it in your Admin Panel.'
            ]);
        }

        // Customer details
        $user = $deposit->user ?? null;
        $customerName = $user ? ($user->fullname ?? $user->username ?? 'Customer') : 'Customer';
        $customerEmail = $user ? ($user->email ?? 'customer@example.com') : 'customer@example.com';
        $customerMobile = $user ? ($user->mobile ?? '') : '';

        // Prepare request payload for TZPayWay API V1
        $postData = [
            'amount' => (float) round($deposit->final_amo ?? $deposit->final_amount ?? $deposit->amount, 2),
            'currency' => $deposit->method_currency ?? ($deposit->currency ?? 'BDT'),
            'user_data' => [
                'track' => $deposit->trx,
                'deposit_id' => $deposit->id,
                'user_id' => $deposit->user_id ?? null,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_mobile' => $customerMobile,
            ],
            'success_url' => function_exists('gatewayRedirectUrl') ? route(gatewayRedirectUrl(true)) : (url('/user/deposit/history')),
            'cancel_url' => function_exists('gatewayRedirectUrl') ? route(gatewayRedirectUrl()) : (url('/user/deposit')),
            'webhook_url' => route('ipn.' . ($gateway->alias ?? 'TZPAYWAY')),
        ];

        // Send POST request to TZPayWay API V1
        $endpoint = $apiUrl . '/api/v1/payment/create';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return json_encode([
                'error' => 'true',
                'message' => 'Connection error: ' . $curlError
            ]);
        }

        $result = json_decode($response);

        if ($httpCode === 200 && isset($result->success) && $result->success && !empty($result->checkout_url)) {
            // Save transaction info in deposit detail
            if (isset($result->transaction->trx_id)) {
                $deposit->detail = json_encode([
                    'tz_trx_id' => $result->transaction->trx_id,
                    'created_at' => now()->toDateTimeString()
                ]);
                $deposit->save();
            }

            $send['redirect'] = 'TRUE';
            $send['redirect_url'] = $result->checkout_url;
        } else {
            $errorMessage = $result->error ?? ($result->message ?? 'Unable to create payment session with TZPayWay.');
            return json_encode([
                'error' => 'true',
                'message' => is_string($errorMessage) ? $errorMessage : json_encode($errorMessage)
            ]);
        }

        return json_encode($send);
    }

    /**
     * Handle Instant Payment Notification (IPN) Webhook from TZPayWay
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ipn(Request $request)
    {
        $rawContent = $request->getContent();
        $payload = json_decode($rawContent, true);

        if (!$payload) {
            $payload = $request->all();
        }

        // Get track number / TRX reference
        $track = $payload['user_data']['track'] ?? ($payload['track'] ?? ($payload['trx_id'] ?? null));
        $trxId = $payload['trx_id'] ?? null;
        $status = strtolower($payload['status'] ?? '');

        if (!$track && !$trxId) {
            return response()->json(['error' => 'Invalid webhook data: missing transaction track or ID'], 400);
        }

        // Find deposit by transaction reference or matching detail
        $deposit = null;
        if ($track) {
            $deposit = Deposit::where('trx', $track)->orderBy('id', 'DESC')->first();
        }
        if (!$deposit && $trxId) {
            $deposit = Deposit::where('detail', 'like', '%' . $trxId . '%')->orderBy('id', 'DESC')->first();
        }

        if (!$deposit) {
            return response()->json(['error' => 'Deposit record not found for transaction: ' . ($track ?: $trxId)], 404);
        }

        // If already completed/processed, acknowledge immediately
        if ($deposit->status == 1) {
            return response()->json(['status' => 'success', 'message' => 'Transaction already completed'], 200);
        }

        // Gateway parameters for direct verification if needed
        $gateway = $deposit->gateway;
        $params = json_decode(json_encode($gateway->gateway_parameters ?? $gateway->parameters ?? []), true);
        $apiKey = $params['api_key']['value'] ?? ($params['api_key'] ?? '');
        $apiUrl = rtrim($params['api_url']['value'] ?? ($params['api_url'] ?? 'https://tzpayway.com'), '/');

        // Check if status is completed
        $isCompleted = in_array($status, ['completed', 'paid', 'success']);

        if (!$isCompleted && $trxId && !empty($apiKey)) {
            // Verify status directly with TZPayWay API
            $verifyEndpoint = $apiUrl . '/api/v1/payment/status/' . $trxId;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $verifyEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'X-API-KEY: ' . $apiKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $verifyResponse = curl_exec($ch);
            curl_close($ch);

            $verifyData = json_decode($verifyResponse, true);
            if (isset($verifyData['data']['status']) && in_array(strtolower($verifyData['data']['status']), ['completed', 'paid', 'success'])) {
                $isCompleted = true;
            }
        }

        if ($isCompleted) {
            $deposit->detail = json_encode($payload);
            $deposit->save();

            // Update user balance and complete deposit
            PaymentController::userDataUpdate($deposit);

            return response()->json([
                'status' => 'success',
                'message' => 'Payment confirmed and credited successfully'
            ], 200);
        }

        return response()->json([
            'status' => 'ignored',
            'message' => 'Payment status is ' . ($status ?: 'pending')
        ], 200);
    }
}
