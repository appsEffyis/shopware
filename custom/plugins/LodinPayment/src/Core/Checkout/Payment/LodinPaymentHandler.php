<?php

namespace LodinPayment\Core\Checkout\Payment;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class LodinPaymentHandler extends AbstractPaymentHandler
{
    private const API_URL = 'https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp';
    private const SHOP_BASE_URL = 'http://194.163.169.2:4488';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct = null
    ): ?RedirectResponse {
        try {
            file_put_contents('/var/www/html/debug.txt', "STEP 1: PAY CALLED\n", FILE_APPEND);

            $salesChannelId = null;
            $source = $context->getSource();

            if (method_exists($source, 'getSalesChannelId')) {
                $salesChannelId = $source->getSalesChannelId();
            }

            $clientId = (string) (
                $this->systemConfigService->get('LodinPayment.config.clientId', $salesChannelId)
                ?: getenv('LODIN_CLIENT_ID')
                ?: 'TEST_CLIENT_ID'
            );

            $clientSecret = (string) (
                $this->systemConfigService->get('LodinPayment.config.clientSecret', $salesChannelId)
                ?: getenv('LODIN_CLIENT_SECRET')
                ?: 'TEST_SECRET'
            );

            file_put_contents('/var/www/html/debug.txt', "STEP 2: CONFIG OK | CLIENT_ID={$clientId}\n", FILE_APPEND);

            $orderTransactionId = $transaction->getOrderTransactionId();

            $criteria = new Criteria([$orderTransactionId]);
            $criteria->addAssociation('order');

            $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

            if (!$orderTransaction instanceof OrderTransactionEntity) {
                throw new \RuntimeException('Order transaction not found');
            }

            $order = $orderTransaction->getOrder();
            
            if ($order === null) {
                throw new \RuntimeException('Order not found');
            }

            $amount = number_format((float) $orderTransaction->getAmount()->getTotalPrice(), 2, '.', '');

            $invoiceId = sprintf(
                'ORDER-%s-%s',
                $order->getOrderNumber(),
                time()
            );

            $shopBaseUrl = rtrim((string) (
            $this->systemConfigService->get('LodinPayment.config.shopBaseUrl', $salesChannelId)
            ?: self::SHOP_BASE_URL
        ), '/');

        $returnUrl = $shopBaseUrl . '/lodin/return'
            . '?orderId=' . urlencode($order->getId())
            . '&transactionId=' . urlencode($orderTransactionId);

            $customFields = $orderTransaction->getCustomFields() ?? [];
            $customFields['lodinInvoiceId'] = $invoiceId;

            $this->orderTransactionRepository->update([
                [
                    'id' => $orderTransactionId,
                    'customFields' => $customFields,
                ],
            ], $context);

            $timestamp = gmdate('Y-m-d\TH:i:s\Z');

            $payload = $clientId . $timestamp . $amount . $invoiceId;
            $signature = $this->generateSignature($payload, $clientSecret);

            $body = [
                'amount' => (float) $amount,
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

            file_put_contents('/var/www/html/debug.txt', "STEP 3: REQUEST BODY = " . json_encode($body) . "\n", FILE_APPEND);

            $ch = curl_init(self::API_URL);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 20,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                curl_close($ch);
                throw new \RuntimeException('Curl error: ' . $curlError);
            }

            curl_close($ch);

            file_put_contents('/var/www/html/debug.txt', "STEP 4: HTTP CODE = {$httpCode}\n", FILE_APPEND);
            file_put_contents('/var/www/html/debug.txt', "STEP 5: RESPONSE = {$response}\n", FILE_APPEND);

            $data = json_decode($response, true);

            if (!is_array($data)) {
                throw new \RuntimeException('Invalid API response: ' . $response);
            }

            $paymentUrl =
                $data['url']
                ?? $data['paymentUrl']
                ?? $data['data']['url']
                ?? $data['data']['paymentUrl']
                ?? null;

            if (!$paymentUrl) {
                throw new \RuntimeException('No payment URL in response: ' . $response);
            }

            $updatedCustomFields = $orderTransaction->getCustomFields() ?? [];
            $updatedCustomFields['lodinInvoiceId'] = $invoiceId;
            $updatedCustomFields['lodinTransactionId'] = $data['transactionId'] ?? null;
            $updatedCustomFields['lodinCardId'] = $data['cardId'] ?? null;

            $this->orderTransactionRepository->update([
                [
                    'id' => $orderTransactionId,
                    'customFields' => $updatedCustomFields,
                ],
            ], $context);

            return new RedirectResponse($paymentUrl);
        } catch (\Throwable $e) {
            file_put_contents('/var/www/html/debug.txt', "FATAL: " . $e::class . ' | ' . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }

    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $type === PaymentHandlerType::REDIRECT;
    }

    private function generateSignature(string $payload, string $secret): string
    {
        $raw = hash_hmac('sha256', $payload, $secret, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}