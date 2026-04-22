<?php declare(strict_types=1);

namespace LodinPayment\Core\Checkout\Payment;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LodinPaymentHandler implements AsyncPaymentHandlerInterface
{
    private const API_URL = 'https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp';

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        Context $context
    ): RedirectResponse {
        // ✅ Direct methods – no reflection
        $order = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();
        $amount = $orderTransaction->getAmount()->getTotalPrice();

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        $invoiceId = 'ORDER-' . $order->getOrderNumber() . '-' . time();

        $clientId = $_ENV['LODIN_CLIENT_ID'] ?? 'TEST_CLIENT_ID';
        $clientSecret = $_ENV['LODIN_CLIENT_SECRET'] ?? 'TEST_SECRET';
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $payload = $clientId . $timestamp . $amount . $invoiceId;
        $signature = $this->generateSignature($payload, $clientSecret);

        $returnUrl = $transaction->getReturnUrl(); // Provided by Shopware

        $body = [
            'amount' => round((float)$amount, 2),
            'invoiceId' => $invoiceId,
            'paymentType' => 'INST',
            'cardId' => $invoiceId,
            'description' => 'Shopware Order #' . $order->getOrderNumber(),
            'returnUrl' => $returnUrl,
        ];

        $headers = [
            'Content-Type: application/json',
            'X-Client-Id: ' . $clientId,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
            'X-Extension-Code: SHOPWARE',
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new \RuntimeException('Curl error: ' . curl_error($ch));
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid API response: ' . $response);
        }

        $paymentUrl = $data['url'] ?? $data['paymentUrl'] ?? $data['data']['url'] ?? $data['data']['paymentUrl'] ?? null;
        if (!$paymentUrl) {
            throw new \RuntimeException('No payment URL in response: ' . $response);
        }

        return new RedirectResponse($paymentUrl);
    }

    private function generateSignature(string $payload, string $secret): string
    {
        $raw = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}