<?php

namespace App\Http\Controllers\Gateway\TZPAYWAY;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Gateway\PaymentController;
use App\Models\Deposit;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    /**
     * Parse and extract gateway parameters from various Viserlab deposit or gateway structures
     *
     * @param object $deposit
     * @return array
     */
    public static function getGatewayParams($deposit)
    {
        $rawParams = [];

        // 1. Check GatewayCurrency relation / property
        $gatewayCurrency = null;
        if (method_exists($deposit, 'gatewayCurrency')) {
            try {
                $gcRel = $deposit->gatewayCurrency();
                $gatewayCurrency = is_object($gcRel) && method_exists($gcRel, 'first') ? $gcRel->first() : $gcRel;
            } catch (\Throwable $e) {
                // Ignore relationship execution error
            }
        }
        if (!$gatewayCurrency && isset($deposit->gateway_currency)) {
            $gatewayCurrency = $deposit->gateway_currency;
        }

        if ($gatewayCurrency) {
            $gcParam = $gatewayCurrency->gateway_parameter ?? ($gatewayCurrency->parameters ?? ($gatewayCurrency->gateway_parameters ?? null));
            if ($gcParam) {
                $parsed = self::parseJsonData($gcParam);
                if (is_array($parsed)) {
                    $rawParams = array_merge($rawParams, $parsed);
                }
            }
        }

        // 2. Check Gateway relation / property
        $gateway = null;
        if (isset($deposit->gateway)) {
            $gateway = $deposit->gateway;
            if (is_object($gateway) && method_exists($gateway, 'first')) {
                try {
                    $firstGw = $gateway->first();
                    if ($firstGw) {
                        $gateway = $firstGw;
                    }
                } catch (\Throwable $e) {}
            }
        } elseif (method_exists($deposit, 'gateway')) {
            try {
                $gwRel = $deposit->gateway();
                $gateway = is_object($gwRel) && method_exists($gwRel, 'first') ? $gwRel->first() : $gwRel;
            } catch (\Throwable $e) {}
        }

        // 3. Fallback direct DB query if Gateway model exists
        if (!$gateway && class_exists('App\Models\Gateway')) {
            try {
                $gateway = \App\Models\Gateway::where('code', $deposit->method_code ?? 0)
                    ->orWhere('alias', 'TZPAYWAY')
                    ->orWhere('alias', 'tzpayway')
                    ->first();
            } catch (\Throwable $e) {}
        }

        if ($gateway) {
            $gwParam = $gateway->gateway_parameters ?? ($gateway->parameters ?? ($gateway->gateway_parameter ?? null));
            if ($gwParam) {
                $parsed = self::parseJsonData($gwParam);
                if (is_array($parsed)) {
                    // Currency-specific parameters take priority over global template
                    $rawParams = array_merge($parsed, $rawParams);
                }
            }
        }

        // 4. Fallback direct query on GatewayCurrency
        if (empty($rawParams) && class_exists('App\Models\GatewayCurrency')) {
            try {
                $gc = \App\Models\GatewayCurrency::where('method_code', $deposit->method_code ?? 0)
                    ->orWhere('gateway_alias', 'TZPAYWAY')
                    ->orWhere('gateway_alias', 'tzpayway')
                    ->first();
                if ($gc) {
                    $gcParam = $gc->gateway_parameter ?? ($gc->parameters ?? null);
                    if ($gcParam) {
                        $parsed = self::parseJsonData($gcParam);
                        if (is_array($parsed)) {
                            $rawParams = array_merge($rawParams, $parsed);
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        // Normalize parameters and extract scalar values
        $extracted = [];
        foreach ($rawParams as $key => $val) {
            $extractedKey = strtolower(str_replace(['-', ' '], '_', $key));
            $value = self::extractParamValue($val);
            if (!empty($value)) {
                $extracted[$extractedKey] = $value;
            }
        }

        return $extracted;
    }

    /**
     * Safely parse JSON strings or return arrays
     */
    private static function parseJsonData($data)
    {
        if (is_array($data)) {
            return $data;
        }
        if (is_object($data)) {
            return json_decode(json_encode($data), true);
        }
        if (is_string($data)) {
            $trimmed = trim($data);
            if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) || 
                (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }

    /**
     * Extract scalar string value from scalar or nested parameter object/array
     */
    private static function extractParamValue($val)
    {
        if (is_null($val)) {
            return '';
        }
        if (is_string($val) || is_numeric($val) || is_bool($val)) {
            return trim((string)$val);
        }
        if (is_array($val)) {
            if (isset($val['value']) && !is_array($val['value']) && !is_object($val['value'])) {
                return trim((string)$val['value']);
            }
            if (isset($val['val']) && !is_array($val['val']) && !is_object($val['val'])) {
                return trim((string)$val['val']);
            }
        }
        if (is_object($val)) {
            if (isset($val->value) && !is_array($val->value) && !is_object($val->value)) {
                return trim((string)$val->value);
            }
            if (isset($val->val) && !is_array($val->val) && !is_object($val->val)) {
                return trim((string)$val->val);
            }
        }
        return '';
    }

    /**
     * Process deposit request and redirect customer to TZPayWay Checkout
     *
     * @param object $deposit
     * @return string JSON response for Viserlab deposit handler
     */
    public static function process($deposit)
    {
        $params = self::getGatewayParams($deposit);

        // Retrieve API Key, Secret Key, and API Base URL
        $apiKey = $params['api_key'] ?? ($params['apikey'] ?? ($params['key'] ?? ($params['app_key'] ?? '')));
        $secretKey = $params['secret_key'] ?? ($params['secretkey'] ?? ($params['secret'] ?? ''));
        $apiUrl = rtrim($params['api_url'] ?? ($params['apiurl'] ?? ($params['url'] ?? ($params['base_url'] ?? 'https://tzpayway.com'))), '/');

        if (empty($apiKey)) {
            return json_encode([
                'error' => 'true',
                'message' => 'TZPayWay API Key is missing. Please configure it in your Admin Panel under Payment Gateways -> Automatic Gateways -> TZPAYWAY.'
            ]);
        }

        // Customer details
        $user = $deposit->user ?? null;
        $customerName = $user ? ($user->fullname ?? $user->name ?? $user->username ?? 'Customer') : 'Customer';
        $customerEmail = $user ? ($user->email ?? 'customer@example.com') : 'customer@example.com';
        $customerMobile = $user ? ($user->mobile ?? ($user->phone ?? '')) : '';

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
            'webhook_url' => route('ipn.' . ($deposit->gateway->alias ?? 'TZPAYWAY')),
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
                'message' => 'Connection error with TZPayWay: ' . $curlError
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
     * Verifies HMAC signature / Secret Key and payment status with fail-safe fallback
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ipn(Request $request)
    {
        $rawContent = $request->getContent();
        $payload = json_decode($rawContent, true);

        if (!$payload || !is_array($payload)) {
            $payload = $request->all();
        }

        // Get track number / TRX reference
        $track = $payload['user_data']['track'] 
            ?? ($payload['track'] 
            ?? ($payload['user_data']['trx'] 
            ?? ($payload['data']['user_data']['track'] 
            ?? ($payload['data']['track'] 
            ?? null))));

        $depositId = $payload['user_data']['deposit_id'] 
            ?? ($payload['deposit_id'] 
            ?? ($payload['data']['user_data']['deposit_id'] 
            ?? null));

        $trxId = $payload['trx_id'] 
            ?? ($payload['data']['trx_id'] 
            ?? ($payload['tz_trx_id'] 
            ?? null));

        $status = strtolower($payload['status'] ?? ($payload['data']['status'] ?? ''));

        if (!$track && !$trxId && !$depositId) {
            return response()->json(['error' => 'Invalid webhook data: missing transaction track or ID'], 400);
        }

        // Find deposit by transaction reference, deposit ID, or matching detail
        $deposit = null;
        if ($track) {
            $deposit = Deposit::where('trx', $track)->orderBy('id', 'DESC')->first();
        }
        if (!$deposit && $depositId) {
            $deposit = Deposit::where('id', $depositId)->first();
        }
        if (!$deposit && $trxId) {
            $deposit = Deposit::where('detail', 'like', '%' . $trxId . '%')->orderBy('id', 'DESC')->first();
        }

        if (!$deposit) {
            return response()->json(['error' => 'Deposit record not found for transaction: ' . ($track ?: ($trxId ?: $depositId))], 404);
        }

        // If already completed/processed, acknowledge immediately
        if ($deposit->status == 1) {
            return response()->json(['status' => 'success', 'message' => 'Transaction already completed'], 200);
        }

        // Retrieve gateway parameters
        $params = self::getGatewayParams($deposit);
        $apiKey = $params['api_key'] ?? ($params['apikey'] ?? ($params['key'] ?? ''));
        $secretKey = $params['secret_key'] ?? ($params['secretkey'] ?? ($params['secret'] ?? ''));
        $apiUrl = rtrim($params['api_url'] ?? ($params['apiurl'] ?? ($params['url'] ?? 'https://tzpayway.com')), '/');

        // Signature and Secret Key Verification
        $signature = $request->header('X-Webhook-Signature') 
            ?? ($request->header('X-TZPayway-Signature') 
            ?? ($request->server('HTTP_X_WEBHOOK_SIGNATURE') 
            ?? ($request->server('HTTP_X_TZPAYWAY_SIGNATURE') 
            ?? '')));

        $isVerified = false;

        if (!empty($secretKey)) {
            // 1. Verify HMAC Signature
            if (!empty($signature)) {
                $expectedSig1 = hash_hmac('sha256', $rawContent, $secretKey);
                $expectedSig2 = hash_hmac('sha256', json_encode($payload), $secretKey);
                if (hash_equals($expectedSig1, $signature) || hash_equals($expectedSig2, $signature)) {
                    $isVerified = true;
                }
            }

            // 2. Verify encrypted_data payload if provided
            if (!$isVerified && !empty($payload['encrypted_data'])) {
                $rawEnc = base64_decode($payload['encrypted_data']);
                if (strlen($rawEnc) > 16) {
                    $iv = substr($rawEnc, 0, 16);
                    $cipherText = substr($rawEnc, 16);
                    $key = hash('sha256', $secretKey, true);
                    $decrypted = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                    if ($decrypted) {
                        $decData = json_decode($decrypted, true);
                        if (is_array($decData) && !empty($decData['trx_id'])) {
                            $isVerified = true;
                        }
                    }
                }
            }
        }

        // 3. Fallback direct server-to-server API verification using X-API-KEY
        if (!$isVerified && !empty($apiKey) && !empty($trxId)) {
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
            $remoteStatus = strtolower($verifyData['data']['status'] ?? '');
            if (in_array($remoteStatus, ['completed', 'paid', 'success'])) {
                $isVerified = true;
                $status = $remoteStatus;
            }
        }

        // Check if status represents a paid/completed payment
        $isCompleted = in_array($status, ['completed', 'paid', 'success']);

        if (!$isVerified && !empty($secretKey)) {
            return response()->json([
                'error' => 'Webhook signature verification failed. Please ensure Secret Key is correctly configured.'
            ], 403);
        }

        if ($isCompleted) {
            $deposit->detail = json_encode([
                'tz_trx_id' => $trxId,
                'amount' => $payload['amount'] ?? ($deposit->amount ?? 0),
                'received_amount' => $payload['received_amount'] ?? ($payload['amount'] ?? 0),
                'due_amount' => $payload['due_amount'] ?? 0,
                'status' => $status,
                'currency' => $payload['currency'] ?? ($deposit->method_currency ?? 'BDT'),
                'method' => $payload['method'] ?? ($deposit->gateway->alias ?? 'TZPAYWAY'),
                'paid_at' => $payload['paid_at'] ?? now()->toIso8601String(),
                'user_data' => $payload['user_data'] ?? null,
                'verified_at' => now()->toDateTimeString(),
            ]);
            $deposit->save();

            // Ensure gateway relationship is loaded so Viserlab PaymentController::userDataUpdate doesn't throw 'property status on null'
            $gateway = null;
            if (class_exists('App\Models\Gateway')) {
                try {
                    $gateway = \App\Models\Gateway::where('code', $deposit->method_code ?? 0)
                        ->orWhere('alias', 'TZPAYWAY')
                        ->orWhere('alias', 'tzpayway')
                        ->first();
                } catch (\Throwable $e) {}
            }
            if ($gateway && method_exists($deposit, 'setRelation')) {
                $deposit->setRelation('gateway', $gateway);
            }

            if (class_exists('App\Models\GatewayCurrency')) {
                try {
                    $gc = \App\Models\GatewayCurrency::where('method_code', $deposit->method_code ?? 0)
                        ->orWhere('gateway_alias', 'TZPAYWAY')
                        ->orWhere('gateway_alias', 'tzpayway')
                        ->first();
                    if ($gc && method_exists($deposit, 'setRelation')) {
                        $deposit->setRelation('gatewayCurrency', $gc);
                        $deposit->setRelation('gateway_currency', $gc);
                    }
                } catch (\Throwable $e) {}
            }

            // Update user balance and complete deposit in Viserlab
            if ($deposit->status == 0) {
                try {
                    PaymentController::userDataUpdate($deposit);
                } catch (\Throwable $e) {
                    $deposit->status = 1;
                    $deposit->save();

                    $user = $deposit->user ?? (class_exists('App\Models\User') ? \App\Models\User::find($deposit->user_id) : null);
                    if ($user) {
                        $user->balance += ($deposit->amount ?? 0);
                        $user->save();
                    }
                }
            }

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
