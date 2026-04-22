<?php declare(strict_types=1);

namespace LodinPayment\Core\Checkout\Payment;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LodinPaymentHandler extends AbstractPaymentHandler
{
    private const API_URL = 'https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp';

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {

        $order = $transaction->getOrder();

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        $amount = $transaction->getOrderTransaction()->getAmount()->getTotalPrice();

        $invoiceId = 'ORDER-' . $order->getOrderNumber() . '-' . time();

        $clientId = $_ENV['LODIN_CLIENT_ID'] ?? 'TEST_CLIENT_ID';
        $clientSecret = $_ENV['LODIN_CLIENT_SECRET'] ?? 'TEST_SECRET';

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $payload = $clientId . $timestamp . $amount . $invoiceId;
        $signature = $this->generateSignature($payload, $clientSecret);

        // IMPORTANT: MUST go to your return controller
        $returnUrl = $_ENV['APP_URL'] . '/lodin/return?orderId=' . $order->getId();

        $body = [
            'amount' => round((float) $amount, 2),
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

        // 🔴 DEBUG SAFE CHECK (prevents Shopware crash)
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid API response: ' . $response);
        }

        // 🔴 IMPORTANT: try multiple possible keys (THIS FIXES YOUR ERROR)
        $paymentUrl =
            $data['url']
            ?? $data['paymentUrl']
            ?? $data['data']['url']
            ?? $data['data']['paymentUrl']
            ?? null;

        if (!$paymentUrl) {
            throw new \RuntimeException('No payment URL in response: ' . $response);
        }

        return new RedirectResponse($paymentUrl);
    }

    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return true;
    }

    private function generateSignature(string $payload, string $secret): string
    {
        $raw = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
