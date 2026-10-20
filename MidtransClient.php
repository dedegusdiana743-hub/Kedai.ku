<?php

declare(strict_types=1);

namespace PesanMenu\Services;

use JsonException;
use PesanMenu\Contracts\PaymentGatewayInterface;
use PesanMenu\Exceptions\PaymentException;

final class MidtransClient implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $serverKey,
        private readonly bool $production = false,
        private readonly string $acquirer = 'gopay',
        private readonly int $timeout = 20
    ) {
        if ($this->serverKey === '') {
            throw new PaymentException('MIDTRANS_SERVER_KEY belum dikonfigurasi.', 500, 'CONFIG');
        }
    }

    public function createCharge(array $order, array $items, array $customer): array
    {
        $providerOrderId = (string) ($order['provider_order_id'] ?: $order['order_number']);
        $itemDetails = [];
        foreach ($items as $item) {
            $itemDetails[] = [
                'id' => 'menu-' . (int) $item['menu_item_id'],
                'price' => (int) $item['unit_price'],
                'quantity' => (int) $item['quantity'],
                'name' => mb_substr((string) $item['name_snapshot'], 0, 50),
            ];
        }

        $grossAmount = (int) $order['total'];
        $itemTotal = 0;
        foreach ($itemDetails as $item) {
            $itemTotal += (int) $item['price'] * (int) $item['quantity'];
        }
        $remaining = $grossAmount - $itemTotal;
        $shippingFee = min($remaining, max(0, (int) ($order['shipping_fee'] ?? 0)));
        if ($shippingFee > 0) {
            $itemDetails[] = ['id' => 'shipping', 'price' => $shippingFee, 'quantity' => 1, 'name' => 'Ongkos antar'];
            $remaining -= $shippingFee;
        }
        $taxAmount = min($remaining, max(0, (int) ($order['tax_amount'] ?? 0)));
        if ($taxAmount > 0) {
            $itemDetails[] = ['id' => 'tax', 'price' => $taxAmount, 'quantity' => 1, 'name' => 'Pajak'];
            $remaining -= $taxAmount;
        }
        if ($remaining > 0) {
            $itemDetails[] = ['id' => 'other', 'price' => $remaining, 'quantity' => 1, 'name' => 'Biaya pesanan'];
        }

        $payload = [
            'payment_type' => 'qris',
            'transaction_details' => [
                'order_id' => $providerOrderId,
                'gross_amount' => $grossAmount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => mb_substr((string) $customer['name'], 0, 40),
                'phone' => mb_substr((string) $customer['phone'], 0, 30),
            ],
            'qris' => [
                'acquirer' => $this->normalizedAcquirer(),
            ],
        ];

        $response = $this->request('POST', '/v2/charge', $payload);
        $responseOrderId = (string) ($response['order_id'] ?? '');
        $responseAmount = (float) ($response['gross_amount'] ?? 0);
        if ($responseOrderId !== $providerOrderId || $responseAmount !== (float) $order['total']) {
            throw new PaymentException('Respons payment provider tidak sesuai dengan pesanan.', 502, 'RESPONSE_MISMATCH');
        }
        if (isset($response['currency']) && strtoupper((string) $response['currency']) !== 'IDR') {
            throw new PaymentException('Mata uang pembayaran tidak sesuai.', 502, 'CURRENCY_MISMATCH');
        }
        $qrUrl = '';
        foreach (($response['actions'] ?? []) as $action) {
            $name = (string) ($action['name'] ?? '');
            if ($name === 'generate-qr-code-v2') {
                $qrUrl = (string) ($action['url'] ?? '');
                break;
            }
            if ($name === 'generate-qr-code' && $qrUrl === '') {
                $qrUrl = (string) ($action['url'] ?? '');
            }
        }
        $transactionId = (string) ($response['transaction_id'] ?? '');
        if ($transactionId === '' || $qrUrl === '') {
            throw new PaymentException('Respons QRIS dari provider tidak lengkap.', 502, $transactionId === '' ? 'INVALID_RESPONSE' : 'MISSING_QR');
        }

        return [
            'transaction_id' => $transactionId,
            'qr_url' => $qrUrl,
            'transaction_status' => (string) ($response['transaction_status'] ?? 'pending'),
        ];
    }

    public function status(string $orderId): array
    {
        return $this->request('GET', '/v2/' . rawurlencode($orderId) . '/status');
    }

    public function refund(string $orderId, int $amount, string $reason): array
    {
        if ($amount < 1) {
            throw new PaymentException('Nominal refund tidak valid.', 422, 'INVALID_AMOUNT');
        }

        return $this->request('POST', '/v2/' . rawurlencode($orderId) . '/refund', [
            'amount' => $amount,
            'reason' => mb_substr($reason, 0, 255),
        ]);
    }

    public function verifyNotification(array $payload): bool
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');
        $signature = strtolower((string) ($payload['signature_key'] ?? ''));
        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signature === '') {
            return false;
        }

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);
        return hash_equals($expected, $signature);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = ($this->production ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com') . $path;
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->serverKey . ':'),
        ];

        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $headers, $json);
        }
        return $this->streamRequest($method, $url, $headers, $json);
    }

    private function curlRequest(string $method, string $url, array $headers, ?string $json): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new PaymentException('Payment provider tidak dapat dihubungi.', 502, 'CURL_INIT');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($json !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $json);
        }
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new PaymentException('Payment provider tidak dapat dihubungi.', 502, 'NETWORK');
        }

        return $this->decodeResponse($body, $status, $error);
    }

    private function streamRequest(string $method, string $url, array $headers, ?string $json): array
    {
        if (!function_exists('file_get_contents')) {
            throw new PaymentException('Ekstensi HTTP untuk payment provider tidak tersedia.', 500, 'NO_HTTP_CLIENT');
        }
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        if ($json !== null) {
            $options['http']['content'] = $json;
        }
        $body = @file_get_contents($url, false, stream_context_create($options));
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($body === false) {
            throw new PaymentException('Payment provider tidak dapat dihubungi.', 502, 'NETWORK');
        }

        return $this->decodeResponse($body, $status, '');
    }

    private function decodeResponse(string $body, int $status, string $networkError): array
    {
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('Respons payment provider tidak valid.', 502, 'INVALID_JSON');
        }
        if (!is_array($payload)) {
            throw new PaymentException('Respons payment provider tidak valid.', 502, 'INVALID_JSON');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($payload['status_message'] ?? $payload['responseMessage'] ?? $payload['error_messages'][0] ?? $networkError);
            $code = (string) ($payload['status_code'] ?? $payload['responseCode'] ?? (string) $status);
            throw new PaymentException($message !== '' ? $message : 'Payment provider menolak permintaan.', $status, $code);
        }

        return $payload;
    }

    private function normalizedAcquirer(): string
    {
        return match ($this->acquirer) {
            'shopeepay', 'airpay shopee', 'airpay_shopeepay' => 'airpay shopee',
            default => 'gopay',
        };
    }
}