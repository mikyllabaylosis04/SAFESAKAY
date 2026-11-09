<?php

namespace App\Services;

use App\Support\XenditMoney;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XenditService
{
    private const API_VERSION = '2024-11-11';

    private function secretKey(): string
    {
        if (! config('services.xendit.enabled')) {
            throw new RuntimeException('Xendit is currently disabled.');
        }

        $key = trim((string) config('services.xendit.secret_key'));

        if ($key === '') {
            throw new RuntimeException('Xendit secret key is not configured.');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        $url = trim((string) config('services.xendit.base_url', 'https://api.xendit.co'));

        if ($url === '') {
            throw new RuntimeException('Xendit API base URL is not configured.');
        }

        return rtrim($url, '/');
    }

    /**
     * Create a GCash payment request (Payments API v3).
     *
     * @return array<string, mixed>
     */
    public function createGcashPaymentRequest(
        string $referenceId,
        int $amountMinorUnits,
        string $description,
        string $successUrl,
        string $cancelUrl,
    ): array {
        if ($amountMinorUnits <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        $referenceId = trim($referenceId);
        $description = trim($description);

        if ($referenceId === '' || $description === '') {
            throw new RuntimeException('Payment reference and description are required.');
        }

        if (! $this->isHttpsUrl($successUrl) || ! $this->isHttpsUrl($cancelUrl)) {
            throw new XenditGatewayException(
                400,
                'API_VALIDATION_ERROR',
                'Xendit return URLs must be HTTPS.',
                'GCash needs public HTTPS return addresses. Set XENDIT_SUCCESS_REDIRECT_URL and XENDIT_FAILURE_REDIRECT_URL, then try again.',
            );
        }

        $apiAmount = XenditMoney::toApiAmountFromMinor($amountMinorUnits);

        $payload = [
            'reference_id' => $referenceId,
            'type' => 'PAY',
            'country' => 'PH',
            'currency' => 'PHP',
            'request_amount' => $apiAmount,
            'capture_method' => 'AUTOMATIC',
            'channel_code' => 'GCASH',
            'channel_properties' => [
                'success_return_url' => $successUrl,
                'failure_return_url' => $cancelUrl,
            ],
            'description' => $description,
            'metadata' => [
                'booking_reference' => $referenceId,
            ],
        ];

        $response = $this->send(
            'create_gcash_payment_request',
            $referenceId,
            fn () => Http::withBasicAuth($this->secretKey(), '')
                ->withHeaders(['api-version' => self::API_VERSION])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($this->baseUrl().'/v3/payment_requests', $payload),
        );

        $body = $this->decodeBody($response, 'create_gcash_payment_request', $referenceId);
        $paymentRequestId = $body['payment_request_id'] ?? $body['payment_session_id'] ?? null;
        $redirectUrl = $this->redirectCustomerUrl($body);

        if (! is_string($paymentRequestId) || trim($paymentRequestId) === '' || $redirectUrl === null) {
            $this->logFailure('create_gcash_payment_request', $response->status(), null, 'Missing payment request id or redirect URL.', $referenceId);

            throw new XenditGatewayException(
                $response->status(),
                null,
                'Xendit did not return a GCash redirect.',
                'GCash did not return a payment link. Try again in a moment.',
            );
        }

        Log::info('xendit.payment_request.created', [
            'operation' => 'create_gcash_payment_request',
            'booking_reference' => $referenceId,
            'http_status' => $response->status(),
            'payment_request_id' => $paymentRequestId,
            'request_amount' => $apiAmount,
            'currency' => $body['currency'] ?? 'PHP',
            'channel_code' => $body['channel_code'] ?? 'GCASH',
            'status' => $body['status'] ?? null,
        ]);

        $body['checkout_url'] = $redirectUrl;

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentRequest(string $paymentRequestId): array
    {
        $paymentRequestId = trim($paymentRequestId);

        if ($paymentRequestId === '') {
            throw new RuntimeException('Xendit payment request id is required.');
        }

        $path = str_starts_with($paymentRequestId, 'ps-')
            ? '/sessions/'.rawurlencode($paymentRequestId)
            : '/v3/payment_requests/'.rawurlencode($paymentRequestId);

        $response = $this->send(
            'get_payment_request',
            $paymentRequestId,
            fn () => Http::withBasicAuth($this->secretKey(), '')
                ->withHeaders(['api-version' => self::API_VERSION])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->get($this->baseUrl().$path),
        );

        return $this->decodeBody($response, 'get_payment_request', $paymentRequestId);
    }

    /**
     * Refund a completed GCash payment back to the passenger's original GCash account.
     *
     * @return array<string, mixed>
     */
    public function createRefund(
        string $paymentRequestId,
        string $referenceId,
        int $amountMinorUnits,
        string $reason = 'CANCELLATION',
    ): array {
        if ($amountMinorUnits <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }

        $payload = [
            'payment_request_id' => $paymentRequestId,
            'reference_id' => $referenceId,
            'amount' => XenditMoney::toApiAmountFromMinor($amountMinorUnits),
            'currency' => 'PHP',
            'reason' => $reason,
        ];

        $response = $this->send(
            'create_refund',
            $referenceId,
            fn () => Http::withBasicAuth($this->secretKey(), '')
                ->withHeaders(['Idempotency-key' => $referenceId])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($this->baseUrl().'/refunds', $payload),
        );

        $body = $this->decodeBody($response, 'create_refund', $referenceId);

        Log::info('xendit.refund.created', [
            'operation' => 'create_refund',
            'booking_reference' => $referenceId,
            'http_status' => $response->status(),
            'refund_id' => $body['id'] ?? null,
            'amount' => $payload['amount'],
            'status' => $body['status'] ?? null,
        ]);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRefund(string $refundId): array
    {
        $refundId = trim($refundId);

        $response = $this->send(
            'get_refund',
            $refundId,
            fn () => Http::withBasicAuth($this->secretKey(), '')
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->get($this->baseUrl().'/refunds/'.rawurlencode($refundId)),
        );

        return $this->decodeBody($response, 'get_refund', $refundId);
    }

    /**
     * Send the operator's net share to a registered GCash number.
     *
     * @return array<string, mixed>
     */
    public function createGcashPayout(
        string $referenceId,
        float $amountPesos,
        string $accountNumber,
        string $accountHolderName,
        string $description,
    ): array {
        if ($amountPesos <= 0) {
            throw new RuntimeException('Payout amount must be greater than zero.');
        }

        $accountNumber = $this->normalizePhilippineMobile($accountNumber);
        $accountHolderName = trim($accountHolderName);

        if ($accountNumber === null || $accountHolderName === '') {
            throw new XenditGatewayException(
                422,
                'INVALID_ACCOUNT',
                'Operator GCash account is incomplete.',
                'The operator does not have a valid approved GCash number.',
            );
        }

        $payload = [
            'reference_id' => $referenceId,
            'channel_code' => 'PH_GCASH',
            'channel_properties' => [
                'account_number' => $accountNumber,
                'account_holder_name' => $accountHolderName,
            ],
            'amount' => round($amountPesos, 2),
            'currency' => 'PHP',
            'description' => mb_substr(trim($description), 0, 100),
        ];

        $response = $this->send(
            'create_gcash_payout',
            $referenceId,
            fn () => Http::withBasicAuth($this->secretKey(), '')
                ->withHeaders(['Idempotency-key' => $referenceId])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($this->baseUrl().'/v2/payouts', $payload),
        );

        $body = $this->decodeBody($response, 'create_gcash_payout', $referenceId);
        $payoutId = $body['id'] ?? null;

        if (! is_string($payoutId) || trim($payoutId) === '') {
            throw new XenditGatewayException(
                $response->status(),
                null,
                'Xendit did not return a payout id.',
                'The operator payout could not be started.',
            );
        }

        Log::info('xendit.payout.created', [
            'operation' => 'create_gcash_payout',
            'booking_reference' => $referenceId,
            'http_status' => $response->status(),
            'payout_id' => $payoutId,
            'amount' => $payload['amount'],
            'status' => $body['status'] ?? null,
            'channel_code' => 'PH_GCASH',
        ]);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayout(string $payoutId): array
    {
        $payoutId = trim($payoutId);

        $response = $this->send(
            'get_payout',
            $payoutId,
            fn () => Http::withBasicAuth($this->secretKey(), '')
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(20)
                ->get($this->baseUrl().'/v2/payouts/'.rawurlencode($payoutId)),
        );

        return $this->decodeBody($response, 'get_payout', $payoutId);
    }

    private function normalizePhilippineMobile(string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function redirectCustomerUrl(array $body): ?string
    {
        $actions = $body['actions'] ?? null;

        if (is_array($actions)) {
            $fallback = null;

            foreach ($actions as $action) {
                if (! is_array($action) || ($action['type'] ?? null) !== 'REDIRECT_CUSTOMER') {
                    continue;
                }

                $value = $action['value'] ?? null;

                if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
                    continue;
                }

                if (($action['descriptor'] ?? null) === 'WEB_URL') {
                    return $value;
                }

                $fallback ??= $value;
            }

            if ($fallback !== null) {
                return $fallback;
            }
        }

        $legacy = $body['payment_link_url'] ?? null;

        return is_string($legacy) && filter_var($legacy, FILTER_VALIDATE_URL) ? $legacy : null;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(string $operation, string $referenceId, callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            $this->logFailure($operation, 0, null, $exception->getMessage(), $referenceId);

            throw new XenditGatewayException(
                0,
                null,
                'Cannot reach Xendit.',
                'Cannot reach the payment gateway. Check the internet connection and try again.',
            );
        }

        if ($response->failed()) {
            $json = $response->json();
            $errorCode = is_array($json) ? $this->stringOrNull($json['error_code'] ?? null) : null;
            $message = is_array($json) ? $this->stringOrNull($json['message'] ?? null) : null;
            $message ??= 'Xendit rejected the payment request.';
            $this->logFailure($operation, $response->status(), $errorCode, $message, $referenceId);

            throw new XenditGatewayException(
                $response->status(),
                $errorCode,
                $this->sanitize($message),
                $this->passengerMessage($response->status(), $errorCode, $message),
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Response $response, string $operation, string $referenceId): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            $this->logFailure($operation, $response->status(), null, 'Invalid JSON response.', $referenceId);

            throw new XenditGatewayException(
                $response->status(),
                null,
                'Invalid Xendit response.',
                'GCash checkout could not be started. Try again in a moment.',
            );
        }

        return $body;
    }

    private function passengerMessage(int $status, ?string $errorCode, string $message): string
    {
        $code = strtoupper((string) $errorCode);
        $lower = strtolower($message);

        if ($status === 401 || $code === 'INVALID_API_KEY') {
            return 'Online payment is not configured. Ask the administrator to check the Xendit test key.';
        }

        if (str_contains($lower, 'https') || str_contains($lower, 'return url')) {
            return 'GCash needs public HTTPS return addresses. Update the payment return URLs, then try again.';
        }

        if ($status === 409) {
            return 'A payment for this rental is already in progress. Wait a moment, then try again.';
        }

        if ($status === 503 || in_array($code, ['CHANNEL_UNAVAILABLE', 'ISSUER_UNAVAILABLE'], true)) {
            return 'GCash is temporarily unavailable. Try again in a few minutes.';
        }

        if ($code === 'INVALID_PAYMENT_REQUEST' || $status === 404) {
            return 'This payment session is no longer valid. Start GCash checkout again.';
        }

        return 'GCash checkout could not be started. Try again in a moment.';
    }

    private function logFailure(string $operation, int $status, ?string $errorCode, string $message, string $referenceId): void
    {
        Log::warning('xendit.payment_request.failed', [
            'operation' => $operation,
            'http_status' => $status,
            'error_code' => $errorCode,
            'message' => $this->sanitize($message),
            'booking_reference' => $referenceId,
        ]);
    }

    private function sanitize(string $message): string
    {
        $clean = preg_replace('/xnd_[A-Za-z0-9_]+/', '[redacted]', $message) ?? $message;
        $clean = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $clean) ?? $clean;

        return mb_substr($clean, 0, 500);
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
